import json

import pytest
from typer.testing import CliRunner

from dev_orchestrator.cli import app
from dev_orchestrator.config import OrchestratorConfig
from dev_orchestrator.graph.graph import build_graph, initial_state
from dev_orchestrator.graph.routing import route_after_triage, route_after_verify
from dev_orchestrator.nodes.executor import executor_node
from dev_orchestrator.nodes.reviewer import review_state
from dev_orchestrator.nodes.triage import assess_task


def _repo(tmp_path):
    (tmp_path / ".git").mkdir()
    (tmp_path / "AGENTS.md").write_text("Agent rules", encoding="utf-8")
    (tmp_path / ".agents" / "skills").mkdir(parents=True)
    (tmp_path / "PRD_V1.md").write_text("# PRD\n\n## Scanner\nRules", encoding="utf-8")
    (tmp_path / "PRD_PROGRESS_AUDIT.md").write_text("# Audit\n\n## Scanner\nStatus", encoding="utf-8")
    return tmp_path


@pytest.mark.filterwarnings("ignore::DeprecationWarning")
def test_low_risk_passing_verification_ends_without_reviewer(tmp_path) -> None:
    pytest.importorskip("langgraph")
    repo = _repo(tmp_path)
    graph = build_graph(OrchestratorConfig.from_env(repo))
    state = initial_state("Rename dashboard label", repo, False, 3, "low-risk")

    result = graph.invoke(state, config={"configurable": {"thread_id": "low-risk"}})

    assert "reviewer" not in result["node_log"]
    assert result["final_status"] == "completed"


def test_moderate_task_routes_to_reviewer_after_passing_verification() -> None:
    route = route_after_verify(
        {
            "verification_results": [{"exit_code": 0}],
            "assessment": {"recommended_workflow": "moderate"},
        }
    )

    assert route == "reviewer"


def test_authorization_task_creates_security_review_finding() -> None:
    assessment = assess_task("Change authorization tenant RBAC for scanner sync")
    findings = review_state(
        {
            "assessment": assessment.model_dump(mode="json"),
            "plan": ["Check policy coverage"],
            "context_packet": {"agents": [{"source": "AGENTS.md"}], "skills": [{"name": "auditing-access-control"}]},
            "applicable_skills": ["auditing-access-control"],
        }
    )

    assert any(finding.category == "security_review_required" for finding in findings)


def test_execute_mode_noop_finding_when_changes_expected() -> None:
    findings = review_state(
        {
            "execute": True,
            "changed_files": [],
            "assessment": {"task_type": "implementation", "complexity": "simple"},
            "context_packet": {"agents": [{"source": "AGENTS.md"}]},
        }
    )

    assert any(finding.category == "execute_noop" for finding in findings)


def test_critical_destructive_task_routes_to_human_checkpoint() -> None:
    assessment = assess_task("Run a destructive migration that may drop table data")

    assert assessment.risk == "critical"
    assert route_after_triage({"assessment": assessment.model_dump(mode="json")}) == "human_checkpoint"


def test_prd_sensitive_task_without_prd_context_creates_finding() -> None:
    assessment = assess_task("Implement PRD scanner business rule")
    findings = review_state(
        {
            "assessment": assessment.model_dump(mode="json"),
            "plan": ["Read PRD"],
            "context_packet": {"agents": [{"source": "AGENTS.md"}]},
        }
    )

    assert any(finding.category == "prd_context_missing" for finding in findings)


def test_baseline_dirty_files_do_not_count_as_executor_changes(monkeypatch, tmp_path) -> None:
    repo = _repo(tmp_path)
    monkeypatch.setattr(
        "dev_orchestrator.nodes.executor.detect_changed_files",
        lambda _repo_root: ["Web/app/Services/Analytics.php"],
    )

    result = executor_node(
        {
            "execute": True,
            "repo_root": str(repo),
            "assessment": {"recommended_model_tier": "cheap"},
            "repository_context": {"baseline_changed_files": ["Web/app/Services/Analytics.php"]},
        }
    )

    assert result["changed_files"] == []


def test_execute_mode_unrelated_new_changes_are_flagged() -> None:
    findings = review_state(
        {
            "execute": True,
            "changed_files": ["Web/app/Services/Analytics.php"],
            "assessment": {"task_type": "implementation", "complexity": "simple"},
            "context_packet": {
                "agents": [{"source": "AGENTS.md"}],
                "selected_files": ["dev-orchestrator/src/dev_orchestrator/nodes/reviewer.py"],
            },
        }
    )

    assert any(finding.category == "unrelated_changes" for finding in findings)


def test_cli_task_json_includes_reliability_fields(tmp_path) -> None:
    repo = _repo(tmp_path)
    runner = CliRunner()

    result = runner.invoke(app, ["task", "Rename dashboard label", "--repo", str(repo), "--json"])

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert "structured_review_findings" in payload
    assert "repair_actions" in payload
    assert "human_escalations" in payload
    assert payload["final_status"] == "completed"


def test_cli_critical_task_json_records_interrupt_escalation(tmp_path) -> None:
    repo = _repo(tmp_path)
    runner = CliRunner()

    result = runner.invoke(
        app,
        ["task", "Run a destructive migration that may drop table data", "--repo", str(repo), "--json"],
    )

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["final_status"] == "awaiting_human"
    assert payload["human_action_required"] is True
    assert payload["human_escalations"][0]["triggers"] == ["langgraph_interrupt", "human_checkpoint"]
