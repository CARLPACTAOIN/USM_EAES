# EAES Development Orchestrator

LangGraph-based development-time workflow scheduler for this repository. It wraps the existing `AGENTS.md`, `.agents/skills/`, `PRD_V1.md`, `PRD_PROGRESS_AUDIT.md`, Laravel, and Flutter workflows instead of replacing them.

## Install

```powershell
cd C:\Users\cjcar\Documents\Capstone_3rd_Year\System
python -m venv dev-orchestrator\.venv
dev-orchestrator\.venv\Scripts\python -m pip install -e .\dev-orchestrator[dev]
```

## Commands

```powershell
python -m dev_orchestrator assess "Rename dashboard label"
python -m dev_orchestrator task "Implement scanner assignment guard"
python -m dev_orchestrator task "Implement scanner assignment guard" --execute --agent codex
python -m dev_orchestrator task "Rename dashboard label" --execute --agent codex --no-auto-apply
python -m dev_orchestrator resume <task-id> --approve-checkpoint
python -m dev_orchestrator resume <task-id> --approve-patch
python -m dev_orchestrator resume <task-id> --reject "reason"
python -m dev_orchestrator chat-start "Implement scanner assignment guard" --json
python -m dev_orchestrator chat-approve <task-id> --target patch --json
python -m dev_orchestrator chat-reject <task-id> "reason" --json
```

`task` runs the graph. By default it is guarded and does not mutate repository files. Pass `--execute --agent codex` to ask the local Codex CLI for a patch proposal. Low-risk simple patches can be auto-applied and verified; moderate, high, critical, destructive, dependency, migration, and deployment work still requires approval. Use `--no-auto-apply` to force approval for every patch.

The `chat-*` commands are testing and bridge hooks for agents. In normal chat use, the assistant can call them in the background and present approval cards here instead of asking you to run PowerShell commands yourself.

## Configuration

Copy `.env.example` to a local env file or set variables in PowerShell. API keys are intentionally not represented here; configure them through the provider SDK or environment outside the repository.

Model tiers are configured independently:

- `DEV_ORCH_MODEL_CHEAP`
- `DEV_ORCH_MODEL_STANDARD`
- `DEV_ORCH_MODEL_STRONG`
- `DEV_ORCH_MODEL_CRITICAL`

Reasoning effort is configured independently:

- `DEV_ORCH_REASONING_CHEAP`
- `DEV_ORCH_REASONING_STANDARD`
- `DEV_ORCH_REASONING_STRONG`
- `DEV_ORCH_REASONING_CRITICAL`
- `DEV_ORCH_CODEX_COMMAND`
- `DEV_ORCH_AGENT_TIMEOUT_SECONDS`

Set `DEV_ORCH_PROVIDER=mock` for deterministic offline patch proposals. The default `codex` provider discovers `codex` from `PATH` or `DEV_ORCH_CODEX_COMMAND`, runs `codex exec` in read-only mode, and falls back to `agent_prompt.md` if Codex is unavailable or returns invalid output.

## Workflow

- Repository context loads only relevant instruction files and metadata.
- Triage applies deterministic rules before optional model classification.
- Planner is skipped for trivial/simple tasks.
- Executor is guarded and receives only the selected context.
- Verifier chooses targeted Laravel, frontend, or Flutter commands from changed files and task risk.
- Reviewer and repair are invoked only when risk or failed verification justifies them.

Run metadata is written to `dev-orchestrator/.runs/`, which is ignored by Git. Per-task artifacts under `dev-orchestrator/.runs/<task-id>/` include `state.json`, `proposal.json`, `proposal.patch`, `agent_prompt.md`, `agent_result.json`, Codex stdout/stderr/final-message files, `verification.json`, and `review.json` as applicable.

## Tests

```powershell
cd dev-orchestrator
python -m pytest
```

Tests mock orchestration behavior and do not make live LLM calls.
