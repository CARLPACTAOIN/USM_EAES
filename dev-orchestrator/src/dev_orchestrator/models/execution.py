from __future__ import annotations

from enum import StrEnum
from typing import Any, Literal

from pydantic import BaseModel, Field


class ApprovalDecisionType(StrEnum):
    Approve = "approve"
    Reject = "reject"


class ApprovalTarget(StrEnum):
    Checkpoint = "checkpoint"
    Patch = "patch"


class ExecutionRequest(BaseModel):
    task: str
    assessment: dict[str, Any] = Field(default_factory=dict)
    plan: list[str] = Field(default_factory=list)
    context_packet: dict[str, Any] = Field(default_factory=dict)
    allowed_files: list[str] = Field(default_factory=list)
    baseline_dirty_files: list[str] = Field(default_factory=list)
    model_selection: dict[str, Any] = Field(default_factory=dict)


class PatchProposal(BaseModel):
    patch_id: str
    summary: str
    affected_files: list[str] = Field(default_factory=list)
    unified_diff: str = ""
    verification_plan: list[str] = Field(default_factory=list)
    risk_notes: list[str] = Field(default_factory=list)
    requires_approval: bool = True


class AgentInvocationResult(BaseModel):
    provider: str
    command: list[str] = Field(default_factory=list)
    exit_code: int | None = None
    stdout_path: str = ""
    stderr_path: str = ""
    events_path: str = ""
    final_message_path: str = ""
    schema_path: str = ""
    timed_out: bool = False
    parse_status: str = "not_run"
    error: str = ""


class PatchApplyResult(BaseModel):
    applied: bool
    git_apply_check: dict[str, Any] = Field(default_factory=dict)
    changed_files: list[str] = Field(default_factory=list)
    failure_reason: str = ""


class ApprovalDecision(BaseModel):
    task_id: str
    decision_type: ApprovalDecisionType
    target: ApprovalTarget
    reason: str = ""


class RunCheckpoint(BaseModel):
    task_id: str
    phase: Literal["started", "checkpoint_pending", "proposal_pending", "patch_pending", "applied", "blocked", "completed"]
    saved_state: dict[str, Any] = Field(default_factory=dict)
    pending_approvals: list[str] = Field(default_factory=list)
    proposal_paths: dict[str, str] = Field(default_factory=dict)
    resume_hints: list[str] = Field(default_factory=list)
