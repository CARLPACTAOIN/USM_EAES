from __future__ import annotations

from pathlib import Path

from dev_orchestrator.models.context import ContextPacket
from dev_orchestrator.tools.git import detect_changed_files, git_status
from dev_orchestrator.tools.context import build_context_packet
from dev_orchestrator.tools.repository import detect_available_tooling, infer_task_paths
from dev_orchestrator.tools.rules import applicable_rules, load_agents_files
from dev_orchestrator.tools.skills import discover_skill_metadata, select_skills


def repository_context_node(state: dict[str, object]) -> dict[str, object]:
    repo_root = Path(str(state["repo_root"]))
    task = str(state["task"])
    target_paths = infer_task_paths(task)
    changed_files = detect_changed_files(repo_root)
    context_budget = int(state.get("context_budget", 16000))
    context_packet = build_context_packet(repo_root, task, context_budget)
    skills = [skill.name for skill in context_packet.skills] or select_skills(repo_root, task, context_packet.selected_files)

    context = {
        "target_paths": target_paths,
        "selected_files": context_packet.selected_files,
        "tooling": detect_available_tooling(repo_root),
        "git_status": git_status(repo_root),
        "baseline_changed_files": changed_files,
        "agents_files": load_agents_files(repo_root),
        "skill_metadata": {name: discover_skill_metadata(repo_root)[name] for name in skills if name in discover_skill_metadata(repo_root)},
        "context_used_chars": context_packet.used_chars,
        "context_skipped": context_packet.skipped,
    }

    rule_targets = context_packet.selected_files + target_paths

    return {
        "repository_context": context,
        "context_packet": context_packet.model_dump(mode="json"),
        "context_sources": context_packet.sources,
        "changed_files": [],
        "applicable_agent_rules": applicable_rules(repo_root, rule_targets),
        "applicable_skills": skills,
        "prd_context": _legacy_prd_context(context_packet),
        "node_log": ["repository_context"],
    }


def _legacy_prd_context(context_packet: ContextPacket) -> dict[str, str]:
    contexts: dict[str, str] = {}
    for excerpt in [*context_packet.prd, *context_packet.audit]:
        contexts.setdefault(excerpt.source, "")
        if contexts[excerpt.source]:
            contexts[excerpt.source] += "\n\n"
        contexts[excerpt.source] += excerpt.content
    return contexts
