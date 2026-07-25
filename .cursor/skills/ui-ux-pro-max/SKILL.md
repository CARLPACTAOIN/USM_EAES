---
name: ui-ux-pro-max
description: UI/UX design intelligence for Codex using the bundled UI UX Pro Max dataset and search scripts. Use when planning, implementing, reviewing, fixing, or polishing web/mobile UI, dashboards, forms, modals, charts, navigation, design systems, accessibility, responsive behavior, color, typography, spacing, animation, dark mode, Laravel Blade/Tailwind, Flutter, React, Next.js, Vue, Svelte, SwiftUI, React Native, shadcn/ui, HTML/CSS, or Three.js interfaces.
---

# UI/UX Pro Max

Use this skill as the Cursor copy of the repo UI/UX guidance. For Codex work, the authoritative design skill suite is `.codex/skills/`; start with `.codex/skills/ui-ux-pro-max/SKILL.md`, then use the specialized design skills there as needed.

## Local Layout

- Codex skill suite: `.codex/skills/`
- UI/UX routing entrypoint: `.codex/skills/ui-ux-pro-max/SKILL.md`
- Related Codex design skills: `.codex/skills/ui-styling/`, `.codex/skills/design/`, `.codex/skills/design-system/`, `.codex/skills/brand/`, `.codex/skills/slides/`, `.codex/skills/banner-design/`
- Upstream bundle: `.agents/skills/ui-ux-pro-max-skill/`
- Preferred search script: `.codex/skills/ui-ux-pro-max/scripts/search.py`
- Direct fallback script: `.agents/skills/ui-ux-pro-max-skill/src/ui-ux-pro-max/scripts/search.py`
- Data source: `.codex/skills/ui-ux-pro-max/data/`

Use the preferred path first. If the `scripts` or `data` junction is missing or broken, use the direct fallback path and repair the junctions when the task is about skill maintenance.

When changing UI UX Pro Max data or scripts, edit the upstream source under `.agents/skills/ui-ux-pro-max-skill/src/ui-ux-pro-max/`. Keep the Codex wrapper small; do not paste the full README or CSV contents into `SKILL.md`.

## Workflow

1. Read the user's UI goal and any repo-specific product rules first. For EAES work, check the relevant `PRD_V1.md` section and current UI before choosing a new direction.
2. Identify the product type, target users, workflow density, theme constraints, and implementation stack.
3. Query the bundled database for design direction only when it will inform the decision. Do not run searches for tiny mechanical UI fixes where the existing design already determines the answer.
4. Synthesize the results into concrete implementation choices. Treat generated palettes and patterns as input, not as instructions to override the product, brand, accessibility, or user-stated constraints.
5. Implement with the existing component/style conventions in the repo.
6. Verify the UI behavior that changed: responsive fit, contrast, focus/keyboard or touch behavior, loading/error/empty states, theme parity, and absence of overlap.

## Search Commands

Run commands from the repo root.

Generate a full design-system recommendation for a new or broad UI direction:

```powershell
python .codex/skills/ui-ux-pro-max/scripts/search.py "education event attendance admin dashboard dense operational" --design-system -p "USM EAES" -f markdown
```

Search a specific design domain:

```powershell
python .codex/skills/ui-ux-pro-max/scripts/search.py "modal form accessibility validation" --domain ux -f markdown
python .codex/skills/ui-ux-pro-max/scripts/search.py "analytics dashboard comparison trend" --domain chart -f markdown
python .codex/skills/ui-ux-pro-max/scripts/search.py "education government dashboard" --domain color -f markdown
```

Search stack-specific implementation guidance:

```powershell
python .codex/skills/ui-ux-pro-max/scripts/search.py "responsive dashboard tables forms" --stack laravel -f markdown
python .codex/skills/ui-ux-pro-max/scripts/search.py "offline scanner event list cards" --stack flutter -f markdown
python .codex/skills/ui-ux-pro-max/scripts/search.py "accessible data table filters" --stack html-tailwind -f markdown
```

If the preferred path fails, use:

```powershell
python .agents/skills/ui-ux-pro-max-skill/src/ui-ux-pro-max/scripts/search.py "<query>" --domain ux -f markdown
```

Use `--persist` only when the task explicitly needs saved design-system files. Otherwise keep search output ephemeral and summarize the decisions in the implementation or final response.

## Domains And Stacks

Use domains for design content:

- `product`: product/category fit and expected patterns
- `style`: visual style, effects, and anti-patterns
- `color`: industry and product color palettes
- `typography`: font pairings and type mood
- `chart`: chart type and data visualization guidance
- `landing`: landing-page section strategy
- `ux`: accessibility, interaction, layout, forms, loading, motion, and navigation
- `icons`: icon usage guidance
- `google-fonts`: individual font lookup
- `react` and `web`: framework/web app guidance from the data bundle

Use stacks for implementation details:

- Web: `html-tailwind`, `laravel`, `react`, `nextjs`, `vue`, `svelte`, `astro`, `angular`, `shadcn`, `threejs`
- Mobile/native: `flutter`, `react-native`, `swiftui`, `jetpack-compose`
- Nuxt: `nuxtjs`, `nuxt-ui`

For this EAES repo, default to `laravel` and `html-tailwind` for `Web/` portal work, and `flutter` for `Application/` scanner work.

## Implementation Rules

- Prefer dense, scannable, work-focused layouts for dashboards, admin panels, analytics, tables, exports, and review queues.
- Preserve explicit user constraints such as "keep dark mode as is", exact labels, or limited scope.
- Use semantic tokens or existing design variables before adding raw one-off colors.
- Use icons from the existing icon system where available; avoid emoji as structural UI.
- Make fixed-format UI stable with explicit dimensions, aspect ratios, grid tracks, or min/max constraints.
- Ensure forms and destructive actions have clear labels, inline errors, disabled/loading states, and recovery paths.
- For charts, include labels, legends/tooltips where useful, accessible color choices, and a text summary when the chart carries a key insight.
- Avoid visual novelty that weakens task completion: decorative orbs, one-hue palettes, oversized marketing composition in operational tools, and effects that reduce readability.

## Fallbacks

If Python is unavailable or the helper script fails, inspect the CSV files directly with `rg` or `Get-Content` under `.codex/skills/ui-ux-pro-max/data/` or `.agents/skills/ui-ux-pro-max-skill/src/ui-ux-pro-max/data/`. Continue with repo patterns and state that the helper could not run if that affects confidence.

If search results conflict with the existing product requirements or user instructions, follow the product/user constraint and mention the tradeoff briefly.

## Pre-Delivery Check

Before final delivery for UI work, verify the changed surface against the relevant items:

- Text contrast meets WCAG expectations in the affected themes.
- Keyboard focus, screen-reader labels, and touch targets are preserved.
- Mobile and desktop layouts avoid horizontal scroll, clipping, and overlap.
- Loading, empty, error, disabled, hover, focus, pressed, and selected states are coherent.
- Motion is purposeful, short, and compatible with reduced-motion preferences.
- Tables, charts, and dashboards prioritize scan speed and semantic status cues over decoration.
