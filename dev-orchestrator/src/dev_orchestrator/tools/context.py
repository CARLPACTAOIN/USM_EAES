from __future__ import annotations

from pathlib import Path

from dev_orchestrator.models.context import ContextPacket
from dev_orchestrator.tools.prd import targeted_prd_excerpts
from dev_orchestrator.tools.repository import detect_available_tooling, focused_repository_files
from dev_orchestrator.tools.rules import agent_excerpts, rule_excerpts
from dev_orchestrator.tools.skills import load_skill_contexts, select_skills


DEFAULT_CONTEXT_BUDGET = 16000


def build_context_packet(repo_root: Path, task: str, budget_chars: int = DEFAULT_CONTEXT_BUDGET) -> ContextPacket:
    selected_files = focused_repository_files(repo_root, task)
    tooling = detect_available_tooling(repo_root)
    selected_skills = select_skills(repo_root, task, selected_files)

    packet = ContextPacket(
        selected_files=selected_files,
        tooling=tooling,
        budget_chars=budget_chars,
    )

    _add_agents(packet, agent_excerpts(repo_root, selected_files))
    _add_rules(packet, rule_excerpts(repo_root, selected_files))
    _add_skills(packet, load_skill_contexts(repo_root, selected_skills, max_chars_per_skill=3200))

    prd_packet = targeted_prd_excerpts(repo_root, task, max_chars=max(4000, budget_chars // 2))
    _add_prd(packet, prd_packet["prd"], "prd")
    _add_prd(packet, prd_packet["audit"], "audit")

    packet.sources = _sources(packet)
    return packet


def _add_agents(packet: ContextPacket, excerpts: list[object]) -> None:
    for excerpt in excerpts:
        if _try_reserve(packet, len(excerpt.content), f"agent:{excerpt.source}"):
            packet.agents.append(excerpt)


def _add_rules(packet: ContextPacket, excerpts: list[object]) -> None:
    for excerpt in excerpts:
        if _try_reserve(packet, len(excerpt.content), f"rule:{excerpt.source}"):
            packet.rules.append(excerpt)


def _add_skills(packet: ContextPacket, skills: list[object]) -> None:
    for skill in skills:
        if _try_reserve(packet, len(skill.excerpt), f"skill:{skill.path}"):
            packet.skills.append(skill)


def _add_prd(packet: ContextPacket, excerpts: list[object], bucket: str) -> None:
    target = packet.prd if bucket == "prd" else packet.audit
    for excerpt in excerpts:
        if _try_reserve(packet, len(excerpt.content), f"{bucket}:{excerpt.source}:{excerpt.title}"):
            target.append(excerpt)


def _try_reserve(packet: ContextPacket, chars: int, source: str) -> bool:
    if packet.used_chars + chars > packet.budget_chars:
        packet.skipped.append(f"budget:{source}")
        return False
    packet.used_chars += chars
    return True


def _sources(packet: ContextPacket) -> list[str]:
    sources: list[str] = []
    sources.extend(packet.selected_files)
    sources.extend(excerpt.source for excerpt in packet.agents)
    sources.extend(excerpt.source for excerpt in packet.rules)
    sources.extend(skill.path for skill in packet.skills)
    sources.extend(f"{excerpt.source}#{excerpt.title}" for excerpt in packet.prd)
    sources.extend(f"{excerpt.source}#{excerpt.title}" for excerpt in packet.audit)
    return sorted(set(sources))

