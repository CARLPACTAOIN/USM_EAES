from __future__ import annotations

import fnmatch
import os
from pathlib import Path


IGNORED_DIRS = {
    ".git",
    ".runs",
    ".venv",
    "__pycache__",
    "node_modules",
    "vendor",
    "build",
    ".dart_tool",
}


def list_tree(repo_root: Path, max_entries: int = 300) -> list[str]:
    entries: list[str] = []
    for root, dirs, files in os.walk(repo_root):
        dirs[:] = [directory for directory in dirs if directory not in IGNORED_DIRS]
        rel_root = Path(root).relative_to(repo_root)
        for file_name in files:
            relative = rel_root / file_name
            entries.append(relative.as_posix())
            if len(entries) >= max_entries:
                return entries
    return entries


def read_file(repo_root: Path, relative_path: str, max_chars: int = 16000) -> str:
    path = (repo_root / relative_path).resolve()
    path.relative_to(repo_root.resolve())
    return path.read_text(encoding="utf-8", errors="replace")[:max_chars]


def search_code(repo_root: Path, query: str, globs: list[str] | None = None) -> list[str]:
    matches: list[str] = []
    lowered = query.lower()
    for relative in list_tree(repo_root, max_entries=5000):
        if globs and not any(fnmatch.fnmatch(relative, pattern) for pattern in globs):
            continue
        path = repo_root / relative
        if path.suffix.lower() in {".png", ".jpg", ".jpeg", ".gif", ".ico", ".ttf", ".lock"}:
            continue
        try:
            content = path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        for index, line in enumerate(content.splitlines(), start=1):
            if lowered in line.lower():
                matches.append(f"{relative}:{index}:{line.strip()[:240]}")
                if len(matches) >= 100:
                    return matches
    return matches


def infer_task_paths(task: str) -> list[str]:
    lowered = task.lower()
    paths: list[str] = []

    if is_laravel_task(task):
        paths.append("Web")
    if is_flutter_task(task):
        paths.append("Application")
    if is_orchestrator_task(task):
        paths.extend(["AGENTS.md", ".agents", "PRD_V1.md", "PRD_PROGRESS_AUDIT.md"])
    if not paths:
        paths.append(".")

    return sorted(set(paths))


def is_laravel_task(task: str) -> bool:
    lowered = task.lower()
    return any(
        word in lowered
        for word in [
            "api",
            "laravel",
            "controller",
            "route",
            "policy",
            "migration",
            "database",
            "model",
            "service",
            "job",
            "queue",
            "livewire",
            "blade",
            "sanctum",
            "socialite",
            "auth",
            "authentication",
            "authorization",
            "permission",
            "rbac",
            "tenant",
            "scanner",
            "sync",
            "attendance",
            "evaluation",
            "analytics",
            "export",
        ]
    )


def is_flutter_task(task: str) -> bool:
    lowered = task.lower()
    return any(word in lowered for word in ["flutter", "scanner app", "mobile", "sqlite", "offline", "dart"])


def is_orchestrator_task(task: str) -> bool:
    lowered = task.lower()
    return any(word in lowered for word in ["agent", "orchestrator", "langgraph", "skill", "prd", "context"])


def focused_repository_files(repo_root: Path, task: str, max_files: int = 120) -> list[str]:
    candidates: list[str] = []

    if is_laravel_task(task):
        terms = _task_path_terms(task)
        candidates.extend(
            [
                "Web/routes/api.php",
                "Web/routes/web.php",
                "Web/composer.json",
                "Web/package.json",
                "Web/phpunit.xml",
                "Web/config/auth.php",
                "Web/config/queue.php",
                "Web/config/permission.php",
            ]
        )
        candidates.extend(_filter_files(_files_under(repo_root / "Web" / "app" / "Http", repo_root), terms))
        candidates.extend(_filter_files(_files_under(repo_root / "Web" / "app" / "Models", repo_root), terms))
        candidates.extend(_filter_files(_files_under(repo_root / "Web" / "app" / "Services", repo_root), terms))
        candidates.extend(_filter_files(_files_under(repo_root / "Web" / "app" / "Livewire", repo_root), terms))
        candidates.extend(_filter_files(_files_under(repo_root / "Web" / "app" / "Jobs", repo_root), terms))
        candidates.extend(_filter_files(_files_under(repo_root / "Web" / "resources" / "views", repo_root), terms))
        candidates.extend(_filter_files(_files_under(repo_root / "Web" / "database" / "migrations", repo_root), terms))
        candidates.extend(_filter_files(_files_under(repo_root / "Web" / "tests", repo_root), terms))

    if is_flutter_task(task):
        terms = _task_path_terms(task)
        candidates.extend(
            [
                "Application/pubspec.yaml",
                "Application/analysis_options.yaml",
                "Application/README.md",
            ]
        )
        candidates.extend(_filter_files(_files_under(repo_root / "Application" / "lib", repo_root), terms))
        candidates.extend(_filter_files(_files_under(repo_root / "Application" / "test", repo_root), terms))

    if is_orchestrator_task(task):
        candidates.extend(
            [
                "AGENTS.md",
                "PRD_V1.md",
                "PRD_PROGRESS_AUDIT.md",
                "dev-orchestrator/README.md",
                "dev-orchestrator/pyproject.toml",
            ]
        )
        candidates.extend(_files_under(repo_root / ".agents" / "skills", repo_root, pattern="SKILL.md"))
        candidates.extend(_files_under(repo_root / ".ai" / "rules", repo_root))
        candidates.extend(_files_under(repo_root / "Web" / ".ai" / "rules", repo_root))
        candidates.extend(_files_under(repo_root / "dev-orchestrator" / "src", repo_root))
        candidates.extend(_files_under(repo_root / "dev-orchestrator" / "tests", repo_root))

    if not candidates:
        candidates.extend(["AGENTS.md"])

    existing = [path for path in dict.fromkeys(candidates) if (repo_root / path).exists()]
    return existing[:max_files]


def detect_available_tooling(repo_root: Path) -> dict[str, object]:
    web = repo_root / "Web"
    app = repo_root / "Application"

    return {
        "laravel": (web / "artisan").exists(),
        "composer_json": (web / "composer.json").exists(),
        "phpunit": (web / "phpunit.xml").exists(),
        "pint": (web / "vendor" / "bin" / "pint").exists() or (web / "vendor" / "bin" / "pint.bat").exists(),
        "npm": (web / "package.json").exists(),
        "flutter": (app / "pubspec.yaml").exists(),
        "flutter_tests": (app / "test").exists(),
        "rules_index": (web / ".ai" / "rules" / "index.md").exists(),
        "root_rules_index": (repo_root / ".ai" / "rules" / "index.md").exists(),
        "skills": (repo_root / ".agents" / "skills").exists(),
    }


def _files_under(path: Path, repo_root: Path, pattern: str = "*") -> list[str]:
    if not path.exists():
        return []
    if path.is_file():
        return [path.relative_to(repo_root).as_posix()]

    files: list[str] = []
    for candidate in sorted(path.rglob(pattern)):
        if candidate.is_file() and not any(part in IGNORED_DIRS for part in candidate.parts):
            files.append(candidate.relative_to(repo_root).as_posix())
    return files


def _filter_files(files: list[str], terms: set[str]) -> list[str]:
    matched = [path for path in files if any(term in path.lower() for term in terms)]
    return matched if matched else files[:8]


def _task_path_terms(task: str) -> set[str]:
    lowered = task.lower()
    terms = {word for word in lowered.replace("-", " ").replace("_", " ").split() if len(word) >= 4}

    if any(word in lowered for word in ["auth", "authorization", "permission", "rbac", "tenant"]):
        terms.update({"auth", "adminscope", "assignment", "tenant", "permission", "security", "middleware"})
    if any(word in lowered for word in ["scanner", "sync", "attendance", "offline"]):
        terms.update(
            {
                "scanner",
                "sync",
                "attendance",
                "rawscan",
                "participation",
                "scope",
                "queue",
                "resolveattendance",
            }
        )
    if "analytics" in lowered:
        terms.update({"analytics", "gawad", "export"})
    if "evaluation" in lowered:
        terms.update({"evaluation", "sentiment"})
    if any(word in lowered for word in ["livewire", "dashboard", "blade", "tailwind", "ui"]):
        terms.update({"livewire", "dashboard", "blade", "view", "frontend"})

    return {term.lower() for term in terms}
