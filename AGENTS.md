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
| `.agents/skills/` | Canonical skill suite for all AI agents (Antigravity, Cursor, Codex) — UI/UX, design, brand, Laravel, Flutter, testing, and project-specific skills |

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

- **All skills live in one canonical location:** `.agents/skills/`.
- **UI/UX routing entrypoint:** `.agents/skills/ui-ux-pro-max/SKILL.md`.
- Related design skills live beside it: `.agents/skills/ui-styling/`, `.agents/skills/design/`, `.agents/skills/design-system/`, `.agents/skills/brand/`, `.agents/skills/slides/`, and `.agents/skills/banner-design/`.
- **Upstream data bundle:** `.agents/skills/ui-ux-pro-max-skill/` (contains search scripts and source data).
- For any UI/UX task, start with `.agents/skills/ui-ux-pro-max/SKILL.md`, then use the relevant specialized skill under `.agents/skills/` when the task involves styling implementation, brand assets, design systems, slides, or banners; consult `.agents/skills/ui-ux-pro-max-skill/README.md` only when upstream package details are needed.
- Search/scripts live under `.agents/skills/ui-ux-pro-max/scripts/`; use `.agents/skills/ui-ux-pro-max/scripts/search.py` at repo root.
- Use the skill before choosing layout, visual style, typography, color systems, interaction patterns, or UX recommendations.
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

- Reference `PRD_V1.md`, `PRD_PROGRESS_AUDIT.md`, and the relevant `.agents/skills/` skill entrypoint directly when they are relevant to the task; for UI/UX routing, start with `.agents/skills/ui-ux-pro-max/SKILL.md`.
- Run Laravel commands from `Web/` (`composer`, `artisan`, `npm`).
- Do not commit secrets (`.env`, API keys, OAuth client secrets).
- If a task changes project progress in a meaningful way, update the audit in the same task before considering the work complete.
- Whenever recommending a next implementation step, explicitly state whether an implementation plan is materially necessary or whether work can proceed directly without one.
- Recommend a plan only when it would meaningfully improve effectiveness, coordination, or risk control; briefly explain why. Otherwise, state that implementation can proceed directly without a plan.
- Whenever recommending a next implementation step, also recommend the lowest-cost, fastest currently available Codex model or model tier that should still complete the task effectively, and briefly justify the choice. Do not default to the flagship model when a smaller model is sufficient.
- Use a lightweight model for documentation and narrow mechanical edits, a mid-tier coding model for bounded multi-file implementation with established patterns and tests, and the most capable model for ambiguous architecture, security or tenancy changes, complex migrations, broad cross-platform work, or repeated failed attempts. Prefer tier descriptions when exact model availability is unknown; verify current official guidance before naming a time-sensitive model.
- If you change `AGENTS.md`, `PRD_V1.md`, or `PRD_PROGRESS_AUDIT.md`, these files live at the repo root only.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project keeps committed, area-grouped rules in `.ai/rules` (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
