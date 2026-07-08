from __future__ import annotations

import argparse
import hashlib
import logging
import re
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Optional

try:
    import pymysql
    from pymysql.cursors import DictCursor
except ImportError:  # pragma: no cover - lets CLI help work before dependencies are installed.
    pymysql = None
    DictCursor = None

try:
    import sqlparse
except ImportError:  # pragma: no cover - documented fallback for bootstrap use.
    sqlparse = None

from fleet_sync_service.config import ConfigurationError, LoggingSettings, load_config
from fleet_sync_service.database import UnsafeSqlError, assert_no_destructive_sql, quote_identifier
from fleet_sync_service.logging_setup import setup_logging


DROP_COLUMN_CONFIRMATION = "EXECUTE_DROP_COLUMN"


@dataclass(frozen=True)
class MigrationFile:
    name: str
    path: Path
    checksum: str
    sql: str
    statements: list[str]
    transactional: bool
    warnings: list[str]


@dataclass(frozen=True)
class MigrationRecord:
    migration_name: str
    checksum: str
    status: str
    applied_at: Any
    duration_ms: int


class MigrationSafetyError(RuntimeError):
    """Raised when a migration contains blocked SQL."""


class MigrationConnection:
    def __init__(self, config, logger: logging.Logger) -> None:
        self.config = config
        self.logger = logger
        self.connection: Optional[pymysql.connections.Connection] = None

    def __enter__(self) -> "MigrationConnection":
        self.connect()
        return self

    def __exit__(self, exc_type: object, exc: object, tb: object) -> None:
        self.close()

    def connect(self) -> None:
        if pymysql is None or DictCursor is None:
            raise RuntimeError("Missing dependency PyMySQL. Run: pip install -r fleet_sync_service/requirements.txt")

        if self.connection and self.connection.open:
            return
        settings = self.config.vps_database
        try:
            self.connection = pymysql.connect(
                host=settings.host,
                port=settings.port,
                user=settings.user,
                password=settings.password,
                database=settings.database,
                charset=settings.charset,
                cursorclass=DictCursor,
                autocommit=False,
                connect_timeout=settings.connect_timeout,
                read_timeout=settings.read_timeout,
                write_timeout=settings.write_timeout,
            )
            self.logger.info("Connected to VPS database %s@%s:%s/%s", settings.user, settings.host, settings.port, settings.database)
        except pymysql.MySQLError as exc:
            raise RuntimeError(f"Could not connect to VPS database: {exc}") from exc

    def close(self) -> None:
        if self.connection:
            self.connection.close()
            self.connection = None

    def fetch_all(self, sql: str, params: Iterable[Any] | None = None) -> list[dict[str, Any]]:
        self.connect()
        try:
            with self._connection().cursor() as cursor:
                cursor.execute(sql, tuple(params or ()))
                return list(cursor.fetchall())
        except pymysql.MySQLError as exc:
            raise RuntimeError(f"SQL read failed: {exc}") from exc

    def fetch_one(self, sql: str, params: Iterable[Any] | None = None) -> Optional[dict[str, Any]]:
        rows = self.fetch_all(sql, params)
        return rows[0] if rows else None

    def execute(self, sql: str, params: Iterable[Any] | None = None) -> int:
        assert_no_destructive_sql(normalize_sql(sql))
        self.logger.info("Executing SQL:\n%s", sql.strip())
        self.connect()
        try:
            with self._connection().cursor() as cursor:
                return cursor.execute(sql, tuple(params or ()))
        except pymysql.MySQLError as exc:
            raise RuntimeError(f"SQL execution failed: {exc}") from exc

    def commit(self) -> None:
        self._connection().commit()

    def rollback(self) -> None:
        self._connection().rollback()

    def _connection(self) -> pymysql.connections.Connection:
        if not self.connection or not self.connection.open:
            self.connect()
        if not self.connection:
            raise RuntimeError("No active VPS database connection.")
        return self.connection


class MigrationRunner:
    def __init__(self, config, logger: logging.Logger, migrations_dir: Path) -> None:
        self.config = config
        self.logger = logger
        self.migrations_dir = migrations_dir
        self.schema_table = config.migrations.schema_table

    def status(self) -> int:
        migrations = self.load_migration_files()
        with MigrationConnection(self.config, self.logger) as connection:
            self.ensure_history_table(connection)
            records = self.load_latest_records(connection)
        return self.print_status(migrations, records)

    def dry_run(self) -> int:
        migrations = self.load_migration_files()
        with MigrationConnection(self.config, self.logger) as connection:
            records = self.load_latest_records(connection) if self.history_table_exists(connection) else {}
        self.validate_applied_checksums(migrations, records)
        return self.print_plan(migrations, records)

    def apply(self) -> int:
        migrations = self.load_migration_files()
        with MigrationConnection(self.config, self.logger) as connection:
            self.ensure_history_table(connection)
            records = self.load_latest_records(connection)
            self.validate_applied_checksums(migrations, records)
            failed = [record for record in records.values() if record.status == "failed"]
            if failed:
                names = ", ".join(record.migration_name for record in failed)
                raise RuntimeError(f"Refusing to continue because failed migrations exist: {names}. Inspect production before retrying.")

            pending = [migration for migration in migrations if records.get(migration.name, None) is None]
            if not pending:
                print("No pending migrations.")
                self.logger.info("No pending migrations.")
                return 0

            for migration in pending:
                self.confirm_warnings(migration)
                self.apply_migration(connection, migration)

        return 0

    def load_migration_files(self) -> list[MigrationFile]:
        self.migrations_dir.mkdir(parents=True, exist_ok=True)
        files = sorted(path for path in self.migrations_dir.glob("*.sql") if path.is_file())
        migrations = [self.build_migration(path) for path in files]
        names = [migration.name for migration in migrations]
        duplicates = sorted({name for name in names if names.count(name) > 1})
        if duplicates:
            raise RuntimeError(f"Duplicate migration filenames detected: {', '.join(duplicates)}")
        return migrations

    def build_migration(self, path: Path) -> MigrationFile:
        sql = path.read_text(encoding="utf-8")
        checksum = hashlib.sha256(sql.encode("utf-8")).hexdigest()
        statements = split_sql_statements(sql)
        warnings = detect_warnings(sql)
        validate_migration_sql(sql)
        return MigrationFile(
            name=path.name,
            path=path,
            checksum=checksum,
            sql=sql,
            statements=statements,
            transactional=has_transaction_marker(sql),
            warnings=warnings,
        )

    def ensure_history_table(self, connection: MigrationConnection) -> None:
        table = quote_identifier(self.schema_table)
        sql = f"""
        CREATE TABLE IF NOT EXISTS {table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_name VARCHAR(255) NOT NULL,
            applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            checksum CHAR(64) NOT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_schema_migrations_name (migration_name),
            KEY idx_schema_migrations_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """
        connection.execute(sql)
        connection.commit()

    def history_table_exists(self, connection: MigrationConnection) -> bool:
        row = connection.fetch_one(
            """
            SELECT 1 AS exists_flag
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
            LIMIT 1
            """,
            (self.schema_table,),
        )
        return row is not None

    def load_latest_records(self, connection: MigrationConnection) -> dict[str, MigrationRecord]:
        if not self.history_table_exists(connection):
            return {}

        rows = connection.fetch_all(
            f"""
            SELECT migration_name, checksum, status, applied_at, duration_ms
            FROM {quote_identifier(self.schema_table)}
            ORDER BY id ASC
            """
        )
        records: dict[str, MigrationRecord] = {}
        for row in rows:
            records[row["migration_name"]] = MigrationRecord(
                migration_name=row["migration_name"],
                checksum=row["checksum"],
                status=row["status"],
                applied_at=row["applied_at"],
                duration_ms=int(row["duration_ms"]),
            )
        return records

    def validate_applied_checksums(self, migrations: list[MigrationFile], records: dict[str, MigrationRecord]) -> None:
        by_name = {migration.name: migration for migration in migrations}
        mismatches = []
        for name, record in records.items():
            migration = by_name.get(name)
            if migration and record.status == "success" and migration.checksum != record.checksum:
                mismatches.append(name)
        if mismatches:
            raise RuntimeError(
                "Applied migration checksum mismatch. Never edit old migration files: "
                + ", ".join(mismatches)
            )

    def print_status(self, migrations: list[MigrationFile], records: dict[str, MigrationRecord]) -> int:
        self.validate_applied_checksums(migrations, records)
        if not migrations:
            print("No migration files found.")
            return 0

        for migration in migrations:
            record = records.get(migration.name)
            if record is None:
                print(f"PENDING  {migration.name}")
            elif record.status == "success":
                print(f"APPLIED  {migration.name}  {record.applied_at}  {record.duration_ms}ms")
            else:
                print(f"FAILED   {migration.name}  {record.applied_at}  {record.duration_ms}ms")
        return 0

    def print_plan(self, migrations: list[MigrationFile], records: dict[str, MigrationRecord]) -> int:
        failed = [record for record in records.values() if record.status == "failed"]
        if failed:
            print("Failed migrations exist; --apply will refuse to continue:")
            for record in failed:
                print(f"FAILED   {record.migration_name}  {record.applied_at}")
            return 1

        pending = [migration for migration in migrations if records.get(migration.name, None) is None]
        if not pending:
            print("No pending migrations.")
            return 0

        print("Pending migrations:")
        for migration in pending:
            warning_suffix = "  WARNINGS" if migration.warnings else ""
            transaction_suffix = "  TRANSACTION" if migration.transactional else "  NO_TRANSACTION_MARKER"
            print(f"PENDING  {migration.name}{transaction_suffix}{warning_suffix}")
            for warning in migration.warnings:
                print(f"WARNING  {migration.name}: {warning}")
        return 0

    def confirm_warnings(self, migration: MigrationFile) -> None:
        if not migration.warnings:
            return

        for warning in migration.warnings:
            self.logger.warning("Migration %s warning: %s", migration.name, warning)
            print(f"WARNING  {migration.name}: {warning}")

        if any("ALTER TABLE DROP COLUMN" in warning for warning in migration.warnings):
            if not sys.stdin.isatty():
                raise RuntimeError(f"Migration {migration.name} requires interactive confirmation for ALTER TABLE DROP COLUMN.")
            answer = input(f"Type {DROP_COLUMN_CONFIRMATION} to execute {migration.name}: ").strip()
            if answer != DROP_COLUMN_CONFIRMATION:
                raise RuntimeError(f"Migration {migration.name} was not confirmed.")

    def apply_migration(self, connection: MigrationConnection, migration: MigrationFile) -> None:
        if not migration.statements:
            raise RuntimeError(f"Migration {migration.name} has no SQL statements.")

        print(f"Applying {migration.name}...")
        self.logger.info("Applying migration %s checksum=%s transactional=%s", migration.name, migration.checksum, migration.transactional)
        started_at = time.monotonic()
        status = "success"
        try:
            if migration.transactional:
                connection.execute("START TRANSACTION")
            for statement in migration.statements:
                connection.execute(statement)
            if migration.transactional:
                connection.commit()
            else:
                connection.commit()
        except Exception:
            status = "failed"
            if migration.transactional:
                connection.rollback()
                self.logger.error("Rolled back transactional migration %s", migration.name)
            raise
        finally:
            duration_ms = int((time.monotonic() - started_at) * 1000)
            try:
                self.record_history(connection, migration, duration_ms, status)
                connection.commit()
            except Exception:
                connection.rollback()
                self.logger.exception("Could not record migration history for %s", migration.name)
            if status == "success":
                print(f"Applied {migration.name} in {duration_ms}ms.")
                self.logger.info("Applied migration %s duration_ms=%s", migration.name, duration_ms)

    def record_history(
        self,
        connection: MigrationConnection,
        migration: MigrationFile,
        duration_ms: int,
        status: str,
    ) -> None:
        sql = (
            f"INSERT INTO {quote_identifier(self.schema_table)} "
            "(migration_name, applied_at, checksum, duration_ms, status) "
            "VALUES (%s, CURRENT_TIMESTAMP(6), %s, %s, %s)"
        )
        connection.execute(sql, (migration.name, migration.checksum, duration_ms, status))


def split_sql_statements(sql: str) -> list[str]:
    if sqlparse:
        return [statement.strip() for statement in sqlparse.split(sql) if normalize_sql(statement)]
    return fallback_split_sql(sql)


def fallback_split_sql(sql: str) -> list[str]:
    statements: list[str] = []
    current: list[str] = []
    quote: str | None = None
    escaped = False
    for char in sql:
        current.append(char)
        if escaped:
            escaped = False
            continue
        if char == "\\":
            escaped = True
            continue
        if quote:
            if char == quote:
                quote = None
            continue
        if char in ("'", '"', "`"):
            quote = char
            continue
        if char == ";":
            statement = "".join(current).strip()
            if statement and normalize_sql(statement):
                statements.append(statement)
            current = []
    tail = "".join(current).strip()
    if tail and normalize_sql(tail):
        statements.append(tail)
    return statements


def has_transaction_marker(sql: str) -> bool:
    return bool(re.search(r"^\s*(?:--|#)\s*@transaction\b", sql, re.IGNORECASE | re.MULTILINE)) or bool(
        re.search(r"/\*\s*@transaction\s*\*/", sql, re.IGNORECASE)
    )


def strip_comments(sql: str) -> str:
    if sqlparse:
        return sqlparse.format(sql, strip_comments=True)
    without_block = re.sub(r"/\*.*?\*/", " ", sql, flags=re.DOTALL)
    without_dash = re.sub(r"^\s*--.*?$", " ", without_block, flags=re.MULTILINE)
    return re.sub(r"^\s*#.*?$", " ", without_dash, flags=re.MULTILINE)


def normalize_sql(sql: str) -> str:
    return re.sub(r"\s+", " ", strip_comments(sql)).strip()


def validate_migration_sql(sql: str) -> None:
    normalized = normalize_sql(sql)
    try:
        assert_no_destructive_sql(normalized)
    except UnsafeSqlError as exc:
        raise MigrationSafetyError(str(exc)) from exc


def detect_warnings(sql: str) -> list[str]:
    warnings: list[str] = []
    for statement in split_sql_statements(sql):
        normalized = normalize_sql(statement)
        if has_alter_table_drop_column(normalized):
            warnings.append("ALTER TABLE DROP COLUMN detected; production data in that column will be lost unless explicitly confirmed.")
    return warnings


def has_alter_table_drop_column(statement: str) -> bool:
    if not re.search(r"\bALTER\s+TABLE\b", statement, re.IGNORECASE):
        return False
    for match in re.finditer(r"\bDROP\s+(?:COLUMN\s+)?(`[^`]+`|[A-Za-z_][A-Za-z0-9_$]*)", statement, re.IGNORECASE):
        token = match.group(1).strip("`").upper()
        if token not in {"INDEX", "KEY", "PRIMARY", "FOREIGN", "CONSTRAINT", "CHECK"}:
            return True
    return False


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Apply forward-only schema migrations to the VPS database.")
    parser.add_argument(
        "--config",
        default=str(Path(__file__).resolve().parent / "fleet_sync_service" / "config.ini"),
        help="Path to fleet_sync_service/config.ini.",
    )
    parser.add_argument("--migrations-dir", help="Override the migrations directory from config.ini.")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--status", action="store_true", help="Show applied and pending migrations.")
    mode.add_argument("--dry-run", action="store_true", help="Show pending migrations without executing SQL.")
    mode.add_argument("--apply", action="store_true", help="Execute pending migrations on the VPS database.")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        config = load_config(args.config)
        migration_log_settings = LoggingSettings(
            level=config.logging.level,
            file=config.migrations.log_file,
            max_bytes=config.logging.max_bytes,
            backup_count=config.logging.backup_count,
        )
        logger = setup_logging(migration_log_settings, "fleet_migrations")
        migrations_dir = Path(args.migrations_dir).expanduser().resolve() if args.migrations_dir else config.migrations.directory
        runner = MigrationRunner(config, logger, migrations_dir)

        if args.status:
            return runner.status()
        if args.dry_run:
            return runner.dry_run()
        if args.apply:
            return runner.apply()
        return 2
    except (ConfigurationError, MigrationSafetyError, RuntimeError) as exc:
        print(f"Migration error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
