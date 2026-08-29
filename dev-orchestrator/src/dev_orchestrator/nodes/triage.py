from __future__ import annotations

import re

from dev_orchestrator.graph.routing import normalize_workflow
from dev_orchestrator.models.router import Complexity, ModelTier, Risk, TaskAssessment
from dev_orchestrator.tools.prd import needs_prd_context, needs_progress_audit_review


HIGH_RISK_KEYWORDS = {
    "authentication",
    "authorization",
    "roles",
    "permissions",
    "tenant",
    "tenant isolation",
    "rbac",
    "security",
    "sanctum",
    "oauth",
    "socialite",
}

SCHEMA_KEYWORDS = {
    "migration",
    "schema",
    "database",
    "foreign key",
    "index",
    "postgresql",
    "sqlite",
    "destructive",
}

DEPLOYMENT_KEYWORDS = {"deploy", "deployment", "production", "ci", "pipeline", "supervisor", "redis", "queue"}
DEPENDENCY_KEYWORDS = {"dependency", "dependencies", "composer require", "npm install", "pip install"}
CRITICAL_KEYWORDS = {"drop table", "delete data", "destructive migration", "production deployment"}
TRIVIAL_KEYWORDS = {"label", "copy", "typo", "wording", "rename text", "button text"}
UI_KEYWORDS = {"ui", "ux", "dashboard", "blade", "tailwind", "screen", "layout"}


def triage_node(state: dict[str, object]) -> dict[str, object]:
    assessment = assess_task(str(state["task"]), state)
    return {
        "assessment": assessment.model_dump(mode="json"),
        "workflow": assessment.recommended_workflow.value,
        "node_log": ["triage"],
    }


def assess_task(task: str, state: dict[str, object] | None = None) -> TaskAssessment:
    lowered = task.lower()
    triggered_rules: list[str] = []
    complexity = Complexity.Simple
    risk = Risk.Low
    task_type = "implementation"
    estimated_files = _estimate_files(lowered)
    needs_plan = False
    needs_tests = True
    needs_security_review = False
    needs_architecture_review = False
    needs_research = False
    architecture_impact = False
    cross_cutting_impact = False
    uncertainty = "low"

    if any(keyword in lowered for keyword in TRIVIAL_KEYWORDS):
        task_type = "ui-copy"
        complexity = Complexity.Trivial
        needs_tests = False
        triggered_rules.append("trivial-copy")

    if any(keyword in lowered for keyword in UI_KEYWORDS):
        task_type = "frontend-ui"
        triggered_rules.append("ui-surface")

    if any(keyword in lowered for keyword in HIGH_RISK_KEYWORDS):
        risk = Risk.High
        complexity = max_complexity(complexity, Complexity.Complex)
        needs_plan = True
        needs_security_review = True
        triggered_rules.append("security-or-authorization")

    if any(keyword in lowered for keyword in SCHEMA_KEYWORDS):
        risk = Risk.High if "destructive" in lowered else max_risk(risk, Risk.Medium)
        complexity = max_complexity(complexity, Complexity.Moderate)
        needs_plan = True
        needs_architecture_review = True
        triggered_rules.append("schema-or-database")

    if any(keyword in lowered for keyword in DEPLOYMENT_KEYWORDS):
        risk = max_risk(risk, Risk.Medium)
        complexity = max_complexity(complexity, Complexity.Moderate)
        needs_plan = True
        triggered_rules.append("deployment-or-queue")

    if any(keyword in lowered for keyword in DEPENDENCY_KEYWORDS):
        risk = max_risk(risk, Risk.High)
        complexity = max_complexity(complexity, Complexity.Moderate)
        needs_plan = True
        needs_architecture_review = True
        triggered_rules.append("dependency-change")

    if any(keyword in lowered for keyword in CRITICAL_KEYWORDS):
        risk = Risk.Critical
        complexity = max_complexity(complexity, Complexity.Critical)
        needs_plan = True
        needs_architecture_review = True
        triggered_rules.append("critical-human-checkpoint")

    if any(keyword in lowered for keyword in ["prd", "product requirement", "business rule", "policy"]):
        needs_plan = True
        triggered_rules.append("prd-sensitive")

    if any(keyword in lowered for keyword in ["refactor", "architecture", "cross-platform", "large", "workflow"]):
        complexity = max_complexity(complexity, Complexity.Complex)
        risk = max_risk(risk, Risk.Medium)
        needs_plan = True
        needs_architecture_review = True
        architecture_impact = True
        triggered_rules.append("architecture-impact")

    if estimated_files >= 6:
        complexity = max_complexity(complexity, Complexity.Moderate)
        cross_cutting_impact = True
        needs_plan = True
        triggered_rules.append("multi-file-scope")

    if "?" in task or any(keyword in lowered for keyword in ["unsure", "investigate", "diagnose", "unknown"]):
        uncertainty = "medium"
        needs_plan = True
        triggered_rules.append("uncertainty")

    workflow = normalize_workflow(complexity, risk)
    model_tier = _model_tier_for(complexity, risk)

    relevant_skills = []
    if state:
        skills = state.get("applicable_skills", [])
        if isinstance(skills, list):
            relevant_skills = [str(skill) for skill in skills]

    return TaskAssessment(
        task_type=task_type,
        complexity=complexity,
        risk=risk,
        scope="cross-cutting" if cross_cutting_impact else "single-area",
        uncertainty=uncertainty,
        architecture_impact=architecture_impact,
        cross_cutting_impact=cross_cutting_impact,
        estimated_files=estimated_files,
        needs_plan=needs_plan,
        needs_repository_analysis=True,
        needs_research=needs_research,
        needs_tests=needs_tests,
        needs_architecture_review=needs_architecture_review,
        needs_security_review=needs_security_review,
        recommended_workflow=workflow,
        recommended_model_tier=model_tier,
        reasoning_summary=", ".join(triggered_rules) or "No high-risk deterministic rules triggered.",
        triggered_rules=triggered_rules,
        relevant_skills=relevant_skills,
        needs_prd_context=needs_prd_context(task),
        needs_progress_audit_review=needs_progress_audit_review(task),
    )


def _estimate_files(task: str) -> int:
    explicit = re.search(r"(\d+)\s+files?", task)
    if explicit:
        return int(explicit.group(1))
    if any(keyword in task for keyword in ["migration", "controller", "test"]):
        return 3
    if any(keyword in task for keyword in ["full", "workflow", "cross-platform"]):
        return 8
    return 1


def max_complexity(left: Complexity, right: Complexity) -> Complexity:
    order = list(Complexity)
    return order[max(order.index(left), order.index(right))]


def max_risk(left: Risk, right: Risk) -> Risk:
    order = list(Risk)
    return order[max(order.index(left), order.index(right))]


def _model_tier_for(complexity: Complexity, risk: Risk) -> ModelTier:
    if risk in {Risk.High, Risk.Critical} or complexity in {Complexity.Complex, Complexity.Critical}:
        return ModelTier.Strong
    if risk == Risk.Medium or complexity == Complexity.Moderate:
        return ModelTier.Standard
    return ModelTier.Cheap
