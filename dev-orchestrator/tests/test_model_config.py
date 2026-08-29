from pathlib import Path

from dev_orchestrator.config import OrchestratorConfig
from dev_orchestrator.models.router import ModelTier, model_for_tier


def test_codex_model_defaults_include_reasoning_effort(tmp_path: Path) -> None:
    config = OrchestratorConfig.from_env(tmp_path)

    standard = model_for_tier(config, ModelTier.Standard, "executor")
    critical = model_for_tier(config, ModelTier.Strong, "reviewer", critical=True)

    assert standard.model == "GPT-5.5"
    assert standard.reasoning == "High"
    assert critical.model == "GPT-5.6 Sol"
    assert critical.reasoning == "Extra High"
    assert critical.critical_override is True
