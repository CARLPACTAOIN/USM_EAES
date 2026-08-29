from __future__ import annotations

from dev_orchestrator.models.reliability import FindingSeverity, HumanEscalation, ReviewFinding


def reviewer_node(state: dict[str, object]) -> dict[str, object]:
    findings = review_state(state)
    high_or_critical = [finding for finding in findings if finding.requires_human]
    escalations = _human_escalations(high_or_critical)
    status = "awaiting_human" if escalations else "reviewed"

    return {
        "structured_review_findings": [finding.model_dump(mode="json") for finding in findings],
        "review_findings": [finding.message for finding in findings] or ["No deterministic review findings."],
        "human_escalations": [escalation.model_dump(mode="json") for escalation in escalations],
        "human_action_required": bool(escalations),
        "human_action_reason": escalations[0].reason if escalations else "",
        "review_status": "needs_human" if escalations else "passed",
        "status": status,
        "final_status": status,
        "node_log": ["reviewer"],
    }


def review_state(state: dict[str, object]) -> list[ReviewFinding]:
    findings: list[ReviewFinding] = []
    assessment = state.get("assessment", {})
    context_packet = state.get("context_packet", {})
    changed_files = [str(path) for path in state.get("changed_files", [])]
    verification_commands = [str(command) for command in state.get("verification_commands", [])]
    verification_failures = state.get("verification_failures", [])
    execute = bool(state.get("execute", False))

    if not isinstance(assessment, dict):
        return [
            ReviewFinding(
                severity=FindingSeverity.High,
                category="assessment_missing",
                message="Task assessment is missing; cannot complete reliability review.",
                requires_human=True,
            )
        ]

    if assessment.get("needs_plan") and not state.get("plan"):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.High,
                category="plan_missing",
                message="Planner was required by triage but no plan is present.",
                evidence=str(assessment.get("triggered_rules", [])),
                requires_human=True,
            )
        )

    if not _packet_has(context_packet, "agents"):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.High,
                category="agents_context_missing",
                message="AGENTS.md context is missing; repository authority rules were not available for review.",
                source="agents",
                requires_human=True,
            )
        )

    applicable_skills = state.get("applicable_skills", [])
    if applicable_skills and not _packet_has(context_packet, "skills"):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.Medium,
                category="skill_context_missing",
                message="Relevant skills were selected, but their SKILL.md excerpts were not loaded in context.",
                evidence=", ".join(str(skill) for skill in applicable_skills if skill),
                source="skills",
                requires_human=False,
            )
        )

    if assessment.get("needs_prd_context") and not _packet_has(context_packet, "prd"):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.High,
                category="prd_context_missing",
                message="PRD-sensitive task is missing targeted PRD context.",
                source="prd",
                requires_human=True,
            )
        )

    if assessment.get("risk") == "critical" and not state.get("human_checkpoint_approved"):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.Critical,
                category="critical_risk_checkpoint_missing",
                message="Critical-risk task reached review without a recorded human checkpoint approval.",
                evidence=", ".join(assessment.get("triggered_rules", [])),
                source="triage",
                requires_human=True,
            )
        )

    if assessment.get("needs_security_review"):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.Medium,
                category="security_review_required",
                message="Security-sensitive task requires authorization and tenant-boundary review before merge.",
                evidence=", ".join(assessment.get("triggered_rules", [])),
                source="triage",
                requires_human=False,
            )
        )

    if assessment.get("needs_progress_audit_review"):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.Low,
                category="audit_review_required",
                message="Review PRD_PROGRESS_AUDIT.md after implementation if product progress materially changed.",
                source="prd_audit",
                requires_human=False,
            )
        )

    if assessment.get("needs_tests") and not verification_commands:
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.Medium,
                category="tests_missing",
                message="Task requires tests, but verifier did not select any verification command.",
                requires_human=False,
            )
        )

    if verification_failures:
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.High,
                category="verification_failed",
                message=f"Verification still has {len(verification_failures)} failing command(s).",
                source="verifier",
                requires_human=True,
            )
        )

    if execute and not changed_files and _expects_changes(assessment):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.Medium,
                category="execute_noop",
                message="Execute mode completed without new changed files for a task that appears to require implementation.",
                source="executor",
                requires_human=False,
            )
        )

    unrelated = _unrelated_changed_files(state, changed_files)
    if unrelated:
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.High,
                category="unrelated_changes",
                message="Execute mode produced changed files outside selected task context.",
                evidence=", ".join(unrelated),
                source="git",
                requires_human=True,
            )
        )

    if _has_prd_conflict_indicator(str(state.get("task", ""))):
        findings.append(
            ReviewFinding(
                severity=FindingSeverity.High,
                category="prd_conflict_possible",
                message="Task text indicates a PRD conflict or deviation; explicit human approval is required.",
                source="prd",
                requires_human=True,
            )
        )

    return findings


def _packet_has(context_packet: object, key: str) -> bool:
    return isinstance(context_packet, dict) and bool(context_packet.get(key))


def _expects_changes(assessment: dict[str, object]) -> bool:
    if assessment.get("complexity") == "trivial" or assessment.get("task_type") == "ui-copy":
        return False
    return str(assessment.get("task_type", "")) in {"implementation", "frontend-ui"}


def _unrelated_changed_files(state: dict[str, object], changed_files: list[str]) -> list[str]:
    if not state.get("execute", False):
        return []
    context_packet = state.get("context_packet", {})
    selected = set(context_packet.get("selected_files", [])) if isinstance(context_packet, dict) else set()
    repository_context = state.get("repository_context", {})
    if isinstance(repository_context, dict):
        target_paths = repository_context.get("target_paths", [])
        if isinstance(target_paths, list):
            selected.update(str(path) for path in target_paths)
    if not selected:
        return []
    if "." in selected:
        return []
    return [
        path
        for path in changed_files
        if ".runs/" not in path.replace("\\", "/") and path not in selected and not _same_area(path, selected)
    ]


def _same_area(path: str, selected: set[str]) -> bool:
    path_parts = path.split("/")
    for selected_path in selected:
        selected_parts = selected_path.split("/")
        if path_parts[:3] == selected_parts[:3]:
            return True
    return False


def _has_prd_conflict_indicator(task: str) -> bool:
    lowered = task.lower()
    return any(keyword in lowered for keyword in ["prd conflict", "off-prd", "deviation", "override prd"])


def _human_escalations(findings: list[ReviewFinding]) -> list[HumanEscalation]:
    if not findings:
        return []
    return [
        HumanEscalation(
            reason="Human review is required before continuing.",
            triggers=[finding.category for finding in findings],
            requested_action="Review the finding evidence, then approve, revise scope, or stop the task.",
            resumable=True,
        )
    ]
