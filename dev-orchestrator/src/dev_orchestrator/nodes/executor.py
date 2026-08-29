from __future__ import annotations

from pathlib import Path

from dev_orchestrator.artifacts import (
    read_json_artifact,
    relative_artifact_path,
    write_json_artifact,
    write_run_checkpoint,
    write_text_artifact,
)
from dev_orchestrator.model_client import model_client_for_provider
from dev_orchestrator.models.execution import ExecutionRequest
from dev_orchestrator.models.router import model_for_tier
from dev_orchestrator.models.router import ModelTier
from dev_orchestrator.models.router import Workflow
from dev_orchestrator.tools.git import detect_changed_files


def executor_node(state: dict[str, object], config: object | None = None) -> dict[str, object]:
    assessment = state.get("assessment", {})
    tier = "cheap"
    if isinstance(assessment, dict):
        tier = str(assessment.get("recommended_model_tier", "cheap"))
    critical = isinstance(assessment, dict) and assessment.get("recommended_workflow") == Workflow.Critical.value

    model_call = {}
    if config is not None:
        selection = model_for_tier(config, ModelTier(tier), "executor", critical=critical)
        model_call = selection.model_dump(mode="json")

    if not state.get("execute", False):
        return {
            "status": "dry_run_ready",
            "changed_files": [],
            "model_reasoning": {"executor": model_call.get("reasoning", "")} if model_call else {},
            "model_calls": [model_call] if model_call else [],
            "node_log": ["executor:dry-run"],
        }

    repo_root = Path(str(state["repo_root"]))
    repository_context = state.get("repository_context", {})
    baseline = set()
    if isinstance(repository_context, dict):
        baseline = {str(path) for path in repository_context.get("baseline_changed_files", [])}

    if config is None or "task_id" not in state:
        current_changed = set(detect_changed_files(repo_root))
        return {
            "status": "execution_ready",
            "changed_files": sorted(current_changed - baseline),
            "model_reasoning": {},
            "model_calls": [],
            "node_log": ["executor"],
        }

    run_dir = Path(str(state.get("run_dir", repo_root / "dev-orchestrator" / ".runs" / str(state["task_id"]))))
    context_packet = state.get("context_packet", {})
    allowed_files = _allowed_files(context_packet if isinstance(context_packet, dict) else {}, repository_context)
    request = ExecutionRequest(
        task=str(state["task"]),
        assessment=assessment if isinstance(assessment, dict) else {},
        plan=[str(step) for step in state.get("plan", [])],
        context_packet=context_packet if isinstance(context_packet, dict) else {},
        allowed_files=allowed_files,
        baseline_dirty_files=sorted(baseline),
        model_selection=model_call,
    )
    client = model_client_for_provider(
        str(state.get("agent", getattr(config, "provider", "prompt"))),
        run_dir,
        repo_root=repo_root,
        timeout_seconds=int(state.get("agent_timeout_seconds", getattr(config, "agent_timeout_seconds", 600))),
        codex_command=str(getattr(config, "codex_command", "codex")),
    )
    proposal = client.create_patch_proposal(request)
    agent_invocation = _agent_invocation(run_dir)

    if proposal is None:
        write_run_checkpoint(
            run_dir,
            str(state["task_id"]),
            "proposal_pending",
            state,
            pending_approvals=["external_patch_proposal"],
            proposal_paths={"prompt": "agent_prompt.md"},
            resume_hints=["Generate proposal.json and proposal.patch, then resume with --approve-patch."],
        )
        return {
            "status": "proposal_pending",
            "final_status": "proposal_pending",
            "pending_approvals": ["external_patch_proposal"],
            "proposal_paths": {"prompt": "agent_prompt.md"},
            "agent_invocation": agent_invocation,
            "changed_files": [],
            "model_reasoning": {"executor": model_call.get("reasoning", "")} if model_call else {},
            "model_calls": [model_call] if model_call else [],
            "node_log": ["executor:proposal-pending"],
        }

    proposal_json = write_json_artifact(run_dir, "proposal.json", proposal.model_dump(mode="json"))
    proposal_patch = write_text_artifact(run_dir, "proposal.patch", proposal.unified_diff)
    proposal_paths = {
        "json": relative_artifact_path(run_dir, proposal_json),
        "patch": relative_artifact_path(run_dir, proposal_patch),
    }
    disallowed_files = _disallowed_files(proposal.affected_files, allowed_files)
    checkpoint_state = {
        **state,
        "patch_proposal": proposal.model_dump(mode="json"),
        "proposal_paths": proposal_paths,
        "pending_approvals": ["patch"],
        "agent_invocation": agent_invocation,
    }
    write_run_checkpoint(
        run_dir,
        str(state["task_id"]),
        "patch_pending",
        checkpoint_state,
        pending_approvals=["patch"],
        proposal_paths=proposal_paths,
        resume_hints=["Inspect proposal.patch, then run resume <task-id> --approve-patch or --reject REASON."],
    )

    result = {
        "status": "patch_approval_required",
        "final_status": "patch_approval_required",
        "pending_approvals": ["patch"],
        "patch_proposal": proposal.model_dump(mode="json"),
        "proposal_paths": proposal_paths,
        "agent_invocation": agent_invocation,
        "changed_files": [],
        "model_reasoning": {"executor": model_call.get("reasoning", "")} if model_call else {},
        "model_calls": [model_call] if model_call else [],
        "node_log": ["executor:proposal"],
    }
    if disallowed_files:
        result.update(
            {
                "human_action_required": True,
                "human_action_reason": "Patch proposal touches files outside the selected task context.",
                "human_escalations": [
                    {
                        "reason": "Patch proposal touches files outside the selected task context.",
                        "triggers": ["disallowed_patch_files", *disallowed_files],
                        "requested_action": "Inspect proposal.patch and approve, reject, or narrow task context.",
                        "resumable": True,
                    }
                ],
            }
        )
    return result


def _allowed_files(context_packet: dict[str, object], repository_context: object) -> list[str]:
    allowed: list[str] = []
    selected = context_packet.get("selected_files", [])
    if isinstance(selected, list) and selected:
        allowed.extend(str(path) for path in selected)
    if isinstance(repository_context, dict):
        target_paths = repository_context.get("target_paths", [])
        if isinstance(target_paths, list):
            allowed.extend(str(path) for path in target_paths)
    return list(dict.fromkeys(allowed))


def _agent_invocation(run_dir: Path) -> dict[str, object]:
    try:
        return read_json_artifact(run_dir, "agent_result.json")
    except OSError:
        return {}


def _disallowed_files(affected_files: list[str], allowed_files: list[str]) -> list[str]:
    if not allowed_files:
        return []
    if "." in allowed_files:
        return []
    return [
        path
        for path in affected_files
        if path not in allowed_files and not any(path.startswith(f"{allowed.rstrip('/')}/") for allowed in allowed_files)
    ]
