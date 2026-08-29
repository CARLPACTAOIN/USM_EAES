from dev_orchestrator.models.router import Complexity, ModelTier, Risk
from dev_orchestrator.nodes.triage import assess_task


def test_ui_label_rename_skips_planner_and_uses_cheap_model() -> None:
    assessment = assess_task("Rename dashboard label from Events to Activities")

    assert assessment.complexity == Complexity.Trivial
    assert assessment.risk == Risk.Low
    assert assessment.needs_plan is False
    assert assessment.needs_tests is False
    assert assessment.recommended_model_tier == ModelTier.Cheap


def test_simple_validation_rule_uses_executor_and_tests() -> None:
    assessment = assess_task("Add a simple validation rule to the event proposal form")

    assert assessment.needs_plan is False
    assert assessment.needs_tests is True
    assert assessment.recommended_model_tier == ModelTier.Cheap


def test_database_schema_change_requires_planner() -> None:
    assessment = assess_task("Add a database migration for scanner assignment indexes")

    assert assessment.needs_plan is True
    assert assessment.needs_architecture_review is True
    assert assessment.risk in {Risk.Medium, Risk.High}
    assert "schema-or-database" in assessment.triggered_rules


def test_authorization_change_is_high_risk() -> None:
    assessment = assess_task("Change authorization and tenant permissions for analytics exports")

    assert assessment.risk == Risk.High
    assert assessment.needs_plan is True
    assert assessment.needs_security_review is True
    assert assessment.recommended_model_tier == ModelTier.Strong

