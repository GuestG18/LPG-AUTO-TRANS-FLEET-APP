from __future__ import annotations

import logging
import re
from dataclasses import dataclass

from .database import DatabaseClient


SAFE_TABLE_NAME = re.compile(r"^[A-Za-z0-9_]+$")


@dataclass(frozen=True)
class TableSchema:
    name: str
    primary_key_columns: list[str]
    timestamp_column: str
    common_columns: list[str]
    update_columns: list[str]


@dataclass(frozen=True)
class TableValidationResult:
    valid: bool
    schema: TableSchema | None = None
    reason: str = ""


def validate_table(
    source: DatabaseClient,
    target: DatabaseClient,
    table_name: str,
    logger: logging.Logger,
) -> TableValidationResult:
    if not SAFE_TABLE_NAME.match(table_name):
        return TableValidationResult(False, reason="table name contains unsupported characters")

    if not source.table_exists(table_name):
        return TableValidationResult(False, reason="table does not exist on VPS/source database")
    if not target.table_exists(table_name):
        return TableValidationResult(False, reason="table does not exist on localhost/target database")

    source_pk = source.get_primary_key_columns(table_name)
    target_pk = target.get_primary_key_columns(table_name)
    if not source_pk:
        return TableValidationResult(False, reason="primary key missing on VPS/source database")
    if not target_pk:
        return TableValidationResult(False, reason="primary key missing on localhost/target database")
    if source_pk != target_pk:
        return TableValidationResult(False, reason=f"primary key mismatch; VPS={source_pk}, localhost={target_pk}")

    source_columns = [row["COLUMN_NAME"] for row in source.get_columns(table_name)]
    target_columns = [row["COLUMN_NAME"] for row in target.get_columns(table_name)]
    source_set = set(source_columns)
    target_set = set(target_columns)

    timestamp_column = ""
    for candidate in ("updated_at", "created_at"):
        if candidate in source_set and candidate in target_set:
            timestamp_column = candidate
            break

    if not timestamp_column:
        return TableValidationResult(False, reason="updated_at or created_at column missing on one of the databases")

    if timestamp_column == "created_at":
        logger.warning("Table %s uses created_at fallback; updates to existing rows are only detected if created_at changes.", table_name)

    missing_on_target = [column for column in source_columns if column not in target_set]
    missing_on_source = [column for column in target_columns if column not in source_set]
    if missing_on_target:
        logger.warning("Table %s has VPS columns missing locally and they will not be synced: %s", table_name, ", ".join(missing_on_target))
    if missing_on_source:
        logger.info("Table %s has local-only columns that will be left untouched: %s", table_name, ", ".join(missing_on_source))

    common_columns = [column for column in source_columns if column in target_set]
    missing_pk_from_common = [column for column in source_pk if column not in common_columns]
    if missing_pk_from_common:
        return TableValidationResult(False, reason=f"primary key columns missing from common schema: {missing_pk_from_common}")

    update_columns = [column for column in common_columns if column not in source_pk]
    return TableValidationResult(
        True,
        schema=TableSchema(
            name=table_name,
            primary_key_columns=source_pk,
            timestamp_column=timestamp_column,
            common_columns=common_columns,
            update_columns=update_columns,
        ),
    )
