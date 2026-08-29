from __future__ import annotations

import fnmatch
import re
from pathlib import Path

from dev_orchestrator.models.context import ContextExcerpt


def load_agents_files(repo_root: Path) -> list[str]:
    candidates = sorted(repo_root.glob("**/AGENTS.md"))
    return [path.relative_to(repo_root).as_posix() for path in candidates if ".git" not in path.parts]


def discover_rule_roots(repo_root: Path) -> list[Path]:
    roots = [repo_root / ".ai" / "rules", repo_root / "Web" / ".ai" / "rules"]
    return [root for root in roots if root.exists()]


def load_rule_index(repo_root: Path) -> dict[str, list[str]]:
    mapping: dict[str, list[str]] = {}
    for rules_root in discover_rule_roots(repo_root):
        index_path = rules_root / "index.md"
        if index_path.exists():
            _merge_mapping(mapping, _parse_index(repo_root, index_path))

        for rule_file in sorted(rules_root.glob("*.md")):
            if rule_file.name == "index.md":
                continue
            frontmatter_paths = _parse_frontmatter_paths(rule_file.read_text(encoding="utf-8", errors="replace"))
            if frontmatter_paths:
                mapping[rule_file.relative_to(repo_root).as_posix()] = frontmatter_paths

    return mapping


def applicable_rules(repo_root: Path, changed_or_target_paths: list[str]) -> list[str]:
    mapping = load_rule_index(repo_root)
    applicable: list[str] = []
    for rule_file, patterns in mapping.items():
        for path in changed_or_target_paths:
            normalized_candidates = _path_variants(path)
            if any(_matches_any(candidate, patterns) for candidate in normalized_candidates):
                if (repo_root / rule_file).exists():
                    applicable.append(rule_file)
                break
    return sorted(set(applicable))


def rule_excerpts(repo_root: Path, paths: list[str], max_chars_per_rule: int = 2400) -> list[ContextExcerpt]:
    excerpts: list[ContextExcerpt] = []
    for path in applicable_rules(repo_root, paths):
        rule_path = repo_root / path
        content = rule_path.read_text(encoding="utf-8", errors="replace")
        excerpts.append(
            ContextExcerpt(
                source=path,
                title=_first_heading(content) or rule_path.stem,
                content=content[:max_chars_per_rule],
                kind="rule",
            )
        )
    return excerpts


def agent_excerpts(repo_root: Path, paths: list[str], max_chars_per_file: int = 3600) -> list[ContextExcerpt]:
    excerpts: list[ContextExcerpt] = []
    for relative in load_agents_files(repo_root):
        if relative != "AGENTS.md" and not any(path.startswith(str(Path(relative).parent).replace("\\", "/")) for path in paths):
            continue
        content = (repo_root / relative).read_text(encoding="utf-8", errors="replace")
        excerpts.append(
            ContextExcerpt(
                source=relative,
                title=_first_heading(content) or "AGENTS",
                content=content[:max_chars_per_file],
                kind="agents",
            )
        )
    return excerpts


def _parse_index(repo_root: Path, index_path: Path) -> dict[str, list[str]]:
    content = index_path.read_text(encoding="utf-8", errors="replace")
    mapping: dict[str, list[str]] = {}
    base = index_path.parent
    for line in content.splitlines():
        if "|" not in line or "Rule file" in line or "---" in line:
            continue
        parts = [part.strip() for part in line.strip("|").split("|")]
        if len(parts) != 2 or not parts[0] or not parts[1]:
            continue
        rule_path = _resolve_rule_path(repo_root, base, parts[1])
        mapping[rule_path] = [glob.strip().strip("'`") for glob in parts[0].split(",") if glob.strip()]
    return mapping


def _parse_frontmatter_paths(content: str) -> list[str]:
    match = re.match(r"---\n(.*?)\n---", content, re.S)
    if not match:
        return []
    paths: list[str] = []
    in_paths = False
    for line in match.group(1).splitlines():
        stripped = line.strip()
        if stripped == "paths:":
            in_paths = True
            continue
        if in_paths and stripped.startswith("-"):
            paths.append(stripped.removeprefix("-").strip().strip("'\""))
        elif in_paths and stripped and not line.startswith(" "):
            in_paths = False
    return paths


def _resolve_rule_path(repo_root: Path, base: Path, raw: str) -> str:
    cleaned = raw.strip().strip("`")
    if cleaned.startswith(".ai/") and "Web/.ai/" not in cleaned:
        candidate = base.parent.parent / cleaned
    else:
        candidate = base / cleaned if not (repo_root / cleaned).exists() else repo_root / cleaned
    return candidate.resolve().relative_to(repo_root.resolve()).as_posix()


def _merge_mapping(left: dict[str, list[str]], right: dict[str, list[str]]) -> None:
    for key, value in right.items():
        left.setdefault(key, [])
        left[key].extend(pattern for pattern in value if pattern not in left[key])


def _path_variants(path: str) -> list[str]:
    normalized = path.replace("\\", "/").lstrip("./")
    variants = [normalized]
    if normalized.startswith("Web/"):
        variants.append(normalized.removeprefix("Web/"))
    else:
        variants.append(f"Web/{normalized}")
    return variants


def _matches_any(path: str, patterns: list[str]) -> bool:
    return any(fnmatch.fnmatch(path, pattern.replace("\\", "/").strip()) for pattern in patterns)


def _first_heading(content: str) -> str:
    for line in content.splitlines():
        if line.startswith("#"):
            return line.lstrip("#").strip()
    return ""

