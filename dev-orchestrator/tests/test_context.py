from pathlib import Path

from dev_orchestrator.tools.context import build_context_packet
from dev_orchestrator.tools.prd import sectionize_markdown, targeted_prd_context, targeted_prd_excerpts
from dev_orchestrator.tools.repository import focused_repository_files
from dev_orchestrator.tools.skills import discover_skill_metadata, select_skills


def repo_root() -> Path:
    return Path(__file__).resolve().parents[2]


def test_prd_sensitive_feature_loads_prd_context() -> None:
    context = targeted_prd_context(repo_root(), "Implement scanner sync evaluation gate behavior")

    assert "PRD_V1.md" in context
    assert "PRD_PROGRESS_AUDIT.md" in context


def test_irrelevant_task_does_not_load_prd_context() -> None:
    context = targeted_prd_context(repo_root(), "Fix a typo in README")

    assert context == {}


def test_skill_selection_loads_relevant_metadata() -> None:
    root = repo_root()
    skills = select_skills(root, "Audit authorization tenant RBAC for scanner sync")
    metadata = discover_skill_metadata(root)

    assert "usm-attendance-system" in skills
    assert "auditing-access-control" in skills
    assert metadata["usm-attendance-system"]["path"].endswith("SKILL.md")
    assert "description" in metadata["usm-attendance-system"]


def test_laravel_authorization_task_selects_domain_laravel_and_access_control_skills() -> None:
    skills = select_skills(repo_root(), "Change Laravel authorization tenant RBAC for scanner sync")

    assert "usm-attendance-system" in skills
    assert "laravel-best-practices" in skills
    assert "auditing-access-control" in skills


def test_livewire_ui_task_selects_ui_livewire_and_tailwind_skills() -> None:
    skills = select_skills(repo_root(), "Improve Livewire dashboard Blade Tailwind layout")

    assert "ui-ux-pro-max" in skills
    assert "livewire-development" in skills
    assert "tailwindcss-development" in skills


def test_flutter_scanner_task_selects_flutter_context_paths() -> None:
    files = focused_repository_files(repo_root(), "Update Flutter scanner offline sync UI")

    assert "Application/pubspec.yaml" in files
    assert any(path.startswith("Application/lib/features/scanner") for path in files)


def test_prd_retrieval_returns_heading_bounded_excerpts() -> None:
    excerpts = targeted_prd_excerpts(repo_root(), "Implement scanner sync behavior")

    assert excerpts["prd"]
    assert excerpts["prd"][0].title != "Preamble"
    assert excerpts["prd"][0].content.startswith("#")


def test_sectionize_markdown_splits_by_headings() -> None:
    sections = sectionize_markdown("# One\nBody\n## Two\nMore")

    assert sections == [("One", "# One\nBody"), ("Two", "## Two\nMore")]


def test_context_budget_caps_content_without_dropping_source_references() -> None:
    packet = build_context_packet(repo_root(), "Implement scanner sync authorization", budget_chars=1200)

    assert packet.used_chars <= packet.budget_chars
    assert packet.sources
    assert packet.skipped


def test_trivial_readme_typo_does_not_load_prd_or_full_skills() -> None:
    packet = build_context_packet(repo_root(), "Fix a typo in README", budget_chars=16000)

    assert packet.prd == []
    assert packet.audit == []
    assert packet.skills == []
