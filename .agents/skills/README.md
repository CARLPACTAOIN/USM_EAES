# Agent skills (EAES)

Authoritative UI/UX skill bundle for local agents (full [ui-ux-pro-max-skill](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) v2.5.0 bundle plus a Codex-facing wrapper).

| Path | Role |
|------|------|
| `ui-ux-pro-max-skill/` | **Full upstream skill repo**: `skill.json`, `src/`, docs, previews, bundled Claude/platform templates |
| `ui-ux-pro-max/SKILL.md` | **Primary Codex/agent instructions** (read this first for UI/UX tasks) |
| `ui-ux-pro-max/agents/openai.yaml` | Optional Codex UI metadata |
| `ui-ux-pro-max/scripts/` | Junction to `ui-ux-pro-max-skill/src/ui-ux-pro-max/scripts/` |
| `ui-ux-pro-max/data/` | Junction to `ui-ux-pro-max-skill/src/ui-ux-pro-max/data/` |
| `.cursor/skills/ui-ux-pro-max/` | Cursor copy of `ui-ux-pro-max/` (synced from here) |

## Search examples (repo root)

```powershell
python .agents/skills/ui-ux-pro-max/scripts/search.py "education event dashboard" --stack laravel -f markdown
python .agents/skills/ui-ux-pro-max/scripts/search.py "USM student portal" --design-system -p "USM EAES" -f markdown
```

Requires Python 3.x.

## Maintenance

The full upstream bundle is source data. Keep Codex-specific guidance in `ui-ux-pro-max/SKILL.md`; do not paste the full upstream README into the wrapper.

## Refresh from Web

If this checkout later restores a `Web/.agents/skills/` mirror and you update that mirror, re-copy to repo root:

```powershell
$root = "c:\Users\cjcar\Documents\Capstone_3rd_Year\System"
Remove-Item "$root\.agents\skills\ui-ux-pro-max-skill", "$root\.agents\skills\ui-ux-pro-max" -Recurse -Force
Copy-Item "$root\Web\.agents\skills\ui-ux-pro-max-skill" "$root\.agents\skills\ui-ux-pro-max-skill" -Recurse
Copy-Item "$root\Web\.agents\skills\ui-ux-pro-max" "$root\.agents\skills\ui-ux-pro-max" -Recurse
# Recreate junctions (see setup script or AGENTS.md)
```

