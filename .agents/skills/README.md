# Agent skills (EAES)

Authoritative UI/UX skill bundle for local agents (full [ui-ux-pro-max-skill](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) v2.11.0 bundle plus a Codex-facing skill suite).

Installed from upstream `main` at `f8ac5e1266dba8354ea96e19994d9f4345e7ec31`.

| Path | Role |
|------|------|
| `.codex/skills/` | **Primary Codex skill suite** for UI/UX, styling, design systems, brand, slides, and banners |
| `.codex/skills/ui-ux-pro-max/SKILL.md` | UI/UX routing entrypoint (read this first for UI/UX tasks) |
| `.codex/skills/ui-ux-pro-max/scripts/` | Codex-local copy of the upstream search scripts |
| `.codex/skills/ui-ux-pro-max/data/` | Codex-local copy of the upstream CSV dataset |
| `.codex/skills/ui-ux-pro-max/references/` | Codex-local quick-reference and pre-delivery rules |
| `.codex/skills/ui-styling/`, `.codex/skills/design/`, `.codex/skills/design-system/`, `.codex/skills/brand/`, `.codex/skills/slides/`, `.codex/skills/banner-design/` | Specialized Codex design skills used as task-specific companions |
| `.agents/skills/ui-ux-pro-max-skill/` | **Full upstream skill repo**: `skill.json`, `src/`, docs, previews, bundled platform templates |
| `.agents/skills/ui-ux-pro-max/` | Legacy repo-local wrapper retained for compatibility with older local agent references |
| `.cursor/skills/ui-ux-pro-max/` | Cursor copy of the UI/UX guidance; refresh from `.codex/skills/ui-ux-pro-max/` when needed |

## Search examples (repo root)

```powershell
python .codex/skills/ui-ux-pro-max/scripts/search.py "education event dashboard" --stack laravel -f markdown
python .codex/skills/ui-ux-pro-max/scripts/search.py "USM student portal" --design-system -p "USM EAES" -f markdown
```

Requires Python 3.x.

## Maintenance

The full upstream bundle is source data. Keep Codex-specific guidance under `.codex/skills/`; do not paste the full upstream README into Codex skill entrypoints. If upstream `src/ui-ux-pro-max/` files change, refresh the matching Codex-local `data/`, `scripts/`, or `references/` directory.

## Refresh from Web

If this checkout later restores a `Web/.agents/skills/` mirror and you update that mirror, refresh the upstream source and then copy the Codex-facing files into `.codex/skills/`:

```powershell
$root = "c:\Users\cjcar\Documents\Capstone_3rd_Year\System"
Remove-Item "$root\.agents\skills\ui-ux-pro-max-skill", "$root\.agents\skills\ui-ux-pro-max" -Recurse -Force
Copy-Item "$root\Web\.agents\skills\ui-ux-pro-max-skill" "$root\.agents\skills\ui-ux-pro-max-skill" -Recurse
Copy-Item "$root\Web\.agents\skills\ui-ux-pro-max" "$root\.agents\skills\ui-ux-pro-max" -Recurse
Remove-Item "$root\.codex\skills\ui-ux-pro-max" -Recurse -Force
Copy-Item "$root\.agents\skills\ui-ux-pro-max" "$root\.codex\skills\ui-ux-pro-max" -Recurse
# Refresh copied scripts/data/references from the upstream bundle if needed.
```

