from __future__ import annotations

from pathlib import Path

from dev_orchestrator.tools.commands import run_command


def git_status(repo_root: Path) -> str:
    return run_command(["git", "status", "--short", "--branch"], repo_root, 30).stdout


def git_diff(repo_root: Path) -> str:
    return run_command(["git", "diff", "--", "."], repo_root, 60).stdout


def detect_changed_files(repo_root: Path) -> list[str]:
    result = run_command(["git", "diff", "--name-only"], repo_root, 30)
    files = [line.strip() for line in result.stdout.splitlines() if line.strip()]

    untracked = run_command(["git", "ls-files", "--others", "--exclude-standard"], repo_root, 30)
    files.extend(line.strip() for line in untracked.stdout.splitlines() if line.strip())

    return sorted(set(files))

