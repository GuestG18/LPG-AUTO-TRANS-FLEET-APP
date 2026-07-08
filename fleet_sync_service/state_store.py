from __future__ import annotations

import json
import os
from datetime import datetime
from pathlib import Path
from typing import Any


class SyncStateStore:
    def __init__(self, path: Path) -> None:
        self.path = path
        self.data = self._load()

    def get_last_sync(self, table_name: str, fallback_timestamp: str) -> str:
        table_state = self.data.get("tables", {}).get(table_name, {})
        return table_state.get("last_successful_sync", fallback_timestamp)

    def record_success(
        self,
        table_name: str,
        last_successful_sync: str,
        timestamp_column: str,
        inserted_rows: int,
        updated_rows: int,
        scanned_rows: int,
        duration_ms: int,
        dry_run: bool,
    ) -> None:
        self.data.setdefault("version", 1)
        self.data.setdefault("tables", {})
        self.data["tables"][table_name] = {
            "last_successful_sync": last_successful_sync,
            "timestamp_column": timestamp_column,
            "inserted_rows": inserted_rows,
            "updated_rows": updated_rows,
            "scanned_rows": scanned_rows,
            "duration_ms": duration_ms,
            "dry_run": dry_run,
            "recorded_at": datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S.%f"),
        }
        self.save()

    def save(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = self.path.with_suffix(self.path.suffix + ".tmp")
        with tmp_path.open("w", encoding="utf-8") as handle:
            json.dump(self.data, handle, indent=2, sort_keys=True)
            handle.write("\n")
        os.replace(tmp_path, self.path)

    def _load(self) -> dict[str, Any]:
        if not self.path.exists():
            return {"version": 1, "tables": {}}
        with self.path.open("r", encoding="utf-8") as handle:
            return json.load(handle)
