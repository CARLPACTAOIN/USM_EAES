from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def write_run_log(runs_dir: Path, task_id: str, payload: dict[str, Any]) -> Path:
    runs_dir.mkdir(parents=True, exist_ok=True)
    path = runs_dir / f"{task_id}.json"
    serializable = {
        "logged_at": datetime.now(timezone.utc).isoformat(),
        **payload,
    }
    path.write_text(json.dumps(serializable, indent=2, sort_keys=True, default=str), encoding="utf-8")
    return path


def read_run_log(runs_dir: Path, task_id: str) -> dict[str, Any]:
    path = runs_dir / f"{task_id}.json"
    if not path.exists():
        raise FileNotFoundError(f"No run log found for task id {task_id}.")
    return json.loads(path.read_text(encoding="utf-8"))

