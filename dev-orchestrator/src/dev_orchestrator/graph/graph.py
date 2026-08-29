from __future__ import annotations

from pathlib import Path
from typing import Any

from dev_orchestrator.config import OrchestratorConfig
from dev_orchestrator.graph.routing import (
    route_after_human_checkpoint,
    route_after_executor,
    route_after_repair,
    route_after_reviewer,
    route_after_triage,
    route_after_verify,
)
from dev_orchestrator.graph.state import OrchestratorState
from dev_orchestrator.nodes.executor import executor_node
from dev_orchestrator.nodes.human import human_checkpoint_node
from dev_orchestrator.nodes.planner import planner_node
from dev_orchestrator.nodes.repair import diagnose_node, repair_node
from dev_orchestrator.nodes.repository_context import repository_context_node
from dev_orchestrator.nodes.reviewer import reviewer_node
from dev_orchestrator.nodes.triage import triage_node
from dev_orchestrator.nodes.verifier import verifier_node


def build_graph(config: OrchestratorConfig) -> Any:
    from langgraph.checkpoint.memory import InMemorySaver
    from langgraph.graph import END, START, StateGraph

    builder = StateGraph(OrchestratorState)
    builder.add_node("repository_context", repository_context_node)
    builder.add_node("triage", triage_node)
    builder.add_node("human_checkpoint", human_checkpoint_node)
    builder.add_node("planner", planner_node)
    builder.add_node("executor", lambda state: executor_node(state, config))
    builder.add_node("verifier", verifier_node)
    builder.add_node("reviewer", reviewer_node)
    builder.add_node("diagnose", diagnose_node)
    builder.add_node("repair", repair_node)

    builder.add_edge(START, "repository_context")
    builder.add_edge("repository_context", "triage")
    builder.add_conditional_edges(
        "triage",
        route_after_triage,
        {
            "human_checkpoint": "human_checkpoint",
            "planner": "planner",
            "executor": "executor",
        },
    )
    builder.add_conditional_edges(
        "human_checkpoint",
        route_after_human_checkpoint,
        {
            "planner": "planner",
            "executor": "executor",
            "end": END,
        },
    )
    builder.add_edge("planner", "executor")
    builder.add_conditional_edges(
        "executor",
        route_after_executor,
        {
            "verifier": "verifier",
            "end": END,
        },
    )
    builder.add_conditional_edges(
        "verifier",
        route_after_verify,
        {
            "reviewer": "reviewer",
            "diagnose": "diagnose",
            "end": END,
        },
    )
    builder.add_edge("diagnose", "repair")
    builder.add_conditional_edges(
        "repair",
        route_after_repair,
        {
            "verifier": "verifier",
            "end": END,
        },
    )
    builder.add_conditional_edges(
        "reviewer",
        route_after_reviewer,
        {
            "end": END,
        },
    )

    return builder.compile(checkpointer=InMemorySaver())


def initial_state(
    task: str,
    repo_root: Path,
    execute: bool,
    max_repair_attempts: int,
    task_id: str,
    context_budget: int = 16000,
    runs_dir: Path | None = None,
    agent: str = "codex",
    auto_apply: bool = True,
    agent_timeout_seconds: int = 600,
) -> OrchestratorState:
    run_dir = (runs_dir or repo_root / "dev-orchestrator" / ".runs") / task_id
    return {
        "task": task,
        "task_id": task_id,
        "execute": execute,
        "agent": agent,
        "auto_apply": auto_apply,
        "agent_timeout_seconds": agent_timeout_seconds,
        "repo_root": str(repo_root),
        "run_dir": str(run_dir),
        "context_budget": context_budget,
        "attempt_count": 0,
        "max_repair_attempts": max_repair_attempts,
        "model_calls": [],
        "token_usage": {"input": 0, "output": 0},
        "estimated_cost": 0.0,
        "node_log": [],
        "structured_review_findings": [],
        "verification_failures": [],
        "repair_actions": [],
        "repair_history": [],
        "pending_approvals": [],
        "proposal_paths": {},
        "patch_proposal": {},
        "patch_apply_result": {},
        "agent_invocation": {},
        "auto_apply_decision": {},
        "review_status": "not_run",
        "human_escalations": [],
        "human_action_required": False,
        "human_checkpoint_approved": False,
        "final_status": "started",
        "status": "started",
    }
