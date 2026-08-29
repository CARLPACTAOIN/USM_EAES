from __future__ import annotations

from operator import add
from typing import Annotated, Any, TypedDict


class OrchestratorState(TypedDict, total=False):
    task: str
    task_id: str
    execute: bool
    agent: str
    auto_apply: bool
    agent_timeout_seconds: int
    repo_root: str
    run_dir: str
    repository_context: dict[str, Any]
    context_packet: dict[str, Any]
    context_sources: list[str]
    context_budget: int
    applicable_agent_rules: list[str]
    applicable_skills: list[str]
    prd_context: dict[str, str]
    model_reasoning: dict[str, str]
    assessment: dict[str, Any]
    workflow: str
    plan: list[str]
    changed_files: list[str]
    git_diff: str
    verification_commands: list[str]
    verification_results: list[dict[str, Any]]
    mock_verification_result_batches: list[list[dict[str, Any]]]
    patch_proposal: dict[str, Any]
    patch_apply_result: dict[str, Any]
    agent_invocation: dict[str, Any]
    auto_apply_decision: dict[str, Any]
    pending_approvals: list[str]
    proposal_paths: dict[str, str]
    review_findings: list[str]
    structured_review_findings: list[dict[str, Any]]
    verification_failures: list[dict[str, Any]]
    repair_actions: list[dict[str, Any]]
    repair_history: list[dict[str, Any]]
    review_status: str
    human_escalations: list[dict[str, Any]]
    final_status: str
    human_checkpoint_approved: bool
    attempt_count: int
    max_repair_attempts: int
    model_calls: Annotated[list[dict[str, Any]], add]
    token_usage: dict[str, int]
    estimated_cost: float
    node_log: Annotated[list[str], add]
    status: str
    human_action_required: bool
    human_action_reason: str
