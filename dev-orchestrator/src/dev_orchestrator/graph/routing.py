from __future__ import annotations

from dev_orchestrator.models.router import Complexity, Risk, Workflow


def route_after_triage(state: dict[str, object]) -> str:
    assessment = state.get("assessment", {})
    if (
        isinstance(assessment, dict)
        and assessment.get("recommended_workflow") == Workflow.Critical.value
        and not state.get("human_checkpoint_approved")
    ):
        return "human_checkpoint"
    if isinstance(assessment, dict) and assessment.get("needs_plan"):
        return "planner"
    return "executor"


def route_after_human_checkpoint(state: dict[str, object]) -> str:
    if state.get("human_action_required"):
        return "end"
    return route_after_triage(state)


def route_after_executor(state: dict[str, object]) -> str:
    if state.get("status") in {"proposal_pending", "patch_approval_required", "awaiting_human"}:
        return "end"
    return "verifier"


def route_after_verify(state: dict[str, object]) -> str:
    results = state.get("verification_results", [])
    failed = any(isinstance(result, dict) and result.get("exit_code", 0) != 0 for result in results)
    if failed:
        return "diagnose"

    assessment = state.get("assessment", {})
    if isinstance(assessment, dict) and (
        assessment.get("needs_security_review")
        or assessment.get("needs_architecture_review")
        or assessment.get("recommended_workflow") in {Workflow.Moderate.value, Workflow.Complex.value, Workflow.Critical.value}
    ):
        return "reviewer"

    return "end"


def route_after_reviewer(state: dict[str, object]) -> str:
    if state.get("human_action_required"):
        return "end"
    return "end"


def route_after_repair(state: dict[str, object]) -> str:
    if state.get("human_action_required"):
        return "end"
    return "verifier"


def normalize_workflow(complexity: Complexity, risk: Risk) -> Workflow:
    if risk == Risk.Critical or complexity == Complexity.Critical:
        return Workflow.Critical
    if risk == Risk.High or complexity == Complexity.Complex:
        return Workflow.Complex
    if complexity == Complexity.Moderate or risk == Risk.Medium:
        return Workflow.Moderate
    if complexity == Complexity.Trivial:
        return Workflow.Trivial
    return Workflow.Simple
