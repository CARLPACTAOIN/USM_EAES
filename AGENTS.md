# AGENTS.md

## Project Context

- **USM Event Attendance & Evaluation System (EAES)** — capstone multi-platform system (Laravel web API/portal in `Web/`, Flutter scanner app in `Application/`).
- This file governs how AI agents and contributors work in this repository.

## Source of Truth

- `PRD_V1.md` is the authoritative product and implementation source of truth for this repository.
- `PRD_V1.md` governs feature scope, business rules, architecture, flows, state behavior, API intent, and acceptance criteria.
- `PRD_PROGRESS_AUDIT.md` is the authoritative implementation-status tracker for the current branch against the PRD.
- `PRD_PROGRESS_AUDIT.md` does not replace, weaken, or override `PRD_V1.md`.
- If the current codebase and `PRD_V1.md` disagree, the PRD wins by default.

## Repository Layout

| Path | Purpose |
|------|---------|
| `PRD_V1.md` | Product requirements (authoritative) |
| `PRD_PROGRESS_AUDIT.md` | Implementation status vs PRD |
| `Web/` | Laravel 12 backend (API, queues, admin/student web when built) |
| `Application/` | Flutter scanner app (`eaes_scanner`; offline QR companion) |
| `.agent/skills/` | Local agent skills (UI/UX and others) |

## Mirrored documentation (repo root ↔ `Web/`)

These files exist in **two identical copies**. Content must stay in sync whenever any copy is edited:

| Repo root | `Web/` mirror |
|-----------|---------------|
| `AGENTS.md` | `Web/AGENTS.md` |
| `PRD_V1.md` | `Web/PRD_V1.md` |
| `PRD_PROGRESS_AUDIT.md` | `Web/PRD_PROGRESS_AUDIT.md` |

**Sync rules**

- After editing **any** of the files above, update the paired copy in the **same task** (do not leave mirrors diverged).
- From repo root, reconcile with: `.\scripts\sync-mirrored-docs.ps1` (newer file wins in `auto` mode).
- Force direction if needed: `-Direction root-to-web` or `-Direction web-to-root`.
- When working only inside `Web/`, local filenames apply (`PRD_V1.md` in this folder). Repo-wide assets remain at root: `../.agent/skills/`, `../.cursor/rules/`.

## PRD-First Workflow

- Before planning, modifying, or implementing work, read the relevant sections of `PRD_V1.md`.
- When changing an existing feature, review both the relevant PRD section and the corresponding entry in `PRD_PROGRESS_AUDIT.md`.
- Use PRD section headings when explaining why a change is being made (e.g. **§3.3 High-Concurrency Sync**, **Epic 5: Evaluation Gate**).
- Do not treat placeholders, static demo data, navigation stubs, or partial CRUD as completed PRD delivery unless the actual PRD behavior is implemented.
- Do not infer completed scope from UI presence alone; confirm the underlying behavior against the PRD.
- Prefer correcting implementation toward the PRD over preserving legacy behavior that predates the PRD.

## Conflict Handling

- If a user request conflicts with `PRD_V1.md`, do not implement it immediately.
- Call out the specific conflict and require an explicit override before proceeding.
- If an explicit override is given, treat the work as a PRD deviation rather than normal implementation.
- After implementing an approved deviation, record it in `PRD_PROGRESS_AUDIT.md` as off-PRD, temporary scope, or revised scope as appropriate.

## Progress Audit Maintenance

- After any repo-tracked change, review `PRD_PROGRESS_AUDIT.md`.
- Update `PRD_PROGRESS_AUDIT.md` only when the change materially affects implementation status, gaps, evidence, sprint notes, or off-PRD deviations.
- Preserve the audit's evidence-based style and existing status legend.
- Update only the impacted entries rather than rewriting unrelated sections.
- If progress did not materially change, no audit edit is required after review.
- If progress did materially change, the audit update is part of the same task and is not optional.

## UI/UX Skill Requirement

- **Authoritative bundle:** `.agent/skills/ui-ux-pro-max-skill/` (or `Web/.agent/skills/ui-ux-pro-max-skill/` — full repo v2.5.0).
- For any UI/UX task, read `.agent/skills/ui-ux-pro-max-skill/README.md` or `Web/.agent/skills/ui-ux-pro-max-skill/README.md` first before design decisions.
- Search/scripts live under `.agent/skills/ui-ux-pro-max-skill/src/ui-ux-pro-max/`; use `.agent/skills/ui-ux-pro-max-skill/src/ui-ux-pro-max/scripts/search.py` (or the equivalent in `Web/`) at repo root (junctioned to that source).
- Use the skill before choosing layout, visual style, typography, color systems, interaction patterns, or UX recommendations.
- Do not use the trimmed root `.cursor/skills/` copy alone if it is out of date; sync from `.agent/skills/ui-ux-pro-max-skill/` or `Web/.agent/skills/ui-ux-pro-max-skill/` when refreshing Cursor.
- Keep UI work aligned with `PRD_V1.md` and the current project direction.
- The UI/UX skill improves design quality and decision support, but it does not override product scope or PRD requirements.

## EAES Technical Defaults (from PRD)

When implementing backend work unless the PRD or an approved deviation says otherwise:

- **Database:** PostgreSQL 16, UUID primary keys on domain tables (see PRD §7).
- **Auth:** Google OAuth via Laravel Socialite (`@usm.edu.ph`), API tokens via Laravel Sanctum.
- **RBAC:** Spatie Laravel-Permission with tenant scoping by `organization_id` / `college_id`.
- **Queues:** Redis preferred for high-concurrency sync (PRD §3.3); database queue acceptable for local dev only if documented in the audit.
- **Mobile sync:** Idempotent bulk scan ingest, background jobs for resolution (e.g. `ResolveAttendanceJob`).

## Practical Rules

- Reference `PRD_V1.md`, `PRD_PROGRESS_AUDIT.md`, and `.agent/skills/ui-ux-pro-max-skill/README.md` (or `Web/.agent/skills/ui-ux-pro-max-skill/README.md`) directly when they are relevant to the task.
- Run Laravel commands from `Web/` (`composer`, `artisan`, `npm`).
- Do not commit secrets (`.env`, API keys, OAuth client secrets).
- If a task changes project progress in a meaningful way, update the audit in the same task before considering the work complete.
- If you change `AGENTS.md`, `PRD_V1.md`, or `PRD_PROGRESS_AUDIT.md`, update **both** root and `Web/` copies (or run `.\scripts\sync-mirrored-docs.ps1`).
