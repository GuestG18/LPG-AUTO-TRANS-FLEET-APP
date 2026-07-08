from __future__ import annotations

import logging
import re
from typing import Any, Iterable, Optional

try:
    import pymysql
    from pymysql.cursors import DictCursor
except ImportError:  # pragma: no cover - lets CLI help work before dependencies are installed.
    pymysql = None
    DictCursor = None

from .config import DatabaseSettings


class DatabaseError(RuntimeError):
    """Raised for database-level failures."""


class UnsafeSqlError(DatabaseError):
    """Raised when a SQL statement violates the service safety rules."""


DESTRUCTIVE_SQL_PATTERNS = (
    re.compile(r"\bDROP\s+DATABASE\b", re.IGNORECASE),
    re.compile(r"\bDROP\s+SCHEMA\b", re.IGNORECASE),
    re.compile(r"\bDROP\s+TABLE\b", re.IGNORECASE),
    re.compile(r"\bTRUNCATE\b", re.IGNORECASE),
    re.compile(r"\bDELETE\s+FROM\b", re.IGNORECASE),
)


def quote_identifier(identifier: str) -> str:
    if not identifier:
        raise ValueError("Identifier cannot be empty.")
    return f"`{identifier.replace('`', '``')}`"


def assert_no_destructive_sql(sql: str) -> None:
    for pattern in DESTRUCTIVE_SQL_PATTERNS:
        if pattern.search(sql):
            raise UnsafeSqlError(f"Blocked unsafe SQL statement: {pattern.pattern}")


class DatabaseClient:
    def __init__(
        self,
        settings: DatabaseSettings,
        role: str,
        read_only: bool = False,
        logger: Optional[logging.Logger] = None,
    ) -> None:
        self.settings = settings
        self.role = role
        self.read_only = read_only
        self.logger = logger or logging.getLogger(__name__)
        self.connection: Optional[pymysql.connections.Connection] = None

    def __enter__(self) -> "DatabaseClient":
        self.connect()
        return self

    def __exit__(self, exc_type: object, exc: object, tb: object) -> None:
        self.close()

    def connect(self) -> None:
        if pymysql is None or DictCursor is None:
            raise DatabaseError("Missing dependency PyMySQL. Run: pip install -r fleet_sync_service/requirements.txt")

        if self.connection and self.connection.open:
            return

        try:
            self.connection = pymysql.connect(
                host=self.settings.host,
                port=self.settings.port,
                user=self.settings.user,
                password=self.settings.password,
                database=self.settings.database,
                charset=self.settings.charset,
                cursorclass=DictCursor,
                autocommit=False,
                connect_timeout=self.settings.connect_timeout,
                read_timeout=self.settings.read_timeout,
                write_timeout=self.settings.write_timeout,
            )
            self.logger.info("Connected to %s database %s@%s:%s/%s", self.role, self.settings.user, self.settings.host, self.settings.port, self.settings.database)
        except pymysql.MySQLError as exc:
            raise DatabaseError(f"Could not connect to {self.role} database: {exc}") from exc

    def close(self) -> None:
        if self.connection:
            self.connection.close()
            self.connection = None

    def commit(self) -> None:
        self._connection().commit()

    def rollback(self) -> None:
        self._connection().rollback()

    def test_connection(self) -> dict[str, Any]:
        row = self.fetch_one("SELECT DATABASE() AS database_name, VERSION() AS server_version")
        return row or {}

    def fetch_one(self, sql: str, params: Iterable[Any] | None = None) -> Optional[dict[str, Any]]:
        rows = self.fetch_all(sql, params)
        return rows[0] if rows else None

    def fetch_all(self, sql: str, params: Iterable[Any] | None = None) -> list[dict[str, Any]]:
        self.connect()
        try:
            with self._connection().cursor() as cursor:
                cursor.execute(sql, tuple(params or ()))
                return list(cursor.fetchall())
        except pymysql.MySQLError as exc:
            raise DatabaseError(f"SQL read failed on {self.role}: {exc}") from exc

    def execute_write(self, sql: str, params: Iterable[Any] | None = None, allowed_prefixes: tuple[str, ...] = ("INSERT", "UPDATE")) -> int:
        if self.read_only:
            raise UnsafeSqlError(f"Refusing to execute write SQL on read-only {self.role} connection.")

        assert_no_destructive_sql(sql)
        first_keyword = sql.lstrip().split(None, 1)[0].upper() if sql.strip() else ""
        if allowed_prefixes and first_keyword not in allowed_prefixes:
            raise UnsafeSqlError(f"Only {', '.join(allowed_prefixes)} statements are allowed here; got {first_keyword or 'empty SQL'}.")

        self.connect()
        try:
            with self._connection().cursor() as cursor:
                return cursor.execute(sql, tuple(params or ()))
        except pymysql.MySQLError as exc:
            raise DatabaseError(f"SQL write failed on {self.role}: {exc}") from exc

    def table_exists(self, table_name: str) -> bool:
        row = self.fetch_one(
            """
            SELECT 1 AS table_exists
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
            LIMIT 1
            """,
            (table_name,),
        )
        return row is not None

    def get_columns(self, table_name: str) -> list[dict[str, Any]]:
        return self.fetch_all(
            """
            SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_KEY, EXTRA, DATA_TYPE, ORDINAL_POSITION
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
            ORDER BY ORDINAL_POSITION
            """,
            (table_name,),
        )

    def get_primary_key_columns(self, table_name: str) -> list[str]:
        rows = self.fetch_all(
            """
            SELECT kcu.COLUMN_NAME
            FROM information_schema.TABLE_CONSTRAINTS tc
            JOIN information_schema.KEY_COLUMN_USAGE kcu
              ON kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
             AND kcu.TABLE_SCHEMA = tc.TABLE_SCHEMA
             AND kcu.TABLE_NAME = tc.TABLE_NAME
             AND kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()
              AND tc.TABLE_NAME = %s
              AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
            ORDER BY kcu.ORDINAL_POSITION
            """,
            (table_name,),
        )
        return [row["COLUMN_NAME"] for row in rows]

    def _connection(self) -> pymysql.connections.Connection:
        if not self.connection or not self.connection.open:
            self.connect()
        if not self.connection:
            raise DatabaseError(f"No active {self.role} connection.")
        return self.connection
