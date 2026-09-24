---
paths:
  - '**'
---

# General

## Use full-path GitHub CLI with escalation
For GitHub Issues/PR work in Codex on Windows, prefer `& 'C:\Program Files\GitHub CLI\gh.exe' ...` when `gh` is not on PATH or auth behaves oddly. If sandboxed `gh` reports auth/keyring/token problems or times out unexpectedly, rerun the same full-path command with `sandbox_permissions: require_escalated` so the CLI can access the user's Windows keyring. Quote `@me` in PowerShell, e.g. `--add-assignee "@me"`, and use `--body-file` for multi-line issue/comment bodies.
