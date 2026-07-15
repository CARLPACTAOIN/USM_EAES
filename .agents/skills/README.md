# Agent skills (EAES)

Authoritative UI/UX skill copied from `Web/.agents/skills/` (full [ui-ux-pro-max-skill](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) v2.5.0 bundle).

| Path | Role |
|------|------|
| `ui-ux-pro-max-skill/` | **Full skill repo** — `skill.json`, `src/`, docs, previews, bundled Claude skills |
| `ui-ux-pro-max/SKILL.md` | **Primary agent instructions** (read this first for UI/UX tasks) |
| `ui-ux-pro-max/scripts/` | Junction → `ui-ux-pro-max-skill/src/ui-ux-pro-max/scripts/` |
| `ui-ux-pro-max/data/` | Junction → `ui-ux-pro-max-skill/src/ui-ux-pro-max/data/` |
| `.cursor/skills/ui-ux-pro-max/` | Cursor copy of `ui-ux-pro-max/` (synced from here) |

## Search examples (repo root)

```powershell
python .agents/skills/ui-ux-pro-max/scripts/search.py "education event dashboard" --stack html-tailwind -f markdown
python .agents/skills/ui-ux-pro-max/scripts/search.py "USM student portal" --design-system -p "USM EAES" -f markdown
```

Requires Python 3.x.

## Refresh from Web

If you update the skill under `Web/.agents/skills/`, re-copy to repo root:

```powershell
$root = "c:\Users\cjcar\Documents\Capstone_3rd_Year\System"
Remove-Item "$root\.agents\skills\ui-ux-pro-max-skill", "$root\.agents\skills\ui-ux-pro-max" -Recurse -Force
Copy-Item "$root\Web\.agents\skills\ui-ux-pro-max-skill" "$root\.agents\skills\ui-ux-pro-max-skill" -Recurse
Copy-Item "$root\Web\.agents\skills\ui-ux-pro-max" "$root\.agents\skills\ui-ux-pro-max" -Recurse
# Recreate junctions (see setup script or AGENTS.md)
```
