from __future__ import annotations

import logging
from types import TracebackType

try:
    from sshtunnel import SSHTunnelForwarder
except ImportError:  # pragma: no cover - lets CLI help work before dependencies are installed.
    SSHTunnelForwarder = None

from .config import DatabaseSettings, SshTunnelSettings


class SshTunnelError(RuntimeError):
    """Raised when the SSH tunnel cannot be started or stopped cleanly."""


class SshTunnel:
    def __init__(
        self,
        settings: SshTunnelSettings,
        vps_database: DatabaseSettings,
        logger: logging.Logger,
    ) -> None:
        self.settings = settings
        self.vps_database = vps_database
        self.logger = logger
        self.forwarder = None

    def __enter__(self) -> "SshTunnel":
        self.start()
        return self

    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc: BaseException | None,
        tb: TracebackType | None,
    ) -> None:
        self.stop()

    @property
    def enabled(self) -> bool:
        return self.settings.enabled

    def start(self) -> None:
        if not self.settings.enabled:
            self.logger.info("SSH tunnel disabled; connecting directly to VPS database host %s:%s.", self.vps_database.host, self.vps_database.port)
            return

        if SSHTunnelForwarder is None:
            raise SshTunnelError("Missing dependency sshtunnel. Run: pip install -r fleet_sync_service/requirements.txt")

        tunnel_kwargs = {
            "ssh_address_or_host": (self.settings.host, self.settings.port),
            "ssh_username": self.settings.user,
            "remote_bind_address": (self.settings.remote_bind_host, self.settings.remote_bind_port),
            "local_bind_address": (self.settings.local_bind_host, self.settings.local_bind_port),
        }
        if self.settings.password:
            tunnel_kwargs["ssh_password"] = self.settings.password
        if self.settings.private_key:
            tunnel_kwargs["ssh_pkey"] = str(self.settings.private_key)

        self.logger.info(
            "Starting SSH tunnel %s@%s:%s -> %s:%s on %s:%s",
            self.settings.user,
            self.settings.host,
            self.settings.port,
            self.settings.remote_bind_host,
            self.settings.remote_bind_port,
            self.settings.local_bind_host,
            self.settings.local_bind_port,
        )
        try:
            self.forwarder = SSHTunnelForwarder(**tunnel_kwargs)
            self.forwarder.start()
        except Exception as exc:
            self.forwarder = None
            raise SshTunnelError(
                "SSH tunnel failed before MySQL connection. "
                f"Could not open SSH tunnel to {self.settings.user}@{self.settings.host}:{self.settings.port}: {exc}"
            ) from exc

        if not self.forwarder or not self.forwarder.is_active:
            raise SshTunnelError(
                f"SSH tunnel to {self.settings.user}@{self.settings.host}:{self.settings.port} did not become active."
            )

        self.logger.info(
            "SSH tunnel OK: VPS MySQL %s:%s is available locally at %s:%s",
            self.settings.remote_bind_host,
            self.settings.remote_bind_port,
            self.local_bind_host,
            self.local_bind_port,
        )

    def stop(self) -> None:
        if self.forwarder is None:
            return
        try:
            self.forwarder.stop()
            self.logger.info("SSH tunnel stopped.")
        except Exception as exc:
            raise SshTunnelError(f"SSH tunnel stopped with an error: {exc}") from exc
        finally:
            self.forwarder = None

    @property
    def local_bind_host(self) -> str:
        return self.settings.local_bind_host

    @property
    def local_bind_port(self) -> int:
        if self.forwarder is not None:
            return int(self.forwarder.local_bind_port)
        return self.settings.local_bind_port

    def database_settings(self) -> DatabaseSettings:
        if not self.settings.enabled:
            return self.vps_database
        return self.vps_database.with_host_port(self.local_bind_host, self.local_bind_port)

    def database_role(self) -> str:
        return "VPS/source through SSH tunnel" if self.settings.enabled else "VPS/source"
