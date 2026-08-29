from __future__ import annotations

import re
from functools import lru_cache
from pathlib import Path

from dev_orchestrator.models.context import SkillContext
from dev_orchestrator.tools.repository import is_flutter_task, is_laravel_task, is_orchestrator_task


SKILL_RULES: dict[str, list[str]] = {
    "usm-attendance-system": ["eaes", "attendance", "scanner", "sync", "tenant", "rbac", "prd"],
    "laravel-best-practices": ["laravel", "controller", "migration", "model", "queue", "route", "php", "blade"],
    "livewire-development": ["livewire", "wire:", "component"],
    "tailwindcss-development": ["tailwind", "css", "blade", "responsive"],
    "ui-ux-pro-max": ["ui", "ux", "dashboard", "screen", "layout", "design"],
    "auditing-access-control": ["authorization", "permission", "tenant", "access control", "rbac", "security"],
    "deployment-pipeline-design": ["deploy", "deployment", "ci", "pipeline", "production", "supervisor"],
    "flutter-add-widget-test": ["flutter", "widget test", "screen"],
    "flutter-add-integration-test": ["flutter", "integration test", "end-to-end"],
    "socialite-development": ["oauth", "google", "socialite"],
    "pest-testing": ["pest"],
}


@lru_cache(maxsize=8)
def _discover_skill_metadata_cached(repo_root: str) -> tuple[tuple[str, str, str], ...]:
    root = Path(repo_root)
    skills_root = root / ".agents" / "skills"
    metadata: list[tuple[str, str, str]] = []
    if not skills_root.exists():
        return tuple(metadata)

    for skill_file in sorted(skills_root.glob("*/SKILL.md")):
        content = skill_file.read_text(encoding="utf-8", errors="replace")
        name = skill_file.parent.name
        description = ""
        frontmatter = re.match(r"---\n(.*?)\n---", content, re.S)
        if frontmatter:
            for line in frontmatter.group(1).splitlines():
                if line.startswith("description:"):
                    description = line.split(":", 1)[1].strip()
                if line.startswith("name:"):
                    name = line.split(":", 1)[1].strip()
        metadata.append((name, skill_file.relative_to(root).as_posix(), description))
    return tuple(metadata)


def discover_skill_metadata(repo_root: Path) -> dict[str, dict[str, str]]:
    return {
        name: {"path": path, "description": description}
        for name, path, description in _discover_skill_metadata_cached(str(repo_root.resolve()))
    }


def select_skills(repo_root: Path, task: str, target_paths: list[str] | None = None) -> list[str]:
    lowered = task.lower()
    available = discover_skill_metadata(repo_root)
    selected: list[str] = []

    for skill_name, keywords in SKILL_RULES.items():
        if skill_name in available and any(keyword in lowered for keyword in keywords):
            selected.append(skill_name)

    selected.extend(_path_based_skills(repo_root, task, target_paths or []))
    selected.extend(_description_matches(available, lowered))

    if is_laravel_task(task):
        _append_if_available(selected, available, "laravel-best-practices")
    if is_flutter_task(task):
        _append_if_available(selected, available, "usm-attendance-system")
    if is_orchestrator_task(task):
        _append_if_available(selected, available, "usm-attendance-system")

    return _dedupe_available(selected, available)


def load_skill_contexts(repo_root: Path, skill_names: list[str], max_chars_per_skill: int = 3200) -> list[SkillContext]:
    metadata = discover_skill_metadata(repo_root)
    contexts: list[SkillContext] = []
    for skill_name in skill_names:
        if skill_name not in metadata:
            continue
        path = metadata[skill_name]["path"]
        content = (repo_root / path).read_text(encoding="utf-8", errors="replace")
        contexts.append(
            SkillContext(
                name=skill_name,
                path=path,
                description=metadata[skill_name].get("description", ""),
                excerpt=content[:max_chars_per_skill],
            )
        )
    return contexts


def clear_skill_cache() -> None:
    _discover_skill_metadata_cached.cache_clear()


def _path_based_skills(repo_root: Path, task: str, target_paths: list[str]) -> list[str]:
    selected: list[str] = []
    joined = " ".join(target_paths).lower()
    available = discover_skill_metadata(repo_root)

    if "web/" in joined or is_laravel_task(task):
        _append_if_available(selected, available, "laravel-best-practices")
    if "livewire" in joined:
        _append_if_available(selected, available, "livewire-development")
    if "application/" in joined or is_flutter_task(task):
        _append_if_available(selected, available, "usm-attendance-system")
    if any(keyword in joined for keyword in ["resources/views", "tailwind", "blade"]):
        _append_if_available(selected, available, "ui-ux-pro-max")
        _append_if_available(selected, available, "tailwindcss-development")

    return selected


def _description_matches(available: dict[str, dict[str, str]], lowered_task: str) -> list[str]:
    words = {word for word in re.findall(r"[a-z][a-z0-9_-]{3,}", lowered_task)}
    matches: list[str] = []
    for name, meta in available.items():
        description = meta.get("description", "").lower()
        if description and len(words.intersection(set(re.findall(r"[a-z][a-z0-9_-]{3,}", description)))) >= 2:
            matches.append(name)
    return matches


def _append_if_available(selected: list[str], available: dict[str, dict[str, str]], skill_name: str) -> None:
    if skill_name in available:
        selected.append(skill_name)


def _dedupe_available(selected: list[str], available: dict[str, dict[str, str]]) -> list[str]:
    deduped: list[str] = []
    for skill_name in selected:
        if skill_name in available and skill_name not in deduped:
            deduped.append(skill_name)
    return deduped

