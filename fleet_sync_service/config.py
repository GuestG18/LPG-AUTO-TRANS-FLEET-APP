from __future__ import annotations

import configparser
from dataclasses import dataclass
from dataclasses import replace
from pathlib import Path
from typing import Optional


class ConfigurationError(RuntimeError):
    """Raised when config.ini is missing or invalid."""


@dataclass(frozen=True)
class DatabaseSettings:
    host: str
    port: int
    database: str
    user: str
    password: str
    charset: str = "utf8mb4"
    connect_timeout: int = 10
    read_timeout: int = 60
    write_timeout: int = 60

    def with_host_port(self, host: str, port: int) -> "DatabaseSettings":
        return replace(self, host=host, port=port)


@dataclass(frozen=True)
class SshTunnelSettings:
    enabled: bool
    host: str
    port: int
    user: str
    password: str
    private_key: Path | None
    remote_bind_host: str
    remote_bind_port: int
    local_bind_host: str
    local_bind_port: int


@dataclass(frozen=True)
class SyncSettings:
    tables: list[str]
    batch_size: int
    state_file: Path
    initial_timestamp: str


@dataclass(frozen=True)
class LoggingSettings:
    level: str
    file: Path
    max_bytes: int
    backup_count: int


@dataclass(frozen=True)
class MigrationSettings:
    directory: Path
    log_file: Path
    schema_table: str


@dataclass(frozen=True)
class AppConfig:
    path: Path
    vps_database: DatabaseSettings
    local_database: DatabaseSettings
    ssh_tunnel: SshTunnelSettings
    sync: SyncSettings
    logging: LoggingSettings
    migrations: MigrationSettings


def default_config_path() -> Path:
    return Path(__file__).resolve().parent / "config.ini"


def load_config(config_path: Optional[str | Path] = None) -> AppConfig:
    path = Path(config_path) if config_path else default_config_path()
    path = path.expanduser().resolve()
    if not path.exists():
        example = path.with_name("config.example.ini")
        raise ConfigurationError(
            f"Missing config file: {path}. Copy {example.name} to {path.name} and fill in credentials."
        )

    parser = configparser.ConfigParser(interpolation=None)
    parser.read(path, encoding="utf-8")

    vps = _database_settings(parser, "vps_database")
    local = _database_settings(parser, "local_database")
    ssh_tunnel = _ssh_tunnel_settings(parser, path.parent)
    sync = _sync_settings(parser, path.parent)
    logging = _logging_settings(parser, path.parent)
    migrations = _migration_settings(parser, path.parent)

    return AppConfig(
        path=path,
        vps_database=vps,
        local_database=local,
        ssh_tunnel=ssh_tunnel,
        sync=sync,
        logging=logging,
        migrations=migrations,
    )


def _database_settings(parser: configparser.ConfigParser, section: str) -> DatabaseSettings:
    if not parser.has_section(section):
        raise ConfigurationError(f"Missing required [{section}] section.")

    required = ("host", "database", "user")
    missing = [key for key in required if not parser.get(section, key, fallback="").strip()]
    if missing:
        raise ConfigurationError(f"Missing required [{section}] values: {', '.join(missing)}.")

    return DatabaseSettings(
        host=parser.get(section, "host").strip(),
        port=parser.getint(section, "port", fallback=3306),
        database=parser.get(section, "database").strip(),
        user=parser.get(section, "user").strip(),
        password=parser.get(section, "password", fallback=""),
        charset=parser.get(section, "charset", fallback="utf8mb4").strip(),
        connect_timeout=parser.getint(section, "connect_timeout", fallback=10),
        read_timeout=parser.getint(section, "read_timeout", fallback=60),
        write_timeout=parser.getint(section, "write_timeout", fallback=60),
    )


def _sync_settings(parser: configparser.ConfigParser, base_dir: Path) -> SyncSettings:
    if not parser.has_section("sync"):
        raise ConfigurationError("Missing required [sync] section.")

    tables = _parse_list(parser.get("sync", "tables", fallback=""))
    if not tables:
        raise ConfigurationError("The [sync] tables value must contain at least one table name.")

    batch_size = parser.getint("sync", "batch_size", fallback=500)
    if batch_size <= 0:
        raise ConfigurationError("The [sync] batch_size value must be greater than zero.")

    state_file = _resolve_path(parser.get("sync", "state_file", fallback="state/last_sync.json"), base_dir)
    initial_timestamp = parser.get("sync", "initial_timestamp", fallback="1970-01-01 00:00:00").strip()

    return SyncSettings(
        tables=tables,
        batch_size=batch_size,
        state_file=state_file,
        initial_timestamp=initial_timestamp,
    )


def _ssh_tunnel_settings(parser: configparser.ConfigParser, base_dir: Path) -> SshTunnelSettings:
    if not parser.has_section("ssh_tunnel"):
        return SshTunnelSettings(
            enabled=False,
            host="",
            port=22,
            user="",
            password="",
            private_key=None,
            remote_bind_host="127.0.0.1",
            remote_bind_port=3306,
            local_bind_host="127.0.0.1",
            local_bind_port=3307,
        )

    enabled = parser.getboolean("ssh_tunnel", "enabled", fallback=False)
    host = parser.get("ssh_tunnel", "host", fallback="").strip()
    user = parser.get("ssh_tunnel", "user", fallback="").strip()
    password = parser.get("ssh_tunnel", "password", fallback="")
    private_key_value = parser.get("ssh_tunnel", "private_key", fallback="").strip()
    private_key = _resolve_path(private_key_value, base_dir) if private_key_value else None

    if enabled:
        missing = []
        if not host:
            missing.append("host")
        if not user:
            missing.append("user")
        if missing:
            raise ConfigurationError(f"Missing required [ssh_tunnel] values when enabled=true: {', '.join(missing)}.")
        if private_key and not private_key.exists():
            raise ConfigurationError(f"[ssh_tunnel] private_key does not exist: {private_key}")

    return SshTunnelSettings(
        enabled=enabled,
        host=host,
        port=parser.getint("ssh_tunnel", "port", fallback=22),
        user=user,
        password=password,
        private_key=private_key,
        remote_bind_host=parser.get("ssh_tunnel", "remote_bind_host", fallback="127.0.0.1").strip(),
        remote_bind_port=parser.getint("ssh_tunnel", "remote_bind_port", fallback=3306),
        local_bind_host=parser.get("ssh_tunnel", "local_bind_host", fallback="127.0.0.1").strip(),
        local_bind_port=parser.getint("ssh_tunnel", "local_bind_port", fallback=3307),
    )


def _logging_settings(parser: configparser.ConfigParser, base_dir: Path) -> LoggingSettings:
    if parser.has_section("logging"):
        level = parser.get("logging", "level", fallback="INFO").strip().upper()
        file_path = parser.get("logging", "file", fallback="logs/sync_data.log").strip()
        max_bytes = parser.getint("logging", "max_bytes", fallback=5_242_880)
        backup_count = parser.getint("logging", "backup_count", fallback=5)
    else:
        level = "INFO"
        file_path = "logs/sync_data.log"
        max_bytes = 5_242_880
        backup_count = 5

    return LoggingSettings(
        level=level,
        file=_resolve_path(file_path, base_dir),
        max_bytes=max_bytes,
        backup_count=backup_count,
    )


def _migration_settings(parser: configparser.ConfigParser, base_dir: Path) -> MigrationSettings:
    if parser.has_section("migrations"):
        directory = parser.get("migrations", "directory", fallback="../database/migrations").strip()
        log_file = parser.get("migrations", "log_file", fallback="../database/migrations/migrations.log").strip()
        schema_table = parser.get("migrations", "schema_table", fallback="schema_migrations").strip()
    else:
        directory = "../database/migrations"
        log_file = "../database/migrations/migrations.log"
        schema_table = "schema_migrations"

    if not schema_table:
        raise ConfigurationError("The [migrations] schema_table value cannot be empty.")

    return MigrationSettings(
        directory=_resolve_path(directory, base_dir),
        log_file=_resolve_path(log_file, base_dir),
        schema_table=schema_table,
    )


def _parse_list(value: str) -> list[str]:
    normalized = value.replace("\n", ",")
    return [item.strip() for item in normalized.split(",") if item.strip()]


def _resolve_path(value: str, base_dir: Path) -> Path:
    path = Path(value).expanduser()
    if path.is_absolute():
        return path.resolve()
    return (base_dir / path).resolve()
