from __future__ import annotations

import argparse
import sys
from pathlib import Path

if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from fleet_sync_service.config import ConfigurationError, default_config_path, load_config
from fleet_sync_service.database import DatabaseError
from fleet_sync_service.logging_setup import setup_logging
from fleet_sync_service.tunnel import SshTunnelError


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Synchronize VPS fleet data to the local development database.")
    parser.add_argument(
        "--config",
        default=str(default_config_path()),
        help="Path to config.ini. Defaults to fleet_sync_service/config.ini.",
    )
    parser.add_argument("--dry-run", action="store_true", help="Show what would be inserted or updated without writing locally.")
    parser.add_argument("--test-connections", action="store_true", help="Connect to both databases and exit.")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        config = load_config(args.config)
        logger = setup_logging(config.logging, "fleet_sync")
        from fleet_sync_service.sync_engine import SyncEngine

        engine = SyncEngine(config, logger, dry_run=args.dry_run)

        if args.test_connections:
            engine.test_connections()
            return 0

        results = engine.run()
        return 1 if any(result.status == "failed" for result in results) else 0
    except ConfigurationError as exc:
        print(f"Configuration error: {exc}", file=sys.stderr)
        return 2
    except SshTunnelError as exc:
        print(f"SSH tunnel error: {exc}", file=sys.stderr)
        return 1
    except DatabaseError as exc:
        print(f"MySQL error: {exc}", file=sys.stderr)
        return 1
    except Exception as exc:
        print(f"Synchronization failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
