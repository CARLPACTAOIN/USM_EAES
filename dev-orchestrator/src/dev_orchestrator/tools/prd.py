from __future__ import annotations

import re
from pathlib import Path

from dev_orchestrator.models.context import ContextExcerpt


PRD_KEYWORDS = {
    "auth": ["rbac", "oauth", "sanctum", "onboarding"],
    "authorization": ["rbac", "role", "permission", "tenant"],
    "permission": ["rbac", "role", "permission"],
    "tenant": ["rbac", "tenant", "scope"],
    "scanner": ["offline", "scanner", "sync", "hydrate"],
    "sync": ["offline", "sync", "queue", "eilo"],
    "attendance": ["attendance", "eilo", "evaluation gate"],
    "evaluation": ["evaluation", "gate", "analytics"],
    "analytics": ["analytics", "gawad", "export"],
    "export": ["export", "pdf", "excel"],
    "database": ["database", "schema", "postgresql"],
    "migration": ["database", "schema", "postgresql"],
    "security": ["security", "pii", "https", "audit"],
    "deployment": ["deployment", "redis", "queue", "production"],
}


def needs_prd_context(task: str) -> bool:
    lowered = task.lower()
    behavior_words = [
        "implement",
        "change",
        "feature",
        "behavior",
        "policy",
        "attendance",
        "evaluation",
        "scanner",
        "authorization",
        "permission",
        "tenant",
        "database",
        "migration",
        "analytics",
        "export",
        "prd",
        "deviation",
    ]
    return any(word in lowered for word in behavior_words)


def needs_progress_audit_review(task: str) -> bool:
    lowered = task.lower()
    if not needs_prd_context(task):
        return False
    return any(word in lowered for word in ["implement", "complete", "finish", "deviation", "off-prd", "scope"])


def targeted_prd_context(repo_root: Path, task: str, max_chars: int = 12000) -> dict[str, str]:
    packet = targeted_prd_excerpts(repo_root, task, max_chars=max_chars)
    result: dict[str, str] = {}
    for excerpt in packet["prd"] + packet["audit"]:
        result.setdefault(excerpt.source, "")
        if result[excerpt.source]:
            result[excerpt.source] += "\n\n"
        result[excerpt.source] += excerpt.content
    return result


def targeted_prd_excerpts(repo_root: Path, task: str, max_chars: int = 12000) -> dict[str, list[ContextExcerpt]]:
    if not needs_prd_context(task):
        return {"prd": [], "audit": []}

    budget_each = max(max_chars // 2, 2000)
    return {
        "prd": _targeted_file_excerpts(repo_root / "PRD_V1.md", task, "prd", budget_each),
        "audit": _targeted_file_excerpts(repo_root / "PRD_PROGRESS_AUDIT.md", task, "audit", budget_each),
    }


def sectionize_markdown(content: str) -> list[tuple[str, str]]:
    sections: list[tuple[str, list[str]]] = []
    current_title = "Preamble"
    current_lines: list[str] = []

    for line in content.splitlines():
        if line.startswith("#"):
            if current_lines:
                sections.append((current_title, current_lines))
            current_title = line.lstrip("#").strip()
            current_lines = [line]
        else:
            current_lines.append(line)

    if current_lines:
        sections.append((current_title, current_lines))

    return [(title, "\n".join(lines).strip()) for title, lines in sections if "\n".join(lines).strip()]


def _targeted_file_excerpts(path: Path, task: str, kind: str, max_chars: int) -> list[ContextExcerpt]:
    if not path.exists():
        return []

    content = path.read_text(encoding="utf-8", errors="replace")
    sections = sectionize_markdown(content)
    keywords = _task_keywords(task)
    scored: list[tuple[int, str, str]] = []

    for title, section in sections:
        searchable = f"{title}\n{section}".lower()
        score = sum(1 for keyword in keywords if keyword in searchable)
        if score:
            scored.append((score, title, section))

    if not scored and sections:
        scored.append((1, sections[0][0], sections[0][1]))

    excerpts: list[ContextExcerpt] = []
    used = 0
    for _score, title, section in sorted(scored, key=lambda item: item[0], reverse=True):
        remaining = max_chars - used
        if remaining <= 0:
            break
        content_excerpt = section[:remaining]
        excerpts.append(
            ContextExcerpt(
                source=path.name,
                title=title,
                content=content_excerpt,
                kind=kind,
            )
        )
        used += len(content_excerpt)
    return excerpts


def _task_keywords(task: str) -> set[str]:
    lowered = task.lower()
    keywords = {word for word in re.findall(r"[a-z][a-z0-9_-]{3,}", lowered)}
    for trigger, additions in PRD_KEYWORDS.items():
        if trigger in lowered:
            keywords.update(additions)
    return keywords

