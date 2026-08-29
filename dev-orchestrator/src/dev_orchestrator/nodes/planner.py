from __future__ import annotations


def planner_node(state: dict[str, object]) -> dict[str, object]:
    assessment = state.get("assessment", {})
    task = str(state["task"])
    steps = [
        f"Confirm requested scope for: {task}",
        "Read applicable AGENTS/rules/skills and targeted PRD excerpts.",
        "Identify the smallest affected code path and matching tests.",
        "Implement only the requested change.",
        "Run targeted verification and review the diff.",
    ]

    if isinstance(assessment, dict) and assessment.get("needs_security_review"):
        steps.insert(3, "Trace authorization and tenant boundaries before editing.")

    return {"plan": steps, "node_log": ["planner"]}

