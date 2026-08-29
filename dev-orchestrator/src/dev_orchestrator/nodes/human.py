from __future__ import annotations

from dev_orchestrator.models.reliability import HumanEscalation


def human_checkpoint_node(state: dict[str, object]) -> dict[str, object]:
    try:
        from langgraph.types import interrupt
    except ImportError:
        escalation = HumanEscalation(
            reason="Critical workflow requires approval, but LangGraph interrupts are unavailable.",
            triggers=["interrupt_unavailable", "critical_workflow"],
            requested_action="Inspect the run log and approve, revise scope, or stop the task.",
            resumable=True,
        )
        return {
            "human_action_required": True,
            "human_action_reason": escalation.reason,
            "human_escalations": [escalation.model_dump(mode="json")],
            "status": "awaiting_human",
            "final_status": "awaiting_human",
            "node_log": ["human_checkpoint:unavailable"],
        }

    approval = interrupt(
        {
            "task_id": state.get("task_id"),
            "task": state.get("task"),
            "assessment": state.get("assessment"),
            "message": "Critical-risk task requires human approval before execution.",
        }
    )

    if approval is True or approval == "approved":
        return {
            "human_action_required": False,
            "human_checkpoint_approved": True,
            "node_log": ["human_checkpoint:approved"],
        }

    return {
        "human_action_required": True,
        "human_action_reason": "Human approval was not granted.",
        "human_escalations": [
            {
                "reason": "Human approval was not granted.",
                "triggers": ["human_checkpoint_rejected"],
                "requested_action": "Approve, reject, or revise the task scope before continuing.",
                "resumable": True,
            }
        ],
        "status": "awaiting_human",
        "final_status": "awaiting_human",
        "node_log": ["human_checkpoint:rejected"],
    }
