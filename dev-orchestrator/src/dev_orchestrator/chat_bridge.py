from __future__ import annotations

import uuid
from pathlib import Path

from dev_orchestrator.config import OrchestratorConfig
from dev_orchestrator.graph.graph import build_graph, initial_state
from dev_orchestrator.logging import write_run_log
from dev_orchestrator.models.chat import (
    ChatApprovalDecision,
    ChatApprovalDecisionType,
    ChatApprovalRequest,
    ChatApprovalTarget,
    ChatRunRequest,
    ChatRunSummary,
)
from dev_orchestrator.tools.git import detect_changed_files


APPROVE_WORDS = {"approve", "approved", "yes", "y", "go", "apply", "ok", "okay"}


def start_chat_task(repo_root: Path, config: OrchestratorConfig, request: ChatRunRequest) -> ChatRunSummary:
    from dev_orchestrator.cli import _apply_interrupt_escalation, _maybe_auto_apply, _write_state_artifact

    task_id = f"task-{uuid.uuid4().hex[:12]}"
    graph = build_graph(config)
    state = initial_state(
        request.task,
        repo_root,
        request.execute,
        config.max_repair_attempts,
        task_id,
        request.context_budget,
        config.runs_dir,
        request.agent,
        request.auto_apply,
        request.timeout if request.timeout is not None else config.agent_timeout_seconds,
    )
    result = graph.invoke(state, config={"configurable": {"thread_id": task_id}})
    _apply_interrupt_escalation(result)
    result = _maybe_auto_apply(repo_root, config, task_id, result)
    result["current_worktree_changed_files"] = detect_changed_files(repo_root)
    _write_state_artifact(config.runs_dir, task_id, result)
    _write_chat_log(config, task_id, request.task, repo_root, result)
    return summarize_for_chat(result)


def continue_chat_task(
    repo_root: Path,
    config: OrchestratorConfig,
    decision: ChatApprovalDecision,
) -> ChatRunSummary:
    from dev_orchestrator.cli import _resume_task

    if decision.decision == ChatApprovalDecisionType.NeedsClarification:
        return ChatRunSummary(
            task_id=decision.task_id,
            final_status="needs_clarification",
            short_message="Approval request is ambiguous. Specify approve patch, approve checkpoint, or reject with a reason.",
            needs_clarification=True,
        )

    result = _resume_task(
        repo_root,
        config,
        decision.task_id,
        approve_checkpoint=decision.decision == ChatApprovalDecisionType.Approve
        and decision.target == ChatApprovalTarget.Checkpoint,
        approve_patch=decision.decision == ChatApprovalDecisionType.Approve and decision.target == ChatApprovalTarget.Patch,
        reject=decision.reason if decision.decision == ChatApprovalDecisionType.Reject else None,
    )
    _write_chat_log(config, decision.task_id, str(result.get("task", "")), repo_root, result)
    return summarize_for_chat(result)


def summarize_for_chat(state: dict[str, object]) -> ChatRunSummary:
    task_id = str(state.get("task_id", ""))
    final_status = str(state.get("final_status", state.get("status", "unknown")))
    changed_files = [str(path) for path in state.get("changed_files", []) if str(path)]
    approval = _approval_request(state, task_id)
    return ChatRunSummary(
        task_id=task_id,
        final_status=final_status,
        short_message=_short_message(state, final_status, approval),
        changed_files=changed_files,
        verification_summary=_verification_summary(state),
        review_summary=_review_summary(state),
        approval_required=approval,
    )


def parse_chat_approval(task_id: str, text: str, pending_targets: list[str]) -> ChatApprovalDecision:
    normalized = " ".join(text.strip().lower().split())
    if not normalized:
        return _clarify(task_id, text)

    if normalized.startswith("reject"):
        reason = normalized.removeprefix("reject").strip() or "Rejected from chat."
        target = _single_or_named_target(normalized, pending_targets)
        return ChatApprovalDecision(
            task_id=task_id,
            decision=ChatApprovalDecisionType.Reject,
            target=target,
            reason=reason,
            parsed_source_text=text,
        )

    if normalized in APPROVE_WORDS:
        target = _only_pending_target(pending_targets)
        if target is None:
            return _clarify(task_id, text)
        return ChatApprovalDecision(
            task_id=task_id,
            decision=ChatApprovalDecisionType.Approve,
            target=target,
            parsed_source_text=text,
        )

    if normalized in {"approve patch", "apply patch"}:
        return ChatApprovalDecision(
            task_id=task_id,
            decision=ChatApprovalDecisionType.Approve,
            target=ChatApprovalTarget.Patch,
            parsed_source_text=text,
        )

    if normalized in {"approve checkpoint", "approve critical", "go checkpoint"}:
        return ChatApprovalDecision(
            task_id=task_id,
            decision=ChatApprovalDecisionType.Approve,
            target=ChatApprovalTarget.Checkpoint,
            parsed_source_text=text,
        )

    return _clarify(task_id, text)


def _approval_request(state: dict[str, object], task_id: str) -> ChatApprovalRequest | None:
    pending = [str(item) for item in state.get("pending_approvals", [])]
    if "checkpoint" in pending:
        return ChatApprovalRequest(
            task_id=task_id,
            target=ChatApprovalTarget.Checkpoint,
            recommended_action="Approve checkpoint to let Codex generate a proposal, or reject the task.",
            risk_reason=str(state.get("human_action_reason", "Critical workflow requires approval.")),
        )
    if "patch" in pending:
        proposal = state.get("patch_proposal", {})
        affected_files = proposal.get("affected_files", []) if isinstance(proposal, dict) else []
        return ChatApprovalRequest(
            task_id=task_id,
            target=ChatApprovalTarget.Patch,
            recommended_action="Approve patch to apply and verify it, or reject with a reason.",
            risk_reason=_risk_reason(state),
            affected_files=[str(path) for path in affected_files],
            proposal_paths=state.get("proposal_paths", {}) if isinstance(state.get("proposal_paths", {}), dict) else {},
        )
    return None


def _short_message(state: dict[str, object], final_status: str, approval: ChatApprovalRequest | None) -> str:
    if approval is not None:
        return f"Approval required for {approval.target.value}: {approval.recommended_action}"
    if final_status == "completed":
        return "Task completed, verified, and reviewed."
    if final_status == "proposal_pending":
        return "Agent proposal is pending; inspect the generated prompt or agent failure artifact."
    if final_status == "rejected":
        return f"Task rejected: {state.get('human_action_reason', 'No reason provided.')}"
    return f"Task ended with status: {final_status}."


def _verification_summary(state: dict[str, object]) -> str:
    commands = state.get("verification_commands", [])
    failures = state.get("verification_failures", [])
    if failures:
        return f"{len(failures)} verification failure(s)."
    if commands:
        return f"Ran {len(commands)} verification command(s)."
    return "No verification commands ran."


def _review_summary(state: dict[str, object]) -> str:
    findings = state.get("structured_review_findings", [])
    if isinstance(findings, list) and findings:
        return f"{len(findings)} review finding(s)."
    return "No review findings."


def _risk_reason(state: dict[str, object]) -> str:
    auto_apply = state.get("auto_apply_decision", {})
    if isinstance(auto_apply, dict) and auto_apply.get("reason"):
        return str(auto_apply["reason"])
    assessment = state.get("assessment", {})
    if isinstance(assessment, dict):
        return f"Risk={assessment.get('risk')}, workflow={assessment.get('recommended_workflow')}."
    return ""


def _only_pending_target(pending_targets: list[str]) -> ChatApprovalTarget | None:
    normalized = [target for target in pending_targets if target in {"checkpoint", "patch"}]
    if len(normalized) != 1:
        return None
    return ChatApprovalTarget(normalized[0])


def _single_or_named_target(text: str, pending_targets: list[str]) -> ChatApprovalTarget | None:
    if "patch" in text:
        return ChatApprovalTarget.Patch
    if "checkpoint" in text or "critical" in text:
        return ChatApprovalTarget.Checkpoint
    return _only_pending_target(pending_targets)


def _clarify(task_id: str, text: str) -> ChatApprovalDecision:
    return ChatApprovalDecision(
        task_id=task_id,
        decision=ChatApprovalDecisionType.NeedsClarification,
        parsed_source_text=text,
    )


def _write_chat_log(
    config: OrchestratorConfig,
    task_id: str,
    task: str,
    repo_root: Path,
    result: dict[str, object],
) -> None:
    write_run_log(
        config.runs_dir,
        task_id,
        {
            "task_id": task_id,
            "task": task,
            "execute": result.get("execute", False),
            "repo_root": str(repo_root),
            "result": result,
        },
    )
