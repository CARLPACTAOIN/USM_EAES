from __future__ import annotations

from pathlib import Path

from dev_orchestrator.tools.commands import run_command


def verifier_node(state: dict[str, object]) -> dict[str, object]:
    repo_root = Path(str(state["repo_root"]))
    changed_files = [str(path) for path in state.get("changed_files", [])]
    assessment = state.get("assessment", {})
    commands = select_verification_commands(changed_files, assessment if isinstance(assessment, dict) else {})
    commands = _available_commands(repo_root, commands, assessment if isinstance(assessment, dict) else {})
    mock_batches = state.get("mock_verification_result_batches")
    if isinstance(mock_batches, list) and mock_batches:
        current_batch = mock_batches[0]
        results = current_batch if isinstance(current_batch, list) else []
        failed = any(isinstance(result, dict) and result.get("exit_code", 0) != 0 for result in results)
        return {
            "verification_commands": [" ".join(command) for command, _cwd in commands],
            "verification_results": results,
            "verification_failures": [] if not failed else state.get("verification_failures", []),
            "mock_verification_result_batches": mock_batches[1:],
            "final_status": "completed" if not failed else "verification_failed",
            "node_log": ["verifier:mock"],
        }

    if not state.get("execute", False):
        return {
            "verification_commands": [" ".join(command) for command, _cwd in commands],
            "verification_results": [],
            "verification_failures": [],
            "final_status": "completed",
            "node_log": ["verifier:dry-run"],
        }

    results = []
    for command, cwd in commands:
        results.append(run_command(command, repo_root / cwd, timeout_seconds=180).as_dict())
    failed = any(result.get("exit_code", 0) != 0 for result in results)

    return {
        "verification_commands": [" ".join(command) for command, _cwd in commands],
        "verification_results": results,
        "verification_failures": [] if not failed else state.get("verification_failures", []),
        "final_status": "completed" if not failed else "verification_failed",
        "node_log": ["verifier"],
    }


def select_verification_commands(
    changed_files: list[str],
    assessment: dict[str, object],
) -> list[tuple[list[str], str]]:
    commands: list[tuple[list[str], str]] = []
    changed = "\n".join(changed_files)
    needs_tests = bool(assessment.get("needs_tests", True))

    if "Web/" in changed or _task_mentions_laravel(assessment):
        if any(path.endswith(".php") for path in changed_files):
            commands.append((["php", "artisan", "test", "--compact"], "Web"))
            commands.append((["vendor/bin/pint", "--dirty", "--format", "agent"], "Web"))
        elif needs_tests:
            commands.append((["php", "artisan", "test", "--compact"], "Web"))

        if any(path.startswith("Web/resources/") or path.endswith((".js", ".css", ".blade.php")) for path in changed_files):
            commands.append((["npm", "run", "build"], "Web"))

    if "Application/" in changed:
        commands.append((["flutter", "analyze"], "Application"))
        if needs_tests:
            commands.append((["flutter", "test"], "Application"))

    if not commands and needs_tests:
        commands.append((["git", "diff", "--check"], "."))

    return commands


def _task_mentions_laravel(assessment: dict[str, object]) -> bool:
    task_type = str(assessment.get("task_type", ""))
    return task_type in {"implementation", "frontend-ui"} and bool(assessment.get("needs_tests", True))


def _available_commands(
    repo_root: Path,
    commands: list[tuple[list[str], str]],
    assessment: dict[str, object],
) -> list[tuple[list[str], str]]:
    available = [(command, cwd) for command, cwd in commands if (repo_root / cwd).exists()]
    if available or not bool(assessment.get("needs_tests", True)):
        return available
    return [(["git", "diff", "--check"], ".")]
