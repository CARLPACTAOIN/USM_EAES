---
type: readme
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# EAES Architecture Knowledge Vault

Open the `knowledge/` directory directly as an Obsidian vault.

This vault is a curated, human-facing architecture map. It does not replace Graphify, `PRD_V1.md`, `PRD_PROGRESS_AUDIT.md`, or source code.

## Sources

- `graphify-out/graph.json` for machine-readable structural relationships
- `graphify-out/GRAPH_REPORT.md` for graph-level context
- `PRD_V1.md` for product and architecture requirements
- `PRD_PROGRESS_AUDIT.md` for current implementation status
- Laravel and Flutter source files for implementation evidence

## Regenerate

From the repository root:

```powershell
python dev-orchestrator/sync_knowledge.py
```

The script is deterministic and preserves manually maintained content outside generated markers:

```text
<!-- BEGIN GENERATED:eaes-knowledge -->
...
<!-- END GENERATED:eaes-knowledge -->
```

Use Obsidian Local Graph from domain notes such as `[[Attendance]]`, `[[Event Management]]`, or `[[Scanner Mobile App]]` for the clearest navigation.

<!-- END GENERATED:eaes-knowledge -->
