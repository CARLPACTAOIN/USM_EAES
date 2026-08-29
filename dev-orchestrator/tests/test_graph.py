import pytest

from dev_orchestrator.config import OrchestratorConfig
from dev_orchestrator.graph.graph import build_graph, initial_state


@pytest.mark.filterwarnings("ignore::DeprecationWarning")
def test_graph_runs_dry_assessment_without_live_llm(tmp_path) -> None:
    pytest.importorskip("langgraph")
    repo_root = tmp_path
    (repo_root / ".git").mkdir()
    (repo_root / "AGENTS.md").write_text("Agent rules", encoding="utf-8")
    (repo_root / ".agents" / "skills").mkdir(parents=True)
    (repo_root / "PRD_V1.md").write_text("# PRD", encoding="utf-8")
    (repo_root / "PRD_PROGRESS_AUDIT.md").write_text("# Audit", encoding="utf-8")

    config = OrchestratorConfig.from_env(repo_root)
    graph = build_graph(config)
    state = initial_state("Rename dashboard label", repo_root, False, 3, "test-thread")

    result = graph.invoke(state, config={"configurable": {"thread_id": "test-thread"}})

    assert result["assessment"]["recommended_model_tier"] == "cheap"
    assert result["status"] in {"dry_run_ready", "reviewed"}

