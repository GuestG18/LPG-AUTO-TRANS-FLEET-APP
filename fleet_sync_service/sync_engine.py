from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Any

from .config import AppConfig
from .database import DatabaseClient, quote_identifier
from .state_store import SyncStateStore
from .tunnel import SshTunnel
from .validation import TableSchema, validate_table


@dataclass
class TableSyncStats:
    table_name: str
    scanned_rows: int = 0
    inserted_rows: int = 0
    updated_rows: int = 0
    skipped_rows: int = 0
    duration_ms: int = 0
    status: str = "pending"
    reason: str = ""


class SyncEngine:
    def __init__(self, config: AppConfig, logger: logging.Logger, dry_run: bool = False) -> None:
        self.config = config
        self.logger = logger
        self.dry_run = dry_run
        self.state = SyncStateStore(config.sync.state_file)

    def test_connections(self) -> None:
        with SshTunnel(self.config.ssh_tunnel, self.config.vps_database, self.logger) as tunnel:
            source_settings = tunnel.database_settings()
            with DatabaseClient(source_settings, tunnel.database_role(), read_only=True, logger=self.logger) as source:
                source_info = source.test_connection()
                self.logger.info(
                    "VPS MySQL connection OK: database=%s version=%s",
                    source_info.get("database_name"),
                    source_info.get("server_version"),
                )

        with DatabaseClient(self.config.local_database, "localhost/target", read_only=False, logger=self.logger) as target:
            target_info = target.test_connection()
            self.logger.info("Localhost connection OK: database=%s version=%s", target_info.get("database_name"), target_info.get("server_version"))

    def run(self) -> list[TableSyncStats]:
        started_at = time.monotonic()
        mode = "DRY RUN" if self.dry_run else "APPLY"
        self.logger.info("Synchronization start mode=%s tables=%s", mode, ", ".join(self.config.sync.tables))

        results: list[TableSyncStats] = []
        with SshTunnel(self.config.ssh_tunnel, self.config.vps_database, self.logger) as tunnel:
            source_settings = tunnel.database_settings()
            with DatabaseClient(source_settings, tunnel.database_role(), read_only=True, logger=self.logger) as source, DatabaseClient(
                self.config.local_database,
                "localhost/target",
                read_only=self.dry_run,
                logger=self.logger,
            ) as target:
                for table_name in self.config.sync.tables:
                    try:
                        result = self._sync_table(source, target, table_name)
                    except Exception as exc:
                        self.logger.exception("Table %s failed: %s", table_name, exc)
                        result = TableSyncStats(table_name=table_name, status="failed", reason=str(exc))
                    results.append(result)

        duration_ms = int((time.monotonic() - started_at) * 1000)
        inserted = sum(item.inserted_rows for item in results)
        updated = sum(item.updated_rows for item in results)
        failed = sum(1 for item in results if item.status == "failed")
        skipped = sum(1 for item in results if item.status == "skipped")
        self.logger.info(
            "Synchronization finish duration_ms=%s inserted=%s updated=%s failed_tables=%s skipped_tables=%s",
            duration_ms,
            inserted,
            updated,
            failed,
            skipped,
        )
        return results

    def _sync_table(self, source: DatabaseClient, target: DatabaseClient, table_name: str) -> TableSyncStats:
        stats = TableSyncStats(table_name=table_name)
        table_started = time.monotonic()
        self.logger.info("Table %s synchronization start", table_name)

        validation = validate_table(source, target, table_name, self.logger)
        if not validation.valid or validation.schema is None:
            stats.status = "skipped"
            stats.reason = validation.reason
            self.logger.warning("Table %s skipped: %s", table_name, validation.reason)
            return stats

        schema = validation.schema
        last_sync = self.state.get_last_sync(table_name, self.config.sync.initial_timestamp)
        watermark = self._get_table_watermark(source, schema, last_sync)
        if watermark is None:
            stats.duration_ms = int((time.monotonic() - table_started) * 1000)
            stats.status = "success"
            self.logger.info(
                "Table %s synchronization finish status=%s scanned=0 inserted=0 updated=0 skipped_rows=0 duration_ms=%s reason=no rows newer than %s",
                table_name,
                stats.status,
                stats.duration_ms,
                last_sync,
            )
            return stats

        self.logger.info(
            "Table %s window: %s > %s and %s <= %s",
            table_name,
            schema.timestamp_column,
            last_sync,
            schema.timestamp_column,
            watermark,
        )

        cursor_timestamp: Any = last_sync
        cursor_pk: tuple[Any, ...] | None = None
        while True:
            source_rows = self._fetch_source_batch(source, schema, cursor_timestamp, watermark, cursor_pk)
            if not source_rows:
                break
            stats.scanned_rows += len(source_rows)
            self._sync_batch(target, schema, source_rows, stats)
            last_row = source_rows[-1]
            cursor_timestamp = last_row[schema.timestamp_column]
            cursor_pk = self._row_key(schema.primary_key_columns, last_row)
            if len(source_rows) < self.config.sync.batch_size:
                break

        stats.duration_ms = int((time.monotonic() - table_started) * 1000)
        stats.status = "success"

        if not self.dry_run:
            self.state.record_success(
                table_name=table_name,
                last_successful_sync=str(watermark),
                timestamp_column=schema.timestamp_column,
                inserted_rows=stats.inserted_rows,
                updated_rows=stats.updated_rows,
                scanned_rows=stats.scanned_rows,
                duration_ms=stats.duration_ms,
                dry_run=False,
            )
        else:
            self.logger.info("Dry-run enabled; state file not updated for table %s", table_name)

        self.logger.info(
            "Table %s synchronization finish status=%s scanned=%s inserted=%s updated=%s skipped_rows=%s duration_ms=%s",
            table_name,
            stats.status,
            stats.scanned_rows,
            stats.inserted_rows,
            stats.updated_rows,
            stats.skipped_rows,
            stats.duration_ms,
        )
        return stats

    def _get_table_watermark(self, source: DatabaseClient, schema: TableSchema, last_sync: str) -> str | None:
        sql = (
            f"SELECT MAX({quote_identifier(schema.timestamp_column)}) AS watermark "
            f"FROM {quote_identifier(schema.name)} "
            f"WHERE {quote_identifier(schema.timestamp_column)} > %s"
        )
        row = source.fetch_one(sql, (last_sync,))
        if not row or row.get("watermark") is None:
            return None
        return str(row["watermark"])

    def _fetch_source_batch(
        self,
        source: DatabaseClient,
        schema: TableSchema,
        cursor_timestamp: Any,
        watermark: str,
        cursor_pk: tuple[Any, ...] | None,
    ) -> list[dict[str, Any]]:
        columns_sql = ", ".join(quote_identifier(column) for column in schema.common_columns)
        order_columns = [schema.timestamp_column, *schema.primary_key_columns]
        order_sql = ", ".join(quote_identifier(column) for column in order_columns)
        timestamp_sql = quote_identifier(schema.timestamp_column)
        cursor_sql, cursor_params = self._cursor_condition(schema, timestamp_sql, cursor_timestamp, cursor_pk)
        sql = (
            f"SELECT {columns_sql} "
            f"FROM {quote_identifier(schema.name)} "
            f"WHERE {cursor_sql} "
            f"AND {timestamp_sql} <= %s "
            f"ORDER BY {order_sql} "
            f"LIMIT %s"
        )
        return source.fetch_all(sql, (*cursor_params, watermark, self.config.sync.batch_size))

    def _cursor_condition(
        self,
        schema: TableSchema,
        timestamp_sql: str,
        cursor_timestamp: Any,
        cursor_pk: tuple[Any, ...] | None,
    ) -> tuple[str, list[Any]]:
        if cursor_pk is None:
            return f"{timestamp_sql} > %s", [cursor_timestamp]

        if len(schema.primary_key_columns) == 1:
            pk_sql = quote_identifier(schema.primary_key_columns[0])
            return (
                f"({timestamp_sql} > %s OR ({timestamp_sql} = %s AND {pk_sql} > %s))",
                [cursor_timestamp, cursor_timestamp, cursor_pk[0]],
            )

        pk_columns_sql = ", ".join(quote_identifier(column) for column in schema.primary_key_columns)
        pk_placeholders = ", ".join(["%s"] * len(schema.primary_key_columns))
        return (
            f"({timestamp_sql} > %s OR ({timestamp_sql} = %s AND ({pk_columns_sql}) > ({pk_placeholders})))",
            [cursor_timestamp, cursor_timestamp, *cursor_pk],
        )

    def _sync_batch(
        self,
        target: DatabaseClient,
        schema: TableSchema,
        source_rows: list[dict[str, Any]],
        stats: TableSyncStats,
    ) -> None:
        local_rows = self._load_local_rows(target, schema, source_rows)
        try:
            for source_row in source_rows:
                key = self._row_key(schema.primary_key_columns, source_row)
                if any(value is None for value in key):
                    stats.skipped_rows += 1
                    self.logger.warning("Table %s row skipped because primary key contains NULL: %s", schema.name, key)
                    continue

                local_row = local_rows.get(key)
                if local_row is None:
                    stats.inserted_rows += 1
                    if not self.dry_run:
                        self._insert_row(target, schema, source_row)
                    continue

                changed_columns = [
                    column for column in schema.update_columns if source_row.get(column) != local_row.get(column)
                ]
                if changed_columns:
                    stats.updated_rows += 1
                    if not self.dry_run:
                        self._update_row(target, schema, source_row, changed_columns)

            if not self.dry_run:
                target.commit()
        except Exception:
            if not self.dry_run:
                target.rollback()
            raise

    def _load_local_rows(
        self,
        target: DatabaseClient,
        schema: TableSchema,
        source_rows: list[dict[str, Any]],
    ) -> dict[tuple[Any, ...], dict[str, Any]]:
        if not source_rows:
            return {}

        keys = [self._row_key(schema.primary_key_columns, row) for row in source_rows]
        keys = [key for key in keys if not any(value is None for value in key)]
        if not keys:
            return {}

        columns_sql = ", ".join(quote_identifier(column) for column in schema.common_columns)
        if len(schema.primary_key_columns) == 1:
            pk_column = quote_identifier(schema.primary_key_columns[0])
            placeholders = ", ".join(["%s"] * len(keys))
            where_sql = f"{pk_column} IN ({placeholders})"
            params = [key[0] for key in keys]
        else:
            parts: list[str] = []
            params = []
            for key in keys:
                parts.append("(" + " AND ".join(f"{quote_identifier(column)} = %s" for column in schema.primary_key_columns) + ")")
                params.extend(key)
            where_sql = " OR ".join(parts)

        sql = f"SELECT {columns_sql} FROM {quote_identifier(schema.name)} WHERE {where_sql}"
        rows = target.fetch_all(sql, params)
        return {self._row_key(schema.primary_key_columns, row): row for row in rows}

    def _insert_row(self, target: DatabaseClient, schema: TableSchema, source_row: dict[str, Any]) -> None:
        columns = schema.common_columns
        columns_sql = ", ".join(quote_identifier(column) for column in columns)
        placeholders = ", ".join(["%s"] * len(columns))
        sql = f"INSERT INTO {quote_identifier(schema.name)} ({columns_sql}) VALUES ({placeholders})"
        params = [source_row.get(column) for column in columns]
        target.execute_write(sql, params, allowed_prefixes=("INSERT",))

    def _update_row(
        self,
        target: DatabaseClient,
        schema: TableSchema,
        source_row: dict[str, Any],
        changed_columns: list[str],
    ) -> None:
        set_sql = ", ".join(f"{quote_identifier(column)} = %s" for column in changed_columns)
        where_sql = " AND ".join(f"{quote_identifier(column)} = %s" for column in schema.primary_key_columns)
        sql = f"UPDATE {quote_identifier(schema.name)} SET {set_sql} WHERE {where_sql}"
        params = [source_row.get(column) for column in changed_columns]
        params.extend(source_row.get(column) for column in schema.primary_key_columns)
        target.execute_write(sql, params, allowed_prefixes=("UPDATE",))

    @staticmethod
    def _row_key(pk_columns: list[str], row: dict[str, Any]) -> tuple[Any, ...]:
        return tuple(row.get(column) for column in pk_columns)
