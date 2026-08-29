from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class OrchestratorConfig:
    provider: str
    model_cheap: str
    model_standard: str
    model_strong: str
    model_critical: str
    reasoning_cheap: str
    reasoning_standard: str
    reasoning_strong: str
    reasoning_critical: str
    max_repair_attempts: int
    codex_command: str
    agent_timeout_seconds: int
    runs_dir: Path

    @classmethod
    def from_env(cls, repo_root: Path) -> "OrchestratorConfig":
        runs_dir = Path(os.getenv("DEV_ORCH_RUNS_DIR", "dev-orchestrator/.runs"))
        if not runs_dir.is_absolute():
            runs_dir = repo_root / runs_dir

        return cls(
            provider=os.getenv("DEV_ORCH_PROVIDER", "codex"),
            model_cheap=os.getenv("DEV_ORCH_MODEL_CHEAP", "GPT-5.4 Mini"),
            model_standard=os.getenv("DEV_ORCH_MODEL_STANDARD", "GPT-5.5"),
            model_strong=os.getenv("DEV_ORCH_MODEL_STRONG", "GPT-5.6 Terra"),
            model_critical=os.getenv("DEV_ORCH_MODEL_CRITICAL", "GPT-5.6 Sol"),
            reasoning_cheap=os.getenv("DEV_ORCH_REASONING_CHEAP", "Light"),
            reasoning_standard=os.getenv("DEV_ORCH_REASONING_STANDARD", "High"),
            reasoning_strong=os.getenv("DEV_ORCH_REASONING_STRONG", "High"),
            reasoning_critical=os.getenv("DEV_ORCH_REASONING_CRITICAL", "Extra High"),
            max_repair_attempts=int(os.getenv("DEV_ORCH_MAX_REPAIR_ATTEMPTS", "3")),
            codex_command=os.getenv("DEV_ORCH_CODEX_COMMAND", "codex"),
            agent_timeout_seconds=int(os.getenv("DEV_ORCH_AGENT_TIMEOUT_SECONDS", "600")),
            runs_dir=runs_dir,
        )


def find_repo_root(start: Path | None = None) -> Path:
    current = (start or Path.cwd()).resolve()

    for candidate in (current, *current.parents):
        if (candidate / "AGENTS.md").exists() and (candidate / ".git").exists():
            return candidate

    raise RuntimeError("Could not locate repository root containing AGENTS.md and .git.")
