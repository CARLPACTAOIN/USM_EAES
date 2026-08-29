from __future__ import annotations

from pathlib import Path

from dev_orchestrator.models.execution import PatchApplyResult, PatchProposal
from dev_orchestrator.tools.commands import run_command
from dev_orchestrator.tools.git import detect_changed_files


def apply_patch_proposal(
    repo_root: Path,
    run_dir: Path,
    patch_path: Path,
    proposal: PatchProposal,
    baseline_dirty_files: list[str],
) -> PatchApplyResult:
    try:
        resolved_patch_path = patch_path.resolve()
        resolved_patch_path.relative_to(run_dir.resolve())
    except ValueError:
        return PatchApplyResult(applied=False, failure_reason="Patch path is outside this task's run directory.")

    if not resolved_patch_path.exists():
        return PatchApplyResult(applied=False, failure_reason="Patch file does not exist.")

    unrelated = _new_unrelated_dirty_files(repo_root, baseline_dirty_files, proposal.affected_files)
    if unrelated:
        return PatchApplyResult(
            applied=False,
            changed_files=detect_changed_files(repo_root),
            failure_reason=f"New unrelated dirty files appeared before patch apply: {', '.join(unrelated)}",
        )

    check = run_command(["git", "apply", "--check", str(resolved_patch_path)], repo_root, timeout_seconds=60)
    check_payload = check.as_dict()
    if check.exit_code != 0:
        return PatchApplyResult(applied=False, git_apply_check=check_payload, failure_reason="git apply --check failed.")

    applied = run_command(["git", "apply", str(resolved_patch_path)], repo_root, timeout_seconds=60)
    if applied.exit_code != 0:
        return PatchApplyResult(
            applied=False,
            git_apply_check=check_payload,
            failure_reason="git apply failed after a successful check.",
        )

    return PatchApplyResult(
        applied=True,
        git_apply_check=check_payload,
        changed_files=_without_run_artifacts(repo_root, detect_changed_files(repo_root)),
    )


def _new_unrelated_dirty_files(repo_root: Path, baseline_dirty_files: list[str], affected_files: list[str]) -> list[str]:
    baseline = set(baseline_dirty_files)
    affected = set(affected_files)
    current = set(_without_run_artifacts(repo_root, detect_changed_files(repo_root)))
    return sorted(path for path in current - baseline if path not in affected)


def _without_run_artifacts(repo_root: Path, paths: list[str]) -> list[str]:
    filtered: list[str] = []
    for path in paths:
        parts = Path(path).parts
        if ".runs" in parts:
            continue
        if path.startswith("dev-orchestrator/.runs/"):
            continue
        filtered.append(path)
    return filtered
