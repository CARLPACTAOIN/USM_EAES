from __future__ import annotations

from enum import StrEnum
from typing import Literal

from pydantic import BaseModel, Field


class Complexity(StrEnum):
    Trivial = "trivial"
    Simple = "simple"
    Moderate = "moderate"
    Complex = "complex"
    Critical = "critical"


class Risk(StrEnum):
    Low = "low"
    Medium = "medium"
    High = "high"
    Critical = "critical"


class ModelTier(StrEnum):
    Cheap = "cheap"
    Standard = "standard"
    Strong = "strong"


class Workflow(StrEnum):
    Trivial = "trivial"
    Simple = "simple"
    Moderate = "moderate"
    Complex = "complex"
    Critical = "critical"


class TaskAssessment(BaseModel):
    task_type: str = "unknown"
    complexity: Complexity = Complexity.Simple
    risk: Risk = Risk.Low
    scope: str = "single-area"
    uncertainty: str = "low"
    architecture_impact: bool = False
    cross_cutting_impact: bool = False
    estimated_files: int = 1
    needs_plan: bool = False
    needs_repository_analysis: bool = True
    needs_research: bool = False
    needs_tests: bool = True
    needs_architecture_review: bool = False
    needs_security_review: bool = False
    recommended_workflow: Workflow = Workflow.Simple
    recommended_model_tier: ModelTier = ModelTier.Cheap
    reasoning_summary: str = ""
    triggered_rules: list[str] = Field(default_factory=list)
    relevant_skills: list[str] = Field(default_factory=list)
    needs_prd_context: bool = False
    needs_progress_audit_review: bool = False


class ModelSelection(BaseModel):
    provider: str
    tier: ModelTier
    model: str
    reasoning: str
    phase: Literal["triage", "planner", "executor", "reviewer", "repair"]
    critical_override: bool = False


def model_for_tier(config: object, tier: ModelTier, phase: str, critical: bool = False) -> ModelSelection:
    if critical:
        model = getattr(config, "model_critical")
        reasoning = getattr(config, "reasoning_critical")
    else:
        model = {
            ModelTier.Cheap: getattr(config, "model_cheap"),
            ModelTier.Standard: getattr(config, "model_standard"),
            ModelTier.Strong: getattr(config, "model_strong"),
        }[tier]
        reasoning = {
            ModelTier.Cheap: getattr(config, "reasoning_cheap"),
            ModelTier.Standard: getattr(config, "reasoning_standard"),
            ModelTier.Strong: getattr(config, "reasoning_strong"),
        }[tier]

    return ModelSelection(
        provider=getattr(config, "provider"),
        tier=tier,
        model=model,
        reasoning=reasoning,
        phase=phase,  # type: ignore[arg-type]
        critical_override=critical,
    )
