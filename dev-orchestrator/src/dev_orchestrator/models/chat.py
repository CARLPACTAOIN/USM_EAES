from __future__ import annotations

from enum import StrEnum

from pydantic import BaseModel, Field


class ChatApprovalTarget(StrEnum):
    Checkpoint = "checkpoint"
    Patch = "patch"


class ChatApprovalDecisionType(StrEnum):
    Approve = "approve"
    Reject = "reject"
    NeedsClarification = "needs_clarification"


class ChatRunRequest(BaseModel):
    task: str
    execute: bool = True
    agent: str = "codex"
    auto_apply: bool = True
    context_budget: int = 16000
    timeout: int | None = None


class ChatApprovalRequest(BaseModel):
    task_id: str
    target: ChatApprovalTarget
    recommended_action: str
    risk_reason: str = ""
    affected_files: list[str] = Field(default_factory=list)
    proposal_paths: dict[str, str] = Field(default_factory=dict)


class ChatRunSummary(BaseModel):
    task_id: str
    final_status: str
    short_message: str
    changed_files: list[str] = Field(default_factory=list)
    verification_summary: str = ""
    review_summary: str = ""
    approval_required: ChatApprovalRequest | None = None
    needs_clarification: bool = False


class ChatApprovalDecision(BaseModel):
    task_id: str
    decision: ChatApprovalDecisionType
    target: ChatApprovalTarget | None = None
    reason: str = ""
    parsed_source_text: str = ""
