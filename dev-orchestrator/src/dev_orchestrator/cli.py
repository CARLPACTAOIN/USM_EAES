from __future__ import annotations

import json
import uuid
from pathlib import Path
from typing import Annotated

import typer
from rich.console import Console
from rich.table import Table

from dev_orchestrator.artifacts import (
    read_json_artifact,
    read_run_checkpoint,
    task_run_dir,
    write_json_artifact,
    write_run_checkpoint,
)
from dev_orchestrator.chat_bridge import continue_chat_task, parse_chat_approval, start_chat_task
from dev_orchestrator.config import OrchestratorConfig, find_repo_root
from dev_orchestrator.graph.graph import build_graph, initial_state
from dev_orchestrator.logging import read_run_log, write_run_log
from dev_orchestrator.models.chat import ChatApprovalDecision, ChatApprovalDecisionType, ChatApprovalTarget, ChatRunRequest
from dev_orchestrator.models.execution import ApprovalDecision, ApprovalDecisionType, ApprovalTarget, PatchProposal
from dev_orchestrator.models.reliability import HumanEscalation
from dev_orchestrator.nodes.repair import diagnose_node
from dev_orchestrator.nodes.reviewer import reviewer_node
from dev_orchestrator.nodes.triage import assess_task
from dev_orchestrator.nodes.verifier import verifier_node
from dev_orchestrator.tools.git import detect_changed_files
from dev_orchestrator.tools.context import build_context_packet
from dev_orchestrator.tools.patches import apply_patch_proposal
from dev_orchestrator.tools.prd import targeted_prd_context
from dev_orchestrator.tools.repository import detect_available_tooling
from dev_orchestrator.tools.skills import select_skills

app = typer.Typer(help="EAES LangGraph development orchestrator.")
console = Console()


@app.command()
def assess(
    task: Annotated[str, typer.Argument(help="Development task to assess.")],
    repo: Annotated[Path | None, typer.Option(help="Repository root. Defaults to auto-detection.")] = None,
    show_context: Annotated[bool, typer.Option(help="Show the selected context packet summary.")] = False,
    context_budget: Annotated[int, typer.Option(help="Maximum context packet character budget.")] = 16000,
) -> None:
    repo_root = find_repo_root(repo)
    skills = select_skills(repo_root, task)
    context = {"applicable_skills": skills}
    assessment = assess_task(task, context)

    table = Table(title="Task Assessment")
    table.add_column("Field")
    table.add_column("Value")
    for key, value in assessment.model_dump(mode="json").items():
        table.add_row(key, str(value))
    console.print(table)
    if show_context:
        packet = build_context_packet(repo_root, task, context_budget)
        _print_context_summary(packet.model_dump(mode="json"))


@app.command()
def task(
    task: Annotated[str, typer.Argument(help="Development task to orchestrate.")],
    execute: Annotated[bool, typer.Option(help="Allow guarded command execution.")] = False,
    repo: Annotated[Path | None, typer.Option(help="Repository root. Defaults to auto-detection.")] = None,
    context_budget: Annotated[int, typer.Option(help="Maximum context packet character budget.")] = 16000,
    max_repair_attempts: Annotated[
        int | None, typer.Option(help="Maximum bounded repair attempts before human escalation.")
    ] = None,
    agent: Annotated[str | None, typer.Option(help="Patch proposal agent/provider to use, e.g. codex or mock.")] = None,
    no_auto_apply: Annotated[bool, typer.Option(help="Disable automatic low-risk patch application.")] = False,
    agent_timeout: Annotated[int | None, typer.Option(help="Agent invocation timeout in seconds.")] = None,
    as_json: Annotated[bool, typer.Option("--json", help="Print the final state as JSON.")] = False,
) -> None:
    repo_root = find_repo_root(repo)
    config = OrchestratorConfig.from_env(repo_root)
    repair_attempts = max_repair_attempts if max_repair_attempts is not None else config.max_repair_attempts
    task_id = f"task-{uuid.uuid4().hex[:12]}"
    graph = build_graph(config)
    state = initial_state(
        task,
        repo_root,
        execute,
        repair_attempts,
        task_id,
        context_budget,
        config.runs_dir,
        agent or config.provider,
        not no_auto_apply,
        agent_timeout if agent_timeout is not None else config.agent_timeout_seconds,
    )
    graph_config = {"configurable": {"thread_id": task_id}}

    result = graph.invoke(state, config=graph_config)
    _apply_interrupt_escalation(result)
    result = _maybe_auto_apply(repo_root, config, task_id, result)
    result["current_worktree_changed_files"] = detect_changed_files(repo_root)
    _write_state_artifact(config.runs_dir, task_id, result)
    log_path = write_run_log(
        config.runs_dir,
        task_id,
        {
            "task_id": task_id,
            "task": task,
            "execute": execute,
            "repo_root": str(repo_root),
            "result": result,
        },
    )

    if as_json:
        typer.echo(json.dumps(result, indent=2, sort_keys=True, default=str))
        return

    _print_run_summary(task_id, result, log_path)


@app.command()
def resume(
    task_id: Annotated[str, typer.Argument(help="Task id to inspect/resume.")],
    repo: Annotated[Path | None, typer.Option(help="Repository root. Defaults to auto-detection.")] = None,
    approve_checkpoint: Annotated[bool, typer.Option(help="Approve a pending critical checkpoint.")] = False,
    approve_patch: Annotated[bool, typer.Option(help="Apply an approved saved patch proposal.")] = False,
    reject: Annotated[str | None, typer.Option(help="Reject a pending checkpoint or patch with a reason.")] = None,
    as_json: Annotated[bool, typer.Option("--json", help="Print the resumed state as JSON.")] = False,
) -> None:
    repo_root = find_repo_root(repo)
    config = OrchestratorConfig.from_env(repo_root)
    result = _resume_task(repo_root, config, task_id, approve_checkpoint, approve_patch, reject)
    log_path = write_run_log(
        config.runs_dir,
        task_id,
        {
            "task_id": task_id,
            "task": result.get("task"),
            "execute": result.get("execute", False),
            "repo_root": str(repo_root),
            "result": result,
        },
    )
    if as_json:
        typer.echo(json.dumps(result, indent=2, sort_keys=True, default=str))
        return
    _print_run_summary(task_id, result, log_path)


@app.command("context")
def context_command(
    task: Annotated[str, typer.Argument(help="Development task to inspect context selection for.")],
    repo: Annotated[Path | None, typer.Option(help="Repository root. Defaults to auto-detection.")] = None,
    as_json: Annotated[bool, typer.Option("--json", help="Print the full context packet as JSON.")] = False,
    context_budget: Annotated[int, typer.Option(help="Maximum context packet character budget.")] = 16000,
) -> None:
    repo_root = find_repo_root(repo)
    packet = build_context_packet(repo_root, task, context_budget)
    data = packet.model_dump(mode="json")
    if as_json:
        typer.echo(json.dumps(data, indent=2, sort_keys=True))
        return

    console.print(
        {
            "tooling": detect_available_tooling(repo_root),
            "skills": [skill["name"] for skill in data["skills"]],
            "prd_context_files": list(targeted_prd_context(repo_root, task).keys()),
            "sources": data["sources"],
            "skipped": data["skipped"],
            "used_chars": data["used_chars"],
        }
    )


def _print_run_summary(task_id: str, result: dict[str, object], log_path: Path) -> None:
    assessment = result.get("assessment", {})
    findings = result.get("structured_review_findings", [])
    failures = result.get("verification_failures", [])
    escalations = result.get("human_escalations", [])
    pending = result.get("pending_approvals", [])
    proposal_paths = result.get("proposal_paths", {})
    apply_result = result.get("patch_apply_result", {})
    agent_invocation = result.get("agent_invocation", {})
    auto_apply_decision = result.get("auto_apply_decision", {})
    console.print(f"[bold]Task ID:[/bold] {task_id}")
    if isinstance(assessment, dict):
        console.print(f"[bold]Complexity:[/bold] {assessment.get('complexity')}")
        console.print(f"[bold]Risk:[/bold] {assessment.get('risk')}")
        console.print(f"[bold]Workflow:[/bold] {assessment.get('recommended_workflow')}")
        console.print(f"[bold]Model tier:[/bold] {assessment.get('recommended_model_tier')}")
        console.print(f"[bold]Skills:[/bold] {', '.join(assessment.get('relevant_skills', [])) or 'none'}")
    console.print(f"[bold]Final status:[/bold] {result.get('final_status', result.get('status', 'unknown'))}")
    console.print(f"[bold]Status:[/bold] {result.get('status', 'unknown')}")
    if isinstance(pending, list) and pending:
        console.print(f"[bold]Pending approvals:[/bold] {', '.join(str(item) for item in pending)}")
    if isinstance(proposal_paths, dict) and proposal_paths:
        console.print(f"[bold]Proposal files:[/bold] {proposal_paths}")
    if isinstance(apply_result, dict) and apply_result:
        console.print(f"[bold]Patch applied:[/bold] {apply_result.get('applied')}")
        if apply_result.get("failure_reason"):
            console.print(f"[bold]Patch failure:[/bold] {apply_result.get('failure_reason')}")
    if isinstance(agent_invocation, dict) and agent_invocation:
        console.print(
            f"[bold]Agent:[/bold] {agent_invocation.get('provider', 'unknown')} / {agent_invocation.get('parse_status', 'unknown')}"
        )
    if isinstance(auto_apply_decision, dict) and auto_apply_decision:
        console.print(f"[bold]Auto apply:[/bold] {auto_apply_decision.get('applied')} ({auto_apply_decision.get('reason')})")
    if isinstance(findings, list) and findings:
        console.print(f"[bold]Review findings:[/bold] {_finding_counts(findings)}")
    else:
        console.print("[bold]Review findings:[/bold] none")
    console.print(f"[bold]Repair attempts:[/bold] {result.get('attempt_count', 0)}")
    if isinstance(escalations, list) and escalations:
        first = escalations[0] if isinstance(escalations[0], dict) else {}
        console.print(f"[bold]Human escalation:[/bold] {first.get('reason', 'required')}")
    if isinstance(failures, list) and failures:
        failed_commands = [
            str(failure.get("command"))
            for failure in failures
            if isinstance(failure, dict) and failure.get("command")
        ]
        console.print(f"[bold]Failed commands:[/bold] {failed_commands}")
    console.print(f"[bold]Verification:[/bold] {result.get('verification_commands', [])}")
    model_calls = result.get("model_calls", [])
    if isinstance(model_calls, list) and model_calls:
        selected = model_calls[-1] if isinstance(model_calls[-1], dict) else {}
        console.print(
            f"[bold]Selected model:[/bold] {selected.get('model', 'unknown')} / {selected.get('reasoning', 'unknown')}"
        )
    console.print(f"[bold]Context sources:[/bold] {len(result.get('context_sources', []))}")
    console.print(f"[bold]Run log:[/bold] {log_path}")


def _apply_interrupt_escalation(result: dict[str, object]) -> None:
    interrupts = result.get("__interrupt__")
    if not interrupts:
        return

    reason = "LangGraph human checkpoint interrupted execution."
    result["human_action_required"] = True
    result["human_action_reason"] = reason
    result["status"] = "awaiting_human"
    result["final_status"] = "awaiting_human"
    result["pending_approvals"] = ["checkpoint"]
    existing = result.get("human_escalations", [])
    if not isinstance(existing, list):
        existing = []
    result["human_escalations"] = [
        *existing,
        {
            "reason": reason,
            "triggers": ["langgraph_interrupt", "human_checkpoint"],
            "requested_action": "Approve, reject, or revise the task scope before resuming.",
            "resumable": True,
        },
    ]


def _write_state_artifact(runs_dir: Path, task_id: str, result: dict[str, object]) -> None:
    run_dir = task_run_dir(runs_dir, task_id)
    phase = str(result.get("final_status", result.get("status", "started")))
    pending = result.get("pending_approvals", [])
    proposal_paths = result.get("proposal_paths", {})
    write_run_checkpoint(
        run_dir,
        task_id,
        _checkpoint_phase(phase),
        result,
        pending_approvals=[str(item) for item in pending] if isinstance(pending, list) else [],
        proposal_paths=proposal_paths if isinstance(proposal_paths, dict) else {},
        resume_hints=_resume_hints(result),
    )


def _resume_task(
    repo_root: Path,
    config: OrchestratorConfig,
    task_id: str,
    approve_checkpoint: bool,
    approve_patch: bool,
    reject: str | None,
) -> dict[str, object]:
    run_dir = task_run_dir(config.runs_dir, task_id)
    if not (run_dir / "state.json").exists():
        log = read_run_log(config.runs_dir, task_id)
        result = log.get("result", {})
        if not isinstance(result, dict):
            raise RuntimeError(f"Run log for {task_id} does not contain an inspectable result.")
        return result

    checkpoint = read_run_checkpoint(run_dir)
    state = checkpoint.saved_state
    if reject is not None:
        target = "patch" if "patch" in checkpoint.pending_approvals else "checkpoint"
        decision = ApprovalDecision(
            task_id=task_id,
            decision_type=ApprovalDecisionType.Reject,
            target=ApprovalTarget(target),
            reason=reject,
        )
        result = _reject_state(state, decision)
        _write_state_artifact(config.runs_dir, task_id, result)
        return result

    if approve_checkpoint:
        state["human_checkpoint_approved"] = True
        state["human_action_required"] = False
        state["human_action_reason"] = ""
        state["status"] = "checkpoint_approved"
        state["final_status"] = "checkpoint_approved"
        state["pending_approvals"] = []
        graph = build_graph(config)
        result = graph.invoke(state, config={"configurable": {"thread_id": task_id}})
        _apply_interrupt_escalation(result)
        result = _maybe_auto_apply(repo_root, config, task_id, result)
        result["current_worktree_changed_files"] = detect_changed_files(repo_root)
        _write_state_artifact(config.runs_dir, task_id, result)
        return result

    if approve_patch:
        result = _approve_patch(repo_root, config, task_id, checkpoint.saved_state)
        _write_state_artifact(config.runs_dir, task_id, result)
        return result

    return checkpoint.saved_state


def _maybe_auto_apply(
    repo_root: Path,
    config: OrchestratorConfig,
    task_id: str,
    result: dict[str, object],
) -> dict[str, object]:
    decision = _auto_apply_decision(result)
    result["auto_apply_decision"] = decision
    if not decision["should_apply"]:
        return result
    applied = _approve_patch(repo_root, config, task_id, result)
    apply_result = applied.get("patch_apply_result", {})
    applied["auto_apply_decision"] = {
        **decision,
        "attempted": True,
        "applied": bool(isinstance(apply_result, dict) and apply_result.get("applied")),
    }
    return applied


def _auto_apply_decision(result: dict[str, object]) -> dict[str, object]:
    if result.get("final_status") != "patch_approval_required":
        return {"should_apply": False, "attempted": False, "applied": False, "reason": "No patch is waiting for approval."}
    if not result.get("auto_apply", True):
        return {"should_apply": False, "attempted": False, "applied": False, "reason": "Auto-apply disabled by CLI option."}
    if result.get("human_action_required"):
        return {
            "should_apply": False,
            "attempted": False,
            "applied": False,
            "reason": "Human action is required before patch application.",
        }
    proposal = result.get("patch_proposal", {})
    if not isinstance(proposal, dict) or not str(proposal.get("unified_diff", "")).strip():
        return {"should_apply": False, "attempted": False, "applied": False, "reason": "Patch proposal has no diff."}

    assessment = result.get("assessment", {})
    if not isinstance(assessment, dict):
        return {"should_apply": False, "attempted": False, "applied": False, "reason": "Assessment is missing."}
    if assessment.get("risk") != "low":
        return {
            "should_apply": False,
            "attempted": False,
            "applied": False,
            "reason": f"Risk is {assessment.get('risk')}; approval required.",
        }
    if assessment.get("recommended_workflow") not in {"trivial", "simple"}:
        return {
            "should_apply": False,
            "attempted": False,
            "applied": False,
            "reason": f"Workflow is {assessment.get('recommended_workflow')}; approval required.",
        }
    if any(
        rule in {"schema-or-database", "dependency-change", "deployment-or-queue", "critical-human-checkpoint"}
        for rule in assessment.get("triggered_rules", [])
    ):
        return {"should_apply": False, "attempted": False, "applied": False, "reason": "Risk rule requires approval."}
    return {
        "should_apply": True,
        "attempted": False,
        "applied": False,
        "reason": "Low-risk simple task eligible for auto-apply.",
    }


def _approve_patch(
    repo_root: Path,
    config: OrchestratorConfig,
    task_id: str,
    state: dict[str, object],
) -> dict[str, object]:
    run_dir = task_run_dir(config.runs_dir, task_id)
    proposal_payload = state.get("patch_proposal")
    if not isinstance(proposal_payload, dict):
        proposal_payload = read_json_artifact(run_dir, "proposal.json")
    proposal = PatchProposal.model_validate(proposal_payload)
    repository_context = state.get("repository_context", {})
    baseline = []
    if isinstance(repository_context, dict):
        baseline = [str(path) for path in repository_context.get("baseline_changed_files", [])]

    apply_result = apply_patch_proposal(repo_root, run_dir, run_dir / "proposal.patch", proposal, baseline)
    result: dict[str, object] = {
        **state,
        "patch_apply_result": apply_result.model_dump(mode="json"),
        "pending_approvals": [],
        "human_action_required": False,
        "human_action_reason": "",
    }
    write_json_artifact(run_dir, "apply.json", apply_result.model_dump(mode="json"))

    if not apply_result.applied:
        result.update({"status": "patch_apply_failed", "final_status": "blocked"})
        return result

    result["changed_files"] = apply_result.changed_files
    verification = verifier_node(result)
    result.update(verification)
    write_json_artifact(run_dir, "verification.json", verification)
    failed = any(
        isinstance(item, dict) and item.get("exit_code", 0) != 0
        for item in verification.get("verification_results", [])
        if isinstance(verification, dict)
    )
    if failed:
        result.update(diagnose_node(result))
    review = reviewer_node(result)
    result.update(review)
    write_json_artifact(run_dir, "review.json", review)
    if not result.get("human_action_required"):
        result["status"] = "completed"
        result["final_status"] = "completed"
    return result


def _reject_state(state: dict[str, object], decision: ApprovalDecision) -> dict[str, object]:
    escalation = HumanEscalation(
        reason=decision.reason or "Approval was rejected.",
        triggers=[f"{decision.target.value}_rejected"],
        requested_action="Revise the task scope or proposal before resuming.",
        resumable=True,
    )
    return {
        **state,
        "approval_decision": decision.model_dump(mode="json"),
        "human_action_required": True,
        "human_action_reason": escalation.reason,
        "human_escalations": [*state.get("human_escalations", []), escalation.model_dump(mode="json")],
        "pending_approvals": [],
        "status": "rejected",
        "final_status": "rejected",
    }


def _checkpoint_phase(status: str) -> str:
    if status in {"awaiting_human"}:
        return "checkpoint_pending"
    if status in {"proposal_pending"}:
        return "proposal_pending"
    if status in {"patch_approval_required"}:
        return "patch_pending"
    if status in {"blocked", "rejected", "patch_apply_failed"}:
        return "blocked"
    if status in {"completed", "reviewed"}:
        return "completed"
    return "started"


def _resume_hints(result: dict[str, object]) -> list[str]:
    pending = result.get("pending_approvals", [])
    if pending == ["patch"]:
        return ["Inspect proposal.patch, then resume with --approve-patch or --reject REASON."]
    if result.get("human_action_required"):
        return ["Resume with --approve-checkpoint or --reject REASON."]
    if result.get("status") == "proposal_pending":
        return ["Create proposal.json and proposal.patch from agent_prompt.md, then resume with --approve-patch."]
    return []


def _finding_counts(findings: list[object]) -> str:
    counts: dict[str, int] = {}
    for finding in findings:
        if not isinstance(finding, dict):
            continue
        severity = str(finding.get("severity", "unknown"))
        counts[severity] = counts.get(severity, 0) + 1
    return ", ".join(f"{severity}={count}" for severity, count in sorted(counts.items())) or "none"


def _print_context_summary(packet: dict[str, object]) -> None:
    skills = packet.get("skills", [])
    console.print("[bold]Context Packet[/bold]")
    console.print(f"Selected files: {len(packet.get('selected_files', []))}")
    console.print(f"Sources: {len(packet.get('sources', []))}")
    console.print(f"Used chars: {packet.get('used_chars')} / {packet.get('budget_chars')}")
    if isinstance(skills, list):
        console.print(f"Skills: {', '.join(str(skill.get('name')) for skill in skills if isinstance(skill, dict)) or 'none'}")
    console.print(f"Skipped: {packet.get('skipped', [])}")


@app.command("chat-start")
def chat_start(
    task: Annotated[str, typer.Argument(help="Development task to start through the chat bridge.")],
    repo: Annotated[Path | None, typer.Option(help="Repository root. Defaults to auto-detection.")] = None,
    execute: Annotated[bool, typer.Option(help="Allow guarded proposal generation.")] = True,
    agent: Annotated[str, typer.Option(help="Patch proposal agent/provider.")] = "codex",
    no_auto_apply: Annotated[bool, typer.Option(help="Disable automatic low-risk patch application.")] = False,
    context_budget: Annotated[int, typer.Option(help="Maximum context packet character budget.")] = 16000,
    agent_timeout: Annotated[int | None, typer.Option(help="Agent invocation timeout in seconds.")] = None,
    as_json: Annotated[bool, typer.Option("--json", help="Print chat summary as JSON.")] = False,
) -> None:
    repo_root = find_repo_root(repo)
    config = OrchestratorConfig.from_env(repo_root)
    summary = start_chat_task(
        repo_root,
        config,
        ChatRunRequest(
            task=task,
            execute=execute,
            agent=agent,
            auto_apply=not no_auto_apply,
            context_budget=context_budget,
            timeout=agent_timeout,
        ),
    )
    data = summary.model_dump(mode="json")
    if as_json:
        typer.echo(json.dumps(data, indent=2, sort_keys=True, default=str))
        return
    _print_chat_summary(data)


@app.command("chat-approve")
def chat_approve(
    task_id: Annotated[str, typer.Argument(help="Task id with a pending chat approval.")],
    repo: Annotated[Path | None, typer.Option(help="Repository root. Defaults to auto-detection.")] = None,
    target: Annotated[str | None, typer.Option(help="Approval target: patch or checkpoint.")] = None,
    text: Annotated[str, typer.Option(help="Raw chat approval text to parse.")] = "approve",
    as_json: Annotated[bool, typer.Option("--json", help="Print chat summary as JSON.")] = False,
) -> None:
    repo_root = find_repo_root(repo)
    config = OrchestratorConfig.from_env(repo_root)
    pending = _pending_targets(config, task_id)
    decision = parse_chat_approval(task_id, f"approve {target}" if target else text, pending)
    summary = continue_chat_task(repo_root, config, decision)
    data = summary.model_dump(mode="json")
    if as_json:
        typer.echo(json.dumps(data, indent=2, sort_keys=True, default=str))
        return
    _print_chat_summary(data)


@app.command("chat-reject")
def chat_reject(
    task_id: Annotated[str, typer.Argument(help="Task id with a pending chat approval.")],
    reason: Annotated[str, typer.Argument(help="Reason for rejection.")],
    repo: Annotated[Path | None, typer.Option(help="Repository root. Defaults to auto-detection.")] = None,
    target: Annotated[str | None, typer.Option(help="Approval target: patch or checkpoint.")] = None,
    as_json: Annotated[bool, typer.Option("--json", help="Print chat summary as JSON.")] = False,
) -> None:
    repo_root = find_repo_root(repo)
    config = OrchestratorConfig.from_env(repo_root)
    pending = _pending_targets(config, task_id)
    if target:
        pending = [target]
    decision = parse_chat_approval(task_id, f"reject {reason}", pending)
    if target and decision.decision == ChatApprovalDecisionType.Reject:
        decision = ChatApprovalDecision(
            task_id=task_id,
            decision=decision.decision,
            target=ChatApprovalTarget(target),
            reason=decision.reason,
            parsed_source_text=decision.parsed_source_text,
        )
    summary = continue_chat_task(repo_root, config, decision)
    data = summary.model_dump(mode="json")
    if as_json:
        typer.echo(json.dumps(data, indent=2, sort_keys=True, default=str))
        return
    _print_chat_summary(data)


def _pending_targets(config: OrchestratorConfig, task_id: str) -> list[str]:
    checkpoint = read_run_checkpoint(task_run_dir(config.runs_dir, task_id))
    return [str(target) for target in checkpoint.pending_approvals]


def _print_chat_summary(summary: dict[str, object]) -> None:
    console.print(f"[bold]Task ID:[/bold] {summary.get('task_id')}")
    console.print(f"[bold]Status:[/bold] {summary.get('final_status')}")
    console.print(str(summary.get("short_message", "")))
    approval = summary.get("approval_required")
    if isinstance(approval, dict):
        console.print(f"[bold]Approval:[/bold] {approval.get('target')} - {approval.get('recommended_action')}")
        if approval.get("affected_files"):
            console.print(f"[bold]Affected files:[/bold] {approval.get('affected_files')}")
        if approval.get("proposal_paths"):
            console.print(f"[bold]Proposal files:[/bold] {approval.get('proposal_paths')}")
    console.print(f"[bold]Verification:[/bold] {summary.get('verification_summary')}")
    console.print(f"[bold]Review:[/bold] {summary.get('review_summary')}")


if __name__ == "__main__":
    app()
