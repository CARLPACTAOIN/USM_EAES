from __future__ import annotations

from enum import StrEnum

from pydantic import BaseModel, Field


class FindingSeverity(StrEnum):
    Info = "info"
    Low = "low"
    Medium = "medium"
    High = "high"
    Critical = "critical"


class ReviewFinding(BaseModel):
    severity: FindingSeverity
    category: str
    message: str
    evidence: str = ""
    source: str = "reviewer"
    requires_human: bool = False


class VerificationFailure(BaseModel):
    command: str
    cwd: str
    exit_code: int
    stdout_excerpt: str = ""
    stderr_excerpt: str = ""
    failure_category: str = "command_failed"
    summary: str


class RepairAction(BaseModel):
    attempt: int
    action_type: str
    reason: str
    target_commands: list[str] = Field(default_factory=list)
    target_files: list[str] = Field(default_factory=list)
    recommended_model_tier: str = "standard"


class HumanEscalation(BaseModel):
    reason: str
    triggers: list[str] = Field(default_factory=list)
    requested_action: str
    resumable: bool = True

