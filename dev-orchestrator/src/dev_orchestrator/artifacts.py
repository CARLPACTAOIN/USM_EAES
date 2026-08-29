from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from dev_orchestrator.models.execution import RunCheckpoint


def task_run_dir(runs_dir: Path, task_id: str) -> Path:
    return runs_dir / task_id


def ensure_run_dir(run_dir: Path) -> Path:
    run_dir.mkdir(parents=True, exist_ok=True)
    return run_dir


def write_json_artifact(run_dir: Path, name: str, payload: dict[str, Any]) -> Path:
    ensure_run_dir(run_dir)
    path = _artifact_path(run_dir, name)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True, default=str), encoding="utf-8")
    return path


def read_json_artifact(run_dir: Path, name: str) -> dict[str, Any]:
    path = _artifact_path(run_dir, name)
    return json.loads(path.read_text(encoding="utf-8"))


def write_text_artifact(run_dir: Path, name: str, content: str) -> Path:
    ensure_run_dir(run_dir)
    path = _artifact_path(run_dir, name)
    path.write_text(content, encoding="utf-8")
    return path


def write_run_checkpoint(
    run_dir: Path,
    task_id: str,
    phase: str,
    state: dict[str, Any],
    pending_approvals: list[str] | None = None,
    proposal_paths: dict[str, str] | None = None,
    resume_hints: list[str] | None = None,
) -> Path:
    checkpoint = RunCheckpoint(
        task_id=task_id,
        phase=phase,  # type: ignore[arg-type]
        saved_state=_json_safe(state),
        pending_approvals=pending_approvals or [],
        proposal_paths=proposal_paths or {},
        resume_hints=resume_hints or [],
    )
    return write_json_artifact(run_dir, "state.json", checkpoint.model_dump(mode="json"))


def read_run_checkpoint(run_dir: Path) -> RunCheckpoint:
    return RunCheckpoint.model_validate(read_json_artifact(run_dir, "state.json"))


def relative_artifact_path(run_dir: Path, path: Path) -> str:
    return path.resolve().relative_to(run_dir.resolve()).as_posix()


def _artifact_path(run_dir: Path, name: str) -> Path:
    path = (run_dir / name).resolve()
    path.relative_to(run_dir.resolve())
    return path


def _json_safe(value: Any) -> Any:
    return json.loads(json.dumps(value, default=str))
