from pathlib import Path

from dev_orchestrator.tools.rules import applicable_rules, load_rule_index, rule_excerpts


def test_root_and_web_rule_indexes_are_discovered(tmp_path: Path) -> None:
    (tmp_path / ".ai" / "rules").mkdir(parents=True)
    (tmp_path / "Web" / ".ai" / "rules").mkdir(parents=True)
    (tmp_path / ".ai" / "rules" / "index.md").write_text(
        "| Applies to | Rule file |\n| --- | --- |\n| docs/** | .ai/rules/docs.md |\n",
        encoding="utf-8",
    )
    (tmp_path / ".ai" / "rules" / "docs.md").write_text("# Docs\n", encoding="utf-8")
    (tmp_path / "Web" / ".ai" / "rules" / "index.md").write_text(
        "| Applies to | Rule file |\n| --- | --- |\n| app/Services/** | .ai/rules/services.md |\n",
        encoding="utf-8",
    )
    (tmp_path / "Web" / ".ai" / "rules" / "services.md").write_text("# Services\n", encoding="utf-8")

    mapping = load_rule_index(tmp_path)

    assert ".ai/rules/docs.md" in mapping
    assert "Web/.ai/rules/services.md" in mapping
    assert applicable_rules(tmp_path, ["docs/readme.md", "Web/app/Services/Foo.php"]) == [
        ".ai/rules/docs.md",
        "Web/.ai/rules/services.md",
    ]


def test_rule_frontmatter_paths_are_supported(tmp_path: Path) -> None:
    rules = tmp_path / "Web" / ".ai" / "rules"
    rules.mkdir(parents=True)
    (rules / "services.md").write_text(
        "---\npaths:\n  - app/Services/EventAnalytics*\n---\n# Services\nRule body",
        encoding="utf-8",
    )

    excerpts = rule_excerpts(tmp_path, ["Web/app/Services/EventAnalyticsService.php"])

    assert excerpts
    assert excerpts[0].source == "Web/.ai/rules/services.md"


def test_analytics_service_path_loads_existing_services_rule() -> None:
    root = Path(__file__).resolve().parents[2]

    rules = applicable_rules(root, ["Web/app/Services/OrganizationAnalyticsService.php"])

    assert "Web/.ai/rules/services.md" in rules

