from __future__ import annotations

from dev_orchestrator.models.reliability import HumanEscalation, RepairAction, VerificationFailure


def diagnose_node(state: dict[str, object]) -> dict[str, object]:
    failures = collect_verification_failures(state.get("verification_results", []))
    return {
        "verification_failures": [failure.model_dump(mode="json") for failure in failures],
        "review_findings": [f"Verification failed in {len(failures)} command(s); diagnose before repair."],
        "node_log": ["diagnose"],
    }


def repair_node(state: dict[str, object]) -> dict[str, object]:
    attempt = int(state.get("attempt_count", 0)) + 1
    max_attempts = int(state.get("max_repair_attempts", 3))
    failures = state.get("verification_failures", [])
    assessment = state.get("assessment", {})
    recommended_tier = _recommended_repair_tier(assessment if isinstance(assessment, dict) else {})
    commands = [
        str(failure.get("command", ""))
        for failure in failures
        if isinstance(failure, dict) and failure.get("command")
    ]
    action = RepairAction(
        attempt=attempt,
        action_type="bounded_verification_retry",
        reason=_repair_reason(failures),
        target_commands=commands,
        recommended_model_tier=recommended_tier,
    )
    repair_actions = [*state.get("repair_actions", []), action.model_dump(mode="json")]
    repair_history = [
        *state.get("repair_history", []),
        {
            "attempt": attempt,
            "failure_count": len(failures) if isinstance(failures, list) else 0,
            "commands": commands,
        },
    ]

    if attempt >= max_attempts:
        escalation = HumanEscalation(
            reason=f"Stopped after {attempt} failed repair attempts.",
            triggers=["max_repair_attempts", *commands],
            requested_action="Inspect the verification failures and repair manually before resuming.",
            resumable=True,
        )
        return {
            "attempt_count": attempt,
            "repair_actions": repair_actions,
            "repair_history": repair_history,
            "human_escalations": [*state.get("human_escalations", []), escalation.model_dump(mode="json")],
            "human_action_required": True,
            "human_action_reason": escalation.reason,
            "status": "blocked",
            "final_status": "blocked",
            "node_log": ["repair:escalated"],
        }

    return {
        "attempt_count": attempt,
        "repair_actions": repair_actions,
        "repair_history": repair_history,
        "status": "repair_ready",
        "final_status": "repair_ready",
        "node_log": ["repair"],
    }


def collect_verification_failures(results: object) -> list[VerificationFailure]:
    if not isinstance(results, list):
        return []

    failures: list[VerificationFailure] = []
    for result in results:
        if not isinstance(result, dict) or int(result.get("exit_code", 0)) == 0:
            continue
        command = str(result.get("command", ""))
        cwd = str(result.get("cwd", ""))
        stdout = str(result.get("stdout", ""))
        stderr = str(result.get("stderr", ""))
        failures.append(
            VerificationFailure(
                command=command,
                cwd=cwd,
                exit_code=int(result.get("exit_code", 1)),
                stdout_excerpt=_excerpt(stdout),
                stderr_excerpt=_excerpt(stderr),
                failure_category=_failure_category(command, stdout, stderr),
                summary=_failure_summary(command, stdout, stderr),
            )
        )
    return failures


def _excerpt(value: str, limit: int = 1000) -> str:
    normalized = value.strip()
    if len(normalized) <= limit:
        return normalized
    return normalized[:limit].rstrip() + "\n[truncated]"


def _failure_category(command: str, stdout: str, stderr: str) -> str:
    lowered = f"{command}\n{stdout}\n{stderr}".lower()
    if "php artisan test" in lowered:
        return "phpunit"
    if "pint" in lowered:
        return "formatting"
    if "npm run build" in lowered:
        return "frontend_build"
    if "flutter analyze" in lowered:
        return "flutter_analyze"
    if "flutter test" in lowered:
        return "flutter_test"
    if "git diff --check" in lowered:
        return "diff_check"
    return "command_failed"


def _failure_summary(command: str, stdout: str, stderr: str) -> str:
    source = stderr.strip() or stdout.strip()
    first_line = source.splitlines()[0] if source else "Command exited with a non-zero status."
    return f"{command or 'verification command'} failed: {first_line}"


def _repair_reason(failures: object) -> str:
    if isinstance(failures, list) and failures:
        categories = sorted(
            {
                str(failure.get("failure_category", "command_failed"))
                for failure in failures
                if isinstance(failure, dict)
            }
        )
        return f"Verification failed in categories: {', '.join(categories)}."
    return "Verification failed; retry bounded verification after manual or deterministic repair."


def _recommended_repair_tier(assessment: dict[str, object]) -> str:
    if assessment.get("needs_security_review") or assessment.get("risk") in {"high", "critical"}:
        return "strong"
    return "standard"
