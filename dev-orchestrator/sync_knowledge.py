"""Generate a curated Obsidian architecture vault for the EAES repository.

The vault is intentionally human-facing. Graphify remains the machine-readable
source for structural code relationships; this script only uses it as evidence
for important implementation artifacts.
"""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any

BEGIN = "<!-- BEGIN GENERATED:eaes-knowledge -->"
END = "<!-- END GENERATED:eaes-knowledge -->"


@dataclass(frozen=True)
class Note:
    title: str
    folder: str
    kind: str
    body: str

    @property
    def path(self) -> Path:
        return Path("knowledge") / self.folder / f"{self.title}.md" if self.folder else Path("knowledge") / f"{self.title}.md"


def wiki(title: str) -> str:
    return f"[[{title}]]"


def bullets(values: list[str]) -> str:
    return "\n".join(f"- {value}" for value in values) if values else "- None identified."


def file_bullets(repo: Path, paths: list[str], graph_counts: dict[str, int]) -> str:
    lines: list[str] = []
    for path in paths:
        if (repo / path).exists():
            suffix = f" - Graphify nodes: {graph_counts.get(path, 0)}" if graph_counts.get(path, 0) else ""
            lines.append(f"- `{path}`{suffix}")
    return "\n".join(lines) if lines else "- No specific file references selected."


def implementation(repo: Path, graph_counts: dict[str, int], groups: dict[str, list[str]]) -> str:
    sections: list[str] = []
    for heading, paths in groups.items():
        body = file_bullets(repo, paths, graph_counts)
        if body != "- No specific file references selected.":
            sections.append(f"### {heading}\n{body}")
    return "\n\n".join(sections) if sections else "No implementation artifacts selected."


def generated_note(kind: str, body: str) -> str:
    return f"""---
type: {kind}
status: generated
source: graphify
---

{BEGIN}
{body.rstrip()}

{END}
"""


def replace_generated(existing: str, generated: str) -> str:
    if BEGIN not in existing or END not in existing:
        return generated

    before, rest = existing.split(BEGIN, 1)
    _, after = rest.split(END, 1)
    body = generated.split(BEGIN, 1)[1].split(END, 1)[0]
    return f"{before}{BEGIN}{body}{END}{after}"


def write_text_if_changed(path: Path, text: str) -> bool:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        old = path.read_text(encoding="utf-8")
        text = replace_generated(old, text)
        if old == text:
            return False
    path.write_text(text, encoding="utf-8", newline="\n")
    return True


def load_graph(repo: Path) -> tuple[dict[str, int], dict[str, dict[str, Any]], Counter[str]]:
    graph_path = repo / "graphify-out" / "graph.json"
    if not graph_path.exists():
        raise SystemExit("graphify-out/graph.json not found. Run: graphify extract . --code-only")

    data = json.loads(graph_path.read_text(encoding="utf-8"))
    node_by_label: dict[str, dict[str, Any]] = {}
    graph_counts: Counter[str] = Counter()

    for node in data.get("nodes", []):
        label = node.get("label")
        source_file = node.get("source_file")
        if isinstance(label, str) and label and label not in node_by_label:
            node_by_label[label] = node
        if isinstance(source_file, str) and source_file.startswith(("Web/", "Application/")):
            if not source_file.startswith(("Web/public/vendor/", "Application/windows/flutter/ephemeral/")):
                graph_counts[source_file] += 1

    return dict(graph_counts), node_by_label, Counter({k: v for k, v in graph_counts.items()})


DOMAINS: list[dict[str, Any]] = [
    {
        "title": "Event Management",
        "purpose": "Owns proposal drafting, PPA review, event schedules, participation scopes, event domains, and lifecycle state.",
        "related": ["Organizations and Membership", "Attendance", "Scanner Mobile App", "Evaluation", "Analytics and Exports", "Authorization and Tenant Scope"],
        "concepts": ["Event", "Event Day", "Participation Scope", "PPA", "Evaluation Form"],
        "workflows": ["PPA Approval", "Event Lifecycle", "Participant Scope Approval", "Attendance Rules"],
        "cross": ["Authorization and Tenant Scope", "Audit Logging", "Evaluation Gate"],
        "rules": [
            "PRD Epic 2 makes proposal approval separate from runtime lifecycle.",
            "Participant PPAs must be approved or marked not required before participant scanners, hydration, sync, and official analytics counting are allowed.",
            "Organizer attendance scope is automatically included for the organizing organization.",
            "HR-domain events bypass OSA PPA approval and use faculty/staff roster paths.",
        ],
        "impact": ["Attendance", "Scanner Mobile App", "Evaluation", "Analytics and Exports", "Authorization and Tenant Scope"],
        "implementation": {
            "Models": [
                "Web/app/Models/Event.php",
                "Web/app/Models/EventDay.php",
                "Web/app/Models/EventParticipationScope.php",
                "Web/app/Models/EvaluationFormVersion.php",
            ],
            "Services and Support": [
                "Web/app/Services/EventProposalDraftService.php",
                "Web/app/Services/EventLifecycleService.php",
                "Web/app/Services/ParticipationScopeService.php",
                "Web/app/Services/DashboardEventWorkspaceService.php",
                "Web/app/Support/EventTenantScope.php",
                "Web/app/Support/EventEvaluationForm.php",
            ],
            "Controllers and Livewire": [
                "Web/app/Http/Controllers/Web/DashboardController.php",
                "Web/app/Http/Controllers/Api/EventProposalController.php",
                "Web/app/Livewire/Dashboard/EventProposalCreate.php",
                "Web/app/Livewire/Dashboard/EventProposalWorkflow.php",
                "Web/app/Livewire/Dashboard/EventParticipantScopes.php",
                "Web/app/Livewire/Dashboard/EventAttendanceRules.php",
                "Web/app/Livewire/Dashboard/EventsWorkspace.php",
            ],
            "Tests": [
                "Web/tests/Feature/WebPortalTest.php",
                "Web/tests/Feature/ParticipantScopeConcurrencyTest.php",
                "Web/tests/Feature/UsmEaesTest.php",
            ],
        },
    },
    {
        "title": "Attendance",
        "purpose": "Turns raw scanner captures into canonical attendance records using EILO, duplicate protection, pending-link handling, and evaluation-gated validity.",
        "related": ["Event Management", "Scanner Mobile App", "Evaluation", "Analytics and Exports", "Organizations and Membership", "Queue and Concurrency"],
        "concepts": ["Raw Scan", "Attendance Record", "Pending Student Link", "Scanner Session", "Participation Scope"],
        "workflows": ["Attendance Scanning", "Offline Attendance Sync", "EILO Resolution", "Evaluation Completion", "Attendance Rules"],
        "cross": ["Offline Sync", "Queue and Concurrency", "Evaluation Gate", "Audit Logging", "Authorization and Tenant Scope"],
        "rules": [
            "PRD §3.3 requires idempotent bulk sync and queued attendance resolution for high-concurrency events.",
            "Raw scans are persisted first; canonical attendance records are resolved by background processing.",
            "EILO keeps earliest time-in and latest time-out per participant/day/scope.",
            "Attendance validity remains false until required evaluation completion unless force validation applies.",
        ],
        "impact": ["Scanner Mobile App", "Evaluation", "Analytics and Exports", "Event Management", "Queue and Concurrency"],
        "implementation": {
            "Models": [
                "Web/app/Models/RawScan.php",
                "Web/app/Models/AttendanceRecord.php",
                "Web/app/Models/PendingStudentLink.php",
            ],
            "Services and Jobs": [
                "Web/app/Services/AttendanceSyncPersistenceService.php",
                "Web/app/Services/QueueOutboxService.php",
                "Web/app/Jobs/ResolveAttendanceJob.php",
            ],
            "Controllers and Livewire": [
                "Web/app/Http/Controllers/Api/AttendanceSyncController.php",
                "Web/app/Http/Controllers/Api/PendingStudentLinkController.php",
                "Web/app/Livewire/Dashboard/PendingQrLinks.php",
                "Web/app/Livewire/Dashboard/EventAttendanceRules.php",
            ],
            "Tests": [
                "Web/tests/Feature/AttendanceSyncConcurrencyTest.php",
                "Web/tests/Postgres/AttendanceSyncPostgresConcurrencyTest.php",
                "Web/tests/Feature/UsmEaesTest.php",
            ],
        },
    },
    {
        "title": "Scanner Mobile App",
        "purpose": "Provides the Flutter companion app for account-authenticated scanner assignment activation, roster hydration, offline capture, and retry-safe sync.",
        "related": ["Attendance", "Event Management", "Authentication and Access Control", "Offline Sync", "Device Identity"],
        "concepts": ["Scanner Assignment", "Scanner Session", "Raw Scan", "Roster Student", "Device Identity"],
        "workflows": ["Scanner Assignment and Activation", "Attendance Scanning", "Offline Attendance Sync"],
        "cross": ["Offline Sync", "Device Binding", "Authorization and Tenant Scope", "Queue and Concurrency"],
        "rules": [
            "PRD Epic 3 requires account-authenticated event-scoped scanner sessions; normal student login is not enough for hydrate or sync.",
            "The mobile app can continue syncing previously captured valid offline scans during allowed grace paths.",
            "The backend remains the security boundary for scanner access, scope, scan windows, and session validity.",
            "The local SQLite store must preserve failed and pending scans for retry.",
        ],
        "impact": ["Attendance", "Authorization and Tenant Scope", "Queue and Concurrency", "Analytics and Exports"],
        "implementation": {
            "Flutter State and API": [
                "Application/lib/features/scanner/presentation/scanner_controller.dart",
                "Application/lib/features/scanner/data/scanner_api_client.dart",
                "Application/lib/features/scanner/data/scanner_local_database.dart",
                "Application/lib/features/scanner/data/scanner_contracts.dart",
            ],
            "Flutter Domain": [
                "Application/lib/features/scanner/domain/scanner_session.dart",
                "Application/lib/features/scanner/domain/offline_scan.dart",
                "Application/lib/features/scanner/domain/roster_student.dart",
                "Application/lib/features/scanner/domain/scan_type.dart",
                "Application/lib/features/scanner/domain/scan_sync_status.dart",
                "Application/lib/features/scanner/domain/scanner_error_mapper.dart",
            ],
            "Flutter Screens and Widgets": [
                "Application/lib/features/scanner/presentation/scanner_home_screen.dart",
                "Application/lib/features/scanner/presentation/screens/session_setup_screen.dart",
                "Application/lib/features/scanner/presentation/screens/live_scanner_screen.dart",
                "Application/lib/features/scanner/presentation/screens/offline_tools_screen.dart",
                "Application/lib/features/scanner/presentation/screens/hydrated_roster_screen.dart",
                "Application/lib/features/scanner/presentation/widgets/scanner_viewfinder.dart",
                "Application/lib/features/scanner/presentation/widgets/sync_status_panel.dart",
            ],
            "Backend Scanner Boundary": [
                "Web/app/Models/EventScannerAssignment.php",
                "Web/app/Models/ScannerSession.php",
                "Web/app/Services/ScannerAssignmentService.php",
                "Web/app/Services/ScannerSessionService.php",
                "Web/app/Http/Controllers/Api/ScannerAssignmentController.php",
                "Web/app/Support/ScannerAccess.php",
            ],
            "Tests": [
                "Application/test/scanner_controller_test.dart",
                "Application/test/scanner_api_client_test.dart",
                "Application/test/scanner_session_test.dart",
                "Web/tests/Feature/UsmEaesTest.php",
            ],
        },
    },
    {
        "title": "Organizations and Membership",
        "purpose": "Models USM colleges, programs, organizations, membership records, employee rosters, and admin assignment scopes.",
        "related": ["Authentication and Access Control", "Event Management", "Attendance", "Analytics and Exports", "HR Attendance"],
        "concepts": ["Organization", "College", "Program", "Organization Membership", "Admin Assignment", "Employee Roster"],
        "workflows": ["Admin Assignment Governance", "Participant Scope Approval", "Organization Analytics"],
        "cross": ["Authorization and Tenant Scope", "Audit Logging"],
        "rules": [
            "PRD Epic 1.3 makes admin_assignments the source of truth for administrative authority and tenant scope.",
            "Student profile membership fields are not admin tenant scope.",
            "Colleges and programs are official master data maintained by OSA/Super Admin.",
            "Organization memberships feed rosters, eligibility, and analytics denominators.",
        ],
        "impact": ["Authorization and Tenant Scope", "Event Management", "Scanner Mobile App", "Analytics and Exports"],
        "implementation": {
            "Models": [
                "Web/app/Models/Organization.php",
                "Web/app/Models/College.php",
                "Web/app/Models/Program.php",
                "Web/app/Models/OrganizationMembership.php",
                "Web/app/Models/AdminAssignment.php",
                "Web/app/Models/EmployeeProfile.php",
                "Web/app/Models/EmployeeRosterEntry.php",
            ],
            "Services and Support": [
                "Web/app/Services/OrganizationManagementService.php",
                "Web/app/Services/AdminAssignmentService.php",
                "Web/app/Services/AdminScopeService.php",
                "Web/app/Support/AdminScope.php",
                "Web/app/Support/YearLevels.php",
            ],
            "Controllers and Livewire": [
                "Web/app/Http/Controllers/Web/AdminAccessController.php",
                "Web/app/Http/Controllers/Web/OrganizationProfileController.php",
                "Web/app/Livewire/Dashboard/AdminAccessWorkspace.php",
            ],
            "Tests": [
                "Web/tests/Feature/AdminOnboardingTest.php",
                "Web/tests/Feature/OrganizationAnalyticsTest.php",
                "Web/tests/Feature/WebPortalTest.php",
            ],
        },
    },
    {
        "title": "Evaluation",
        "purpose": "Manages event-specific evaluation forms, student submissions, validity gates, evaluation windows, and sentiment inputs.",
        "related": ["Event Management", "Attendance", "Analytics and Exports", "AI Insights"],
        "concepts": ["Evaluation Submission", "Evaluation Form", "Attendance Record", "Event"],
        "workflows": ["Evaluation Completion", "AI Sentiment and NLP"],
        "cross": ["Evaluation Gate", "Authorization and Tenant Scope", "Audit Logging"],
        "rules": [
            "PRD Epic 5 requires event-specific locked form snapshots approved with the event proposal.",
            "Evaluation submission toggles the attendance valid flag when the gate applies.",
            "The evaluation window closes 24 hours after the event or final event day ends.",
            "Sentiment analysis uses anonymized comments and writes sentiment fields back to evaluations.",
        ],
        "impact": ["Attendance", "Analytics and Exports", "AI Insights", "Event Management"],
        "implementation": {
            "Models and Support": [
                "Web/app/Models/Evaluation.php",
                "Web/app/Models/EvaluationFormVersion.php",
                "Web/app/Support/EventEvaluationForm.php",
                "Web/app/Support/EvaluationWindow.php",
                "Web/app/Support/EvaluationQuestions.php",
            ],
            "Controllers and Livewire": [
                "Web/app/Http/Controllers/Api/EvaluationController.php",
                "Web/app/Http/Controllers/Web/PortalController.php",
                "Web/app/Livewire/Dashboard/EventSentimentAction.php",
            ],
            "Jobs and Services": [
                "Web/app/Jobs/AnalyzeEventSentimentsJob.php",
                "Web/app/Services/GeminiService.php",
                "Web/app/Services/EventAnalyticsService.php",
            ],
            "Tests": [
                "Web/tests/Feature/WebPortalTest.php",
                "Web/tests/Feature/UsmEaesTest.php",
                "Web/tests/Feature/EventAnalyticsPageTest.php",
            ],
        },
    },
    {
        "title": "Analytics and Exports",
        "purpose": "Builds per-event analytics, organization performance metrics, Gawad Parangal indicators, and scoped PDF/Excel exports.",
        "related": ["Attendance", "Evaluation", "Organizations and Membership", "Event Management", "AI Insights"],
        "concepts": ["Attendance Record", "Evaluation Submission", "Organization", "Event"],
        "workflows": ["Organization Analytics", "Analytics Export", "AI Sentiment and NLP"],
        "cross": ["Caching", "Authorization and Tenant Scope", "Audit Logging"],
        "rules": [
            "PRD Epic 6 requires attendance percentages, evaluation averages, demographic breakdowns, and exportable summaries.",
            "Analytics must honor admin assignment tenant scope.",
            "Exports include event metadata, attendance validity, evaluation ratings, comments, sentiment fields, timestamps, and generating user.",
            "Organization analytics separate system-measurable indicators from deferred manual non-system metrics.",
        ],
        "impact": ["Attendance", "Evaluation", "Organizations and Membership", "AI Insights"],
        "implementation": {
            "Services and Support": [
                "Web/app/Services/EventAnalyticsService.php",
                "Web/app/Services/OrganizationAnalyticsService.php",
                "Web/app/Services/EventAnalyticsCache.php",
                "Web/app/Support/EventExportData.php",
            ],
            "Controllers and Livewire": [
                "Web/app/Http/Controllers/Api/AnalyticsController.php",
                "Web/app/Http/Controllers/Web/EventAnalyticsController.php",
                "Web/app/Http/Controllers/Web/AnalyticsOverviewController.php",
                "Web/app/Http/Controllers/Web/EventExportController.php",
                "Web/app/Livewire/Dashboard/AnalyticsOverviewFilters.php",
                "Web/app/Livewire/Dashboard/EventAnalyticsParticipantFilters.php",
            ],
            "Exports and Observers": [
                "Web/app/Exports/EventAttendanceExport.php",
                "Web/app/Exports/EventEvaluationExport.php",
                "Web/app/Observers/EventAnalyticsCacheInvalidationObserver.php",
                "Web/app/Observers/EventAnalyticsAudienceInvalidationObserver.php",
            ],
            "Tests": [
                "Web/tests/Feature/EventAnalyticsPageTest.php",
                "Web/tests/Feature/OrganizationAnalyticsTest.php",
                "Web/tests/Feature/EventAnalyticsCacheTest.php",
            ],
        },
    },
    {
        "title": "Authentication and Access Control",
        "purpose": "Handles institutional login, Sanctum API tokens, Spatie role projection, tenant-scoped admin authority, and scanner token boundaries.",
        "related": ["Organizations and Membership", "Scanner Mobile App", "Event Management", "Attendance", "AI Insights"],
        "concepts": ["User", "Admin Assignment", "Scanner Assignment", "Scanner Session", "Organization"],
        "workflows": ["Google Authentication", "Admin Assignment Governance", "Scanner Assignment and Activation"],
        "cross": ["Authorization and Tenant Scope", "Device Binding", "Audit Logging"],
        "rules": [
            "Institutional Google login must enforce the usm.edu.ph domain.",
            "Sanctum tokens are used for API/mobile paths, including scanner sessions.",
            "Spatie roles are permission projection; admin_assignments carry active tenant authority.",
            "Scanner hydrate and sync require an activated device-bound scanner session token.",
        ],
        "impact": ["Event Management", "Scanner Mobile App", "Attendance", "Analytics and Exports", "AI Insights"],
        "implementation": {
            "Models and Middleware": [
                "Web/app/Models/User.php",
                "Web/app/Models/AdminAssignment.php",
                "Web/app/Http/Middleware/TenantScopeMiddleware.php",
                "Web/app/Http/Middleware/SecurityHeaders.php",
            ],
            "Controllers and Services": [
                "Web/app/Http/Controllers/Auth/GoogleAuthController.php",
                "Web/app/Http/Controllers/Api/AuthController.php",
                "Web/app/Services/AdminScopeService.php",
                "Web/app/Services/AdminAssignmentService.php",
                "Web/app/Services/ScannerSessionService.php",
            ],
            "Flutter Auth": [
                "Application/lib/features/auth/data/auth_api_client.dart",
                "Application/lib/features/auth/data/google_auth_gateway.dart",
                "Application/lib/features/auth/data/device_identity_provider.dart",
                "Application/lib/features/auth/presentation/auth_controller.dart",
                "Application/lib/features/auth/presentation/auth_gate.dart",
            ],
            "Tests": [
                "Web/tests/Feature/GoogleAuthControllerTest.php",
                "Web/tests/Feature/MobileGoogleAuthTest.php",
                "Application/test/auth_api_client_test.dart",
                "Application/test/auth_google_sign_in_test.dart",
                "Application/test/device_identity_provider_test.dart",
            ],
        },
    },
    {
        "title": "AI Insights",
        "purpose": "Provides sentiment analysis and scoped NLP query assistance through swappable AI services.",
        "related": ["Evaluation", "Analytics and Exports", "Authentication and Access Control", "Queue and Concurrency"],
        "concepts": ["Evaluation Submission", "Event", "Organization", "Admin Assignment"],
        "workflows": ["AI Sentiment and NLP", "Organization Analytics"],
        "cross": ["Authorization and Tenant Scope", "Queue and Concurrency", "Caching"],
        "rules": [
            "PRD §6.2 uses anonymized evaluation comments for sentiment classification.",
            "PRD §6.3 requires tenant scope to be hard-applied around NLP query execution.",
            "AI providers are behind AiServiceInterface, with Gemini and Ollama implementations present.",
            "AI jobs should use bounded retry and queue behavior rather than blocking user workflows.",
        ],
        "impact": ["Evaluation", "Analytics and Exports", "Authorization and Tenant Scope", "Queue and Concurrency"],
        "implementation": {
            "Services and Support": [
                "Web/app/Services/Contracts/AiServiceInterface.php",
                "Web/app/Services/GeminiService.php",
                "Web/app/Services/OllamaService.php",
                "Web/app/Support/NlpQueryExecutor.php",
            ],
            "Controllers and Livewire": [
                "Web/app/Http/Controllers/Web/AiInsightsController.php",
                "Web/app/Http/Controllers/Api/NlpQueryController.php",
                "Web/app/Livewire/Dashboard/AiInsightsQuery.php",
                "Web/app/Livewire/Dashboard/EventSentimentAction.php",
            ],
            "Jobs and Commands": [
                "Web/app/Jobs/AnalyzeEventSentimentsJob.php",
                "Web/routes/console.php",
            ],
            "Tests": [
                "Web/tests/Feature/UsmEaesTest.php",
                "Web/tests/Feature/WebPortalTest.php",
                "Web/tests/Feature/QueueResilienceTest.php",
            ],
        },
    },
    {
        "title": "HR Attendance",
        "purpose": "Covers HR-domain events, faculty/staff onboarding separation, employee roster verification, and HR-specific scanner hydration.",
        "related": ["Organizations and Membership", "Event Management", "Scanner Mobile App", "Attendance", "Analytics and Exports"],
        "concepts": ["Employee Roster", "Event", "Scanner Session", "Attendance Record"],
        "workflows": ["Google Authentication", "Scanner Assignment and Activation", "Offline Attendance Sync"],
        "cross": ["Authorization and Tenant Scope", "Offline Sync", "Device Binding"],
        "rules": [
            "PRD Epic 2.4 separates HR events through events.event_domain.",
            "HR events use faculty/staff attendance rosters and do not require OSA PPA approval.",
            "Faculty/staff trust comes from HR roster verification or imported employee records.",
            "HR analytics and exports must stay scoped to HR-domain authority.",
        ],
        "impact": ["Organizations and Membership", "Authentication and Access Control", "Scanner Mobile App", "Analytics and Exports"],
        "implementation": {
            "Models": [
                "Web/app/Models/EmployeeProfile.php",
                "Web/app/Models/EmployeeRosterEntry.php",
                "Web/app/Models/Event.php",
                "Web/app/Models/ScannerSession.php",
            ],
            "Services": [
                "Web/app/Services/ParticipationScopeService.php",
                "Web/app/Services/ScannerSessionService.php",
                "Web/app/Services/OrganizationAnalyticsService.php",
            ],
            "Controllers": [
                "Web/app/Http/Controllers/Api/AttendanceSyncController.php",
                "Web/app/Http/Controllers/Api/ScannerAssignmentController.php",
                "Web/app/Http/Controllers/Api/AuthController.php",
            ],
            "Tests": [
                "Web/tests/Feature/UsmEaesTest.php",
                "Web/tests/Feature/MobileGoogleAuthTest.php",
                "Web/tests/Feature/OrganizationAnalyticsTest.php",
            ],
        },
    },
]


CONCEPTS: list[dict[str, Any]] = [
    {
        "title": "Event",
        "meaning": "The central proposal and runtime object for an activity, with approval status, event domain, schedule, lifecycle phase, attendance policy, evaluation form, and analytics context.",
        "belongs": ["Event Management"],
        "relationships": ["owned by [[Organization]]", "scheduled through [[Event Day]]", "has [[Participation Scope]] records", "produces [[Raw Scan]] and [[Attendance Record]] data", "may require [[Evaluation Form]] and [[Evaluation Submission]] records"],
        "implementation": ["Web/app/Models/Event.php", "Web/app/Services/EventLifecycleService.php", "Web/app/Services/EventProposalDraftService.php"],
        "constraints": ["Proposal status and lifecycle phase are separate.", "Runtime scanners stay locked until proposal approval and scope approval rules pass.", "Event-level late cutoff overrides participant-scope fallback when set."],
    },
    {
        "title": "Event Day",
        "meaning": "A scheduled day within an event used to derive scan windows, daily EILO grouping, and multi-day attendance records.",
        "belongs": ["Event Management"],
        "relationships": ["belongs to [[Event]]", "anchors [[Attendance Record]] records", "bounds [[Attendance Scanning]]"],
        "implementation": ["Web/app/Models/EventDay.php", "Web/app/Services/EventLifecycleService.php", "Application/lib/features/scanner/domain/event_schedule_day.dart"],
        "constraints": ["Dates and times are treated as USM local schedule time.", "Multi-day events produce daily attendance interpretation."],
    },
    {
        "title": "Organization",
        "meaning": "A USM governance or student-affairs unit such as USG, LSG, society, non-academic organization, academic unit, or office organizer.",
        "belongs": ["Organizations and Membership"],
        "relationships": ["contains [[Organization Membership]]", "belongs under [[College]] when scoped", "owns [[Event]] proposals", "receives [[Participation Scope]] invitations", "feeds [[Analytics and Exports]]"],
        "implementation": ["Web/app/Models/Organization.php", "Web/app/Services/OrganizationManagementService.php", "Web/app/Http/Controllers/Web/OrganizationProfileController.php"],
        "constraints": ["Organization creation and maintenance are OSA-controlled.", "Organization identity is not equivalent to admin authority; active admin assignments are checked separately."],
    },
    {
        "title": "College",
        "meaning": "Institutional academic grouping used for programs, LSG scope, participant scopes, and analytics filters.",
        "belongs": ["Organizations and Membership"],
        "relationships": ["contains [[Program]] records", "may bound [[Organization]] records", "can be the tenant scope of an [[Admin Assignment]]"],
        "implementation": ["Web/app/Models/College.php", "Web/app/Models/Program.php", "Web/app/Support/AdminScope.php"],
        "constraints": ["Colleges are official master data maintained by OSA/Super Admin."],
    },
    {
        "title": "Program",
        "meaning": "Academic program under a college, used for student profile demographics, organization membership, roster scope, and analytics filtering.",
        "belongs": ["Organizations and Membership"],
        "relationships": ["belongs to [[College]]", "maps to [[Organization]] through organization_programs", "classifies [[User]] profile and [[Organization Membership]] records"],
        "implementation": ["Web/app/Models/Program.php", "Web/app/Support/YearLevels.php"],
        "constraints": ["Program and year level are analytics/profile dimensions, not admin tenant authority."],
    },
    {
        "title": "User",
        "meaning": "The authenticated institutional account for students, faculty/staff, admins, scanners, and portal users.",
        "belongs": ["Authentication and Access Control"],
        "relationships": ["has [[Organization Membership]] records", "may hold active [[Admin Assignment]] records", "may be assigned as a [[Scanner Assignment]]", "submits [[Evaluation Submission]]"],
        "implementation": ["Web/app/Models/User.php", "Web/app/Http/Controllers/Auth/GoogleAuthController.php", "Web/app/Http/Controllers/Api/AuthController.php"],
        "constraints": ["Institutional Google login enforces usm.edu.ph.", "Student profile membership fields must not be treated as admin tenant scope."],
    },
    {
        "title": "Admin Assignment",
        "meaning": "The active, term-bound record that gives a user administrative authority over an organization, college, HR, academic unit, or office scope.",
        "belongs": ["Authentication and Access Control", "Organizations and Membership"],
        "relationships": ["grants authority to [[User]]", "scopes actions over [[Organization]] or [[College]]", "guards [[Event Management]], [[Analytics and Exports]], and scanner management"],
        "implementation": ["Web/app/Models/AdminAssignment.php", "Web/app/Services/AdminAssignmentService.php", "Web/app/Services/AdminScopeService.php", "Web/app/Support/AdminScope.php"],
        "constraints": ["Admin assignments are the source of truth for tenant authority.", "Spatie roles are synchronized as the permission projection."],
    },
    {
        "title": "Organization Membership",
        "meaning": "A user's membership relationship to an organization, with membership type, academic year, verification status, college/program attribution, and year-level metadata.",
        "belongs": ["Organizations and Membership"],
        "relationships": ["connects [[User]] to [[Organization]]", "contributes to scanner rosters", "feeds [[Analytics and Exports]] denominators"],
        "implementation": ["Web/app/Models/OrganizationMembership.php", "Web/app/Services/ParticipationScopeService.php"],
        "constraints": ["Membership supports student-affairs rosters and analytics; it does not grant admin authority by itself."],
    },
    {
        "title": "Employee Roster",
        "meaning": "Faculty/staff verification data used by HR-domain attendance and scanner hydration.",
        "belongs": ["HR Attendance"],
        "relationships": ["verifies faculty/staff [[User]] accounts", "feeds [[Scanner Mobile App]] hydration for HR events", "produces [[Attendance Record]] data for HR events"],
        "implementation": ["Web/app/Models/EmployeeProfile.php", "Web/app/Models/EmployeeRosterEntry.php"],
        "constraints": ["HR roster verification is the trust boundary for faculty/staff attendance scanning."],
    },
    {
        "title": "Participation Scope",
        "meaning": "The event audience and ownership unit for organizer and participant attendance scopes, PPA status, capture mode, scanner authority, and analytics roll-up.",
        "belongs": ["Event Management", "Attendance"],
        "relationships": ["belongs to [[Event]]", "targets [[Organization]], [[College]], or [[Program]]", "gates [[Scanner Assignment]] and [[Scanner Session]]", "scopes [[Raw Scan]] and [[Attendance Record]]"],
        "implementation": ["Web/app/Models/EventParticipationScope.php", "Web/app/Services/ParticipationScopeService.php", "Web/app/Livewire/Dashboard/EventParticipantScopes.php"],
        "constraints": ["Participant PPAs must be approved or not required before official scanning/counting.", "Organizer scope is always represented for the organizing organization."],
    },
    {
        "title": "PPA",
        "meaning": "The official proposal/permit packet for event approval, including proposal metadata, schedule, budget, official document, signatures, review status, and evaluation form.",
        "belongs": ["Event Management"],
        "relationships": ["approves [[Event]]", "may be required on [[Participation Scope]]", "locks [[Evaluation Form]] for attendees"],
        "implementation": ["Web/app/Services/EventProposalDraftService.php", "Web/app/Livewire/Dashboard/EventProposalWorkflow.php", "Web/app/Http/Controllers/Api/EventProposalController.php"],
        "constraints": ["Scanner access remains locked until the relevant proposal/scope approval passes.", "Revision requests return the proposal to the organizer; rejection is final unless administratively reopened."],
    },
    {
        "title": "Scanner Assignment",
        "meaning": "The admin-created assignment that authorizes a specific institutional account to activate scanner mode for an event participation scope.",
        "belongs": ["Scanner Mobile App", "Authentication and Access Control"],
        "relationships": ["assigned to [[User]]", "targets [[Event]] and [[Participation Scope]]", "activates [[Scanner Session]]"],
        "implementation": ["Web/app/Models/EventScannerAssignment.php", "Web/app/Services/ScannerAssignmentService.php", "Web/app/Http/Controllers/Api/ScannerAssignmentController.php"],
        "constraints": ["Assignment is account-scoped and revocable.", "It does not itself replace the runtime scanner session token."],
    },
    {
        "title": "Scanner Session",
        "meaning": "The runtime, device-bound scanner token used by the Flutter app for validation, roster hydration, offline capture context, and sync.",
        "belongs": ["Scanner Mobile App", "Attendance"],
        "relationships": ["created from [[Scanner Assignment]]", "scopes [[Raw Scan]] ingestion", "uses [[Device Identity]]", "validates access to [[Offline Sync]]"],
        "implementation": ["Web/app/Models/ScannerSession.php", "Web/app/Services/ScannerSessionService.php", "Application/lib/features/scanner/domain/scanner_session.dart"],
        "constraints": ["Hydrate and sync require the activated scanner session token.", "Device binding is validated at activation/hydration/sync time."],
    },
    {
        "title": "Raw Scan",
        "meaning": "A persisted scan candidate from the scanner, including event, scope, day, scan type, device/session audit fields, and deduplication identity.",
        "belongs": ["Attendance"],
        "relationships": ["captured by [[Scanner Session]]", "belongs to [[Event]] and [[Participation Scope]]", "resolves into [[Attendance Record]]", "may create [[Pending Student Link]]"],
        "implementation": ["Web/app/Models/RawScan.php", "Web/app/Services/AttendanceSyncPersistenceService.php", "Application/lib/features/scanner/domain/offline_scan.dart"],
        "constraints": ["Dedup keys and database constraints protect idempotency.", "Unresolved scans are preserved rather than discarded."],
    },
    {
        "title": "Attendance Record",
        "meaning": "The canonical attendance row for a participant/event/day/scope after EILO resolution and validity gating.",
        "belongs": ["Attendance"],
        "relationships": ["resolved from [[Raw Scan]]", "belongs to [[Event]] and [[Participation Scope]]", "is validated by [[Evaluation Submission]]", "feeds [[Analytics and Exports]]"],
        "implementation": ["Web/app/Models/AttendanceRecord.php", "Web/app/Jobs/ResolveAttendanceJob.php", "Web/app/Services/EventAnalyticsService.php"],
        "constraints": ["Official punctuality status uses event cutoff first, then participant-scope fallback.", "The valid flag is controlled by evaluation-gate rules unless force validation applies."],
    },
    {
        "title": "Pending Student Link",
        "meaning": "A dashboard-reviewable unresolved scan that could not be matched to a registered user during server-side resolution.",
        "belongs": ["Attendance"],
        "relationships": ["created from [[Raw Scan]]", "resolved or flagged by authorized admins", "updates [[Attendance Record]] possibilities"],
        "implementation": ["Web/app/Models/PendingStudentLink.php", "Web/app/Livewire/Dashboard/PendingQrLinks.php", "Web/app/Http/Controllers/Api/PendingStudentLinkController.php"],
        "constraints": ["Unknown QR/manual IDs should be reviewable without exposing raw secrets unnecessarily.", "Resolution is tenant-scoped and audited."],
    },
    {
        "title": "Evaluation Submission",
        "meaning": "A student's event evaluation submission with scored responses, optional comment, sentiment result, and submitted timestamp.",
        "belongs": ["Evaluation"],
        "relationships": ["belongs to [[Event]] and [[User]]", "answers [[Evaluation Form]]", "validates [[Attendance Record]]", "feeds [[Analytics and Exports]] and [[AI Insights]]"],
        "implementation": ["Web/app/Models/Evaluation.php", "Web/app/Http/Controllers/Api/EvaluationController.php", "Web/app/Http/Controllers/Web/PortalController.php"],
        "constraints": ["Submission is event-specific and must match the locked question map.", "The submission window closes 24 hours after the event/final day ends."],
    },
    {
        "title": "Roster Student",
        "meaning": "The compact local scanner representation of a participant identity used for offline lookup and confirmation cards.",
        "belongs": ["Scanner Mobile App", "Attendance"],
        "relationships": ["hydrated from [[Participation Scope]]", "looked up during [[Attendance Scanning]]", "helps create [[Raw Scan]] records"],
        "implementation": ["Application/lib/features/scanner/domain/roster_student.dart", "Application/lib/features/scanner/data/scanner_local_database.dart", "Web/app/Http/Controllers/Api/AttendanceSyncController.php"],
        "constraints": ["Roster data is limited to essential identity metadata for offline scanning.", "Backend scope truth remains authoritative when local roster data is stale."],
    },
    {
        "title": "Evaluation Form",
        "meaning": "The locked event-level evaluation question snapshot reviewed with the proposal and answered by attendees.",
        "belongs": ["Evaluation", "Event Management"],
        "relationships": ["attached to [[Event]]", "answered by [[Evaluation Submission]]", "affects [[Analytics and Exports]] question labels"],
        "implementation": ["Web/app/Models/EvaluationFormVersion.php", "Web/app/Support/EventEvaluationForm.php", "Web/app/Support/EvaluationQuestions.php"],
        "constraints": ["Approval requires a locked event-level snapshot where the PRD gate applies."],
    },
    {
        "title": "Device Identity",
        "meaning": "The stable mobile-device identity used to bind scanner sessions and support same-device resume while blocking unauthorized transfer.",
        "belongs": ["Scanner Mobile App"],
        "relationships": ["binds [[Scanner Session]]", "sent during [[Scanner Assignment and Activation]]", "stamps [[Raw Scan]] context"],
        "implementation": ["Application/lib/features/auth/data/device_identity_provider.dart", "Application/test/device_identity_provider_test.dart", "Web/app/Services/ScannerSessionService.php"],
        "constraints": ["Device identity is not a durable attendance record; it is a scanner security and continuity signal."],
    },
]


CROSS_CUTTING: list[dict[str, Any]] = [
    {
        "title": "Authorization and Tenant Scope",
        "purpose": "Keeps all admin, scanner, analytics, export, and pending-link actions inside active role and tenant boundaries.",
        "related": ["Authentication and Access Control", "Organizations and Membership", "Event Management", "Attendance", "Analytics and Exports", "AI Insights"],
        "concepts": ["Admin Assignment", "User", "Organization", "College", "Scanner Session"],
        "implementation": {
            "Core": ["Web/app/Services/AdminScopeService.php", "Web/app/Support/AdminScope.php", "Web/app/Http/Middleware/TenantScopeMiddleware.php"],
            "Boundaries": ["Web/app/Support/EventTenantScope.php", "Web/app/Support/ScannerAccess.php", "Web/app/Support/NlpQueryExecutor.php"],
            "Tests": ["Web/tests/Feature/UsmEaesTest.php", "Web/tests/Feature/OrganizationAnalyticsTest.php", "Web/tests/Feature/WebPortalTest.php"],
        },
        "rules": ["Admin authority resolves from active admin assignments.", "Student profile membership does not authorize admin access.", "Scanner access is checked at activation, hydration, validation, and sync."],
        "impact": ["Event Management", "Attendance", "Scanner Mobile App", "Analytics and Exports", "AI Insights"],
    },
    {
        "title": "Offline Sync",
        "purpose": "Preserves scanner work across connectivity loss, app restart, backend drift, retries, and grace-period sync.",
        "related": ["Scanner Mobile App", "Attendance", "Queue and Concurrency", "Device Identity"],
        "concepts": ["Raw Scan", "Scanner Session", "Pending Student Link", "Attendance Record"],
        "implementation": {
            "Mobile": ["Application/lib/features/scanner/data/scanner_local_database.dart", "Application/lib/features/scanner/presentation/scanner_controller.dart", "Application/lib/features/scanner/data/scanner_api_client.dart"],
            "Backend": ["Web/app/Http/Controllers/Api/AttendanceSyncController.php", "Web/app/Services/AttendanceSyncPersistenceService.php", "Web/app/Services/QueueOutboxService.php"],
            "Tests": ["Application/test/scanner_controller_test.dart", "Web/tests/Feature/AttendanceSyncConcurrencyTest.php"],
        },
        "rules": ["Do not mark local scans synchronized before backend confirmation.", "Preserve failed/pending rows for retry.", "Scope every scan by event, participation scope, scanner session, device, and scan mode."],
        "impact": ["Attendance", "Scanner Mobile App", "Queue and Concurrency"],
    },
    {
        "title": "Queue and Concurrency",
        "purpose": "Protects sync, resolution, and AI jobs from duplicate processing, contention, and local Redis/database fallback differences.",
        "related": ["Attendance", "AI Insights", "Offline Sync", "Analytics and Exports"],
        "concepts": ["Raw Scan", "Attendance Record", "Evaluation Submission"],
        "implementation": {
            "Jobs and Commands": ["Web/app/Jobs/ResolveAttendanceJob.php", "Web/app/Jobs/AnalyzeEventSentimentsJob.php", "Web/app/Console/Commands/DispatchQueueOutbox.php", "Web/app/Console/Commands/CheckQueueHealth.php"],
            "Services": ["Web/app/Services/QueueOutboxService.php", "Web/app/Services/AttendanceSyncPersistenceService.php"],
            "Tests": ["Web/tests/Feature/QueueResilienceTest.php", "Web/tests/Feature/AttendanceSyncConcurrencyTest.php", "Web/tests/Postgres/AttendanceSyncPostgresConcurrencyTest.php"],
        },
        "rules": ["Redis is preferred for production queue/cache coordination.", "Database queue fallback is local-dev posture, not the production durability boundary.", "Database constraints remain final duplicate authority."],
        "impact": ["Attendance", "AI Insights", "Analytics and Exports"],
    },
    {
        "title": "Evaluation Gate",
        "purpose": "Ensures attendance validity follows required evaluation completion and window rules.",
        "related": ["Attendance", "Evaluation", "Event Management", "Analytics and Exports"],
        "concepts": ["Attendance Record", "Evaluation Submission", "Evaluation Form", "Event"],
        "implementation": {
            "Core": ["Web/app/Http/Controllers/Api/EvaluationController.php", "Web/app/Http/Controllers/Web/PortalController.php", "Web/app/Support/EvaluationWindow.php"],
            "Analytics": ["Web/app/Services/EventAnalyticsService.php", "Web/app/Support/EventExportData.php"],
            "Tests": ["Web/tests/Feature/WebPortalTest.php", "Web/tests/Feature/UsmEaesTest.php"],
        },
        "rules": ["AttendanceRecord.valid stays false until required evaluation completion.", "The window closes 24 hours after event/final day conclusion.", "Exports and analytics must reflect gate status."],
        "impact": ["Attendance", "Evaluation", "Analytics and Exports"],
    },
    {
        "title": "Audit Logging",
        "purpose": "Captures sensitive administrative changes, force validations, pending-link actions, organization governance, and workflow review history.",
        "related": ["Authentication and Access Control", "Event Management", "Attendance", "Organizations and Membership"],
        "concepts": ["Admin Assignment", "Pending Student Link", "Attendance Record", "Organization"],
        "implementation": {
            "Model": ["Web/app/Models/AuditLog.php"],
            "Controllers and Services": ["Web/app/Services/OrganizationManagementService.php", "Web/app/Services/AdminAssignmentService.php", "Web/app/Http/Controllers/Web/DashboardController.php"],
            "Tests": ["Web/tests/Feature/AdminOnboardingTest.php", "Web/tests/Feature/WebPortalTest.php"],
        },
        "rules": ["Do not log full QR values, credentials, OAuth tokens, scanner tokens, or API tokens.", "Tenant-scoped audit visibility matters for OSA Admin Access and governance workflows."],
        "impact": ["Authorization and Tenant Scope", "Attendance", "Event Management"],
    },
    {
        "title": "Caching",
        "purpose": "Keeps analytics responsive while invalidating stale event and audience metrics after attendance, evaluation, or scope changes.",
        "related": ["Analytics and Exports", "Attendance", "Evaluation", "Event Management"],
        "concepts": ["Event", "Attendance Record", "Evaluation Submission", "Participation Scope"],
        "implementation": {
            "Cache": ["Web/app/Services/EventAnalyticsCache.php"],
            "Observers": ["Web/app/Observers/EventAnalyticsCacheInvalidationObserver.php", "Web/app/Observers/EventAnalyticsAudienceInvalidationObserver.php"],
            "Tests": ["Web/tests/Feature/EventAnalyticsCacheTest.php", "Web/tests/Feature/EventAnalyticsPageTest.php"],
        },
        "rules": ["Cache may accelerate analytics, but PostgreSQL remains authoritative.", "Audience changes and attendance/evaluation updates must invalidate related analytics."],
        "impact": ["Analytics and Exports", "Attendance", "Evaluation"],
    },
    {
        "title": "Device Binding",
        "purpose": "Maintains scanner session continuity and blocks unsafe cross-device scanner token reuse.",
        "related": ["Scanner Mobile App", "Authentication and Access Control", "Offline Sync"],
        "concepts": ["Scanner Session", "Scanner Assignment", "Raw Scan"],
        "implementation": {
            "Mobile": ["Application/lib/features/auth/data/device_identity_provider.dart", "Application/test/device_identity_provider_test.dart"],
            "Backend": ["Web/app/Services/ScannerSessionService.php", "Web/app/Http/Controllers/Api/AttendanceSyncController.php"],
        },
        "rules": ["Device identity is sent during activation, hydration, validation, and sync.", "Same-device resume is allowed; unsafe transfer should be blocked."],
        "impact": ["Scanner Mobile App", "Attendance", "Authorization and Tenant Scope"],
    },
]


WORKFLOWS: list[dict[str, Any]] = [
    {
        "title": "Google Authentication",
        "summary": "Institutional login creates the base account context and API tokens; admin and scanner authority are resolved later through assignments.",
        "domains": ["Authentication and Access Control", "Organizations and Membership", "Scanner Mobile App"],
        "concepts": ["User", "Admin Assignment", "Scanner Assignment", "Device Identity"],
        "flow": ["Google login", "usm.edu.ph validation", "User profile or staff pending state", "Sanctum token issue", "Admin assignment or scanner assignment lookup"],
        "implementation": ["Web/app/Http/Controllers/Auth/GoogleAuthController.php", "Web/app/Http/Controllers/Api/AuthController.php", "Application/lib/features/auth/data/google_auth_gateway.dart"],
        "rules": ["Account login alone does not authorize scanner hydrate/sync.", "Non-student institutional users follow faculty/staff pending or HR verification paths."],
    },
    {
        "title": "Admin Assignment Governance",
        "summary": "OSA manages organization/admin authority directly through active admin assignments rather than student self-application.",
        "domains": ["Organizations and Membership", "Authentication and Access Control"],
        "concepts": ["Admin Assignment", "Organization", "College", "User"],
        "flow": ["OSA selects account", "Assigns role and scope", "Spatie role projection syncs", "Active assignment checked by services", "Audit entry records governance change"],
        "implementation": ["Web/app/Services/AdminAssignmentService.php", "Web/app/Services/AdminScopeService.php", "Web/app/Livewire/Dashboard/AdminAccessWorkspace.php"],
        "rules": ["Admin applications are deprecated.", "Active term-bound assignments decide authority."],
    },
    {
        "title": "PPA Approval",
        "summary": "Organizer-created proposal data flows through OSA review before scanners and official event workflows unlock.",
        "domains": ["Event Management", "Organizations and Membership", "Evaluation"],
        "concepts": ["PPA", "Event", "Evaluation Form", "Participation Scope"],
        "flow": ["Draft proposal", "Attach official PPA and schedule", "Submit", "OSA review", "Approve, reject, or request revision", "Approved event unlocks downstream scanner paths"],
        "implementation": ["Web/app/Services/EventProposalDraftService.php", "Web/app/Livewire/Dashboard/EventProposalWorkflow.php", "Web/app/Http/Controllers/Api/EventProposalController.php"],
        "rules": ["Organizer drafts remain private until submitted.", "Approval requires the relevant PPA/evaluation constraints to pass."],
    },
    {
        "title": "Participant Scope Approval",
        "summary": "Invited organizations submit or acknowledge their own participation scope before delegated scanning and roll-up analytics are official.",
        "domains": ["Event Management", "Organizations and Membership", "Scanner Mobile App", "Analytics and Exports"],
        "concepts": ["Participation Scope", "PPA", "Scanner Assignment", "Organization"],
        "flow": ["Parent event submitted", "Participant scope invited", "Participant acknowledges or submits PPA", "OSA/organizer review", "Approved/not-required scope unlocks scanner management", "Analytics roll-up includes eligible scope"],
        "implementation": ["Web/app/Services/ParticipationScopeService.php", "Web/app/Livewire/Dashboard/EventParticipantScopes.php", "Web/tests/Feature/ParticipantScopeConcurrencyTest.php"],
        "rules": ["Organizer cannot duplicate itself as a participant scope.", "Participant scanners cannot hydrate/sync before participant PPA approval or not-required status."],
    },
    {
        "title": "Scanner Assignment and Activation",
        "summary": "Admins assign scanner accounts, and the Flutter app activates one assignment into a device-bound runtime scanner session.",
        "domains": ["Scanner Mobile App", "Authentication and Access Control", "Event Management"],
        "concepts": ["Scanner Assignment", "Scanner Session", "Device Identity", "Participation Scope"],
        "flow": ["Authorized admin assigns scanner", "Flutter account logs in", "Assigned events listed", "Device identity sent", "Assignment activated", "Scanner session token issued", "Roster hydration allowed"],
        "implementation": ["Web/app/Services/ScannerAssignmentService.php", "Web/app/Services/ScannerSessionService.php", "Application/lib/features/auth/presentation/screens/assigned_events_screen.dart", "Application/lib/features/scanner/presentation/screens/session_setup_screen.dart"],
        "rules": ["Only activated scanner-session tokens can call scanner validation, hydrate, and sync endpoints.", "Revoked/expired/wrong-device sessions must fail backend validation."],
    },
    {
        "title": "Attendance Scanning",
        "summary": "Scanner mode captures known and unresolved Time-In/Time-Out records locally, preserving enough context for backend validation and resolution.",
        "domains": ["Scanner Mobile App", "Attendance", "Event Management"],
        "concepts": ["Raw Scan", "Roster Student", "Scanner Session", "Event Day"],
        "flow": ["Hydrated roster loaded", "Scanner selects Time-In or Time-Out", "QR/manual ID captured", "Local duplicate cooldown applies", "Offline scan stored with event/scope/session/device context", "Sync state remains pending"],
        "implementation": ["Application/lib/features/scanner/presentation/scanner_controller.dart", "Application/lib/features/scanner/data/scanner_local_database.dart", "Application/lib/features/scanner/presentation/screens/live_scanner_screen.dart"],
        "rules": ["Client-side checks are user feedback; backend checks remain the security boundary.", "Unknown local identities are saved as unresolved instead of blocked."],
    },
    {
        "title": "Offline Attendance Sync",
        "summary": "Pending local scans are uploaded in retry-safe batches, inserted idempotently as raw scans, then handed to queue-backed resolution.",
        "domains": ["Scanner Mobile App", "Attendance", "Queue and Concurrency"],
        "concepts": ["Raw Scan", "Scanner Session", "Pending Student Link", "Attendance Record"],
        "flow": ["Connectivity detected", "Batch payload prepared", "Backend validates scanner session and scope", "AttendanceSyncPersistenceService inserts winners", "QueueOutboxService dispatches resolution", "Failed rows remain retryable"],
        "implementation": ["Application/lib/features/scanner/data/scanner_api_client.dart", "Web/app/Http/Controllers/Api/AttendanceSyncController.php", "Web/app/Services/AttendanceSyncPersistenceService.php", "Web/app/Services/QueueOutboxService.php"],
        "rules": ["Sync of valid captured scans can continue through approved grace paths.", "Idempotency keys and database indexes are the duplicate defense."],
    },
    {
        "title": "EILO Resolution",
        "summary": "Background resolution groups raw scans and upserts canonical attendance using earliest-in/latest-out semantics.",
        "domains": ["Attendance", "Analytics and Exports"],
        "concepts": ["Raw Scan", "Attendance Record", "Event Day", "Participation Scope"],
        "flow": ["ResolveAttendanceJob starts", "Raw scans grouped by event/day/student/scope", "Earliest Time-In selected", "Latest Time-Out selected", "Late/left-early status calculated", "Attendance record upserted"],
        "implementation": ["Web/app/Jobs/ResolveAttendanceJob.php", "Web/app/Models/AttendanceRecord.php", "Web/tests/Feature/AttendanceSyncConcurrencyTest.php"],
        "rules": ["Participant-scope row locking or fallback event locking prevents concurrent write collisions.", "Evaluation gate fields must be preserved by resolution."],
    },
    {
        "title": "Attendance Rules",
        "summary": "Organizer-owned late cutoff policy is configured before event start and interpreted during attendance resolution and analytics.",
        "domains": ["Event Management", "Attendance", "Analytics and Exports"],
        "concepts": ["Event", "Participation Scope", "Attendance Record"],
        "flow": ["Organizer opens Attendance Rules", "Event cutoff set or left blank", "Participant fallback cutoff may be set", "Rules lock after event start", "Resolution applies event-first fallback policy"],
        "implementation": ["Web/app/Livewire/Dashboard/EventAttendanceRules.php", "Web/app/Http/Controllers/Web/DashboardController.php", "Web/app/Jobs/ResolveAttendanceJob.php"],
        "rules": ["OSA does not approve organizer-owned late cutoff.", "Event cutoff applies first; scope cutoff applies only when event cutoff is unset."],
    },
    {
        "title": "Evaluation Completion",
        "summary": "Students answer the event-specific form during the evaluation window, and successful submission validates attendance where required.",
        "domains": ["Evaluation", "Attendance", "Analytics and Exports"],
        "concepts": ["Evaluation Submission", "Evaluation Form", "Attendance Record", "Event"],
        "flow": ["Evaluation window opens", "Student sees pending event", "Question map validated", "Evaluation stored", "AttendanceRecord.valid toggled", "Analytics/export inputs update"],
        "implementation": ["Web/app/Http/Controllers/Web/PortalController.php", "Web/app/Http/Controllers/Api/EvaluationController.php", "Web/app/Support/EvaluationWindow.php"],
        "rules": ["Window closes 24 hours after event/final day end.", "Submission must match event-specific evaluation questions."],
    },
    {
        "title": "AI Sentiment and NLP",
        "summary": "Closed evaluations can be analyzed for sentiment, and admins can ask scoped analytics questions through the NLP query assistant.",
        "domains": ["AI Insights", "Evaluation", "Analytics and Exports", "Authentication and Access Control"],
        "concepts": ["Evaluation Submission", "Event", "Organization", "Admin Assignment"],
        "flow": ["Evaluation window closes", "AnalyzeEventSentimentsJob batches anonymized comments", "AiServiceInterface classifies sentiment", "Evaluation sentiment fields update", "Admin NLP query is parsed", "NlpQueryExecutor applies tenant scope"],
        "implementation": ["Web/app/Jobs/AnalyzeEventSentimentsJob.php", "Web/app/Services/GeminiService.php", "Web/app/Services/OllamaService.php", "Web/app/Support/NlpQueryExecutor.php"],
        "rules": ["Comments sent to AI are anonymized.", "NLP query execution must hard-apply active admin assignment scope."],
    },
    {
        "title": "Organization Analytics",
        "summary": "Organization and program-level performance metrics aggregate attendance and evaluation data under tenant-scoped filters.",
        "domains": ["Analytics and Exports", "Organizations and Membership", "Attendance", "Evaluation"],
        "concepts": ["Organization", "Program", "Attendance Record", "Evaluation Submission"],
        "flow": ["Admin opens analytics", "Scope and filters resolved", "Organization metrics calculated", "Program leaderboard calculated", "Event drilldowns and exports available"],
        "implementation": ["Web/app/Services/OrganizationAnalyticsService.php", "Web/app/Http/Controllers/Web/AnalyticsOverviewController.php", "Web/app/Livewire/Dashboard/AnalyticsOverviewFilters.php"],
        "rules": ["OSA can see all organization analytics; LSG/Society scopes are constrained by active assignment.", "Manual non-system Gawad fields remain deferred."],
    },
    {
        "title": "Analytics Export",
        "summary": "Scoped PDF/XLSX exports package attendance and evaluation summaries for official printed attachment workflows.",
        "domains": ["Analytics and Exports", "Attendance", "Evaluation", "Event Management"],
        "concepts": ["Event", "Attendance Record", "Evaluation Submission", "Evaluation Form"],
        "flow": ["Authorized admin opens event analytics", "Export payload built", "Attendance or evaluation sheet rendered", "PDF/XLSX generated with metadata", "File downloaded"],
        "implementation": ["Web/app/Http/Controllers/Web/EventExportController.php", "Web/app/Support/EventExportData.php", "Web/app/Exports/EventAttendanceExport.php", "Web/app/Exports/EventEvaluationExport.php"],
        "rules": ["Exports must be tenant-scoped and timestamped.", "Exported evaluation labels resolve from the event form snapshot."],
    },
    {
        "title": "Event Lifecycle",
        "summary": "Approved events move through runtime phases based on schedule, evaluation window, pending scan state, processing, and completion requirements.",
        "domains": ["Event Management", "Attendance", "Evaluation", "Analytics and Exports"],
        "concepts": ["Event", "Event Day", "Evaluation Submission", "Pending Student Link"],
        "flow": ["scheduled", "active", "ended", "evaluation_open", "evaluation_closed", "processing", "completed"],
        "implementation": ["Web/app/Services/EventLifecycleService.php", "Web/app/Services/DashboardEventWorkspaceService.php", "Web/routes/console.php"],
        "rules": ["Lifecycle phase is separate from proposal status.", "Completion depends on schedule, evaluation, unresolved scans/pending links, sentiment processing, and scanner session closure."],
    },
]


def domain_body(repo: Path, graph_counts: dict[str, int], item: dict[str, Any]) -> str:
    return f"""# {item['title']}

#domain

## Purpose

{item['purpose']}

## Related Domains

{bullets([wiki(v) for v in item['related']])}

## Key Concepts

{bullets([wiki(v) for v in item['concepts']])}

## Main Workflows

{bullets([wiki(v) for v in item['workflows']])}

## Cross-Cutting Concerns

{bullets([wiki(v) for v in item['cross']])}

## Important Implementation

{implementation(repo, graph_counts, item['implementation'])}

## Important Rules

{bullets(item['rules'])}

## Change Impact

Changes in this domain may affect:

{bullets([wiki(v) for v in item['impact']])}
"""


def concept_body(repo: Path, graph_counts: dict[str, int], item: dict[str, Any]) -> str:
    impl = file_bullets(repo, item["implementation"], graph_counts)
    return f"""# {item['title']}

#concept

## Meaning

{item['meaning']}

## Belongs To

{bullets([wiki(v) for v in item['belongs']])}

## Relationships

{bullets(item['relationships'])}

## Implementation

{impl}

## Important Constraints

{bullets(item['constraints'])}
"""


def cross_body(repo: Path, graph_counts: dict[str, int], item: dict[str, Any]) -> str:
    return f"""# {item['title']}

#cross-cutting

## Purpose

{item['purpose']}

## Related Domains

{bullets([wiki(v) for v in item['related']])}

## Key Concepts

{bullets([wiki(v) for v in item['concepts']])}

## Important Implementation

{implementation(repo, graph_counts, item['implementation'])}

## Important Rules

{bullets(item['rules'])}

## Change Impact

{bullets([wiki(v) for v in item['impact']])}
"""


def workflow_body(repo: Path, graph_counts: dict[str, int], item: dict[str, Any]) -> str:
    impl = file_bullets(repo, item["implementation"], graph_counts)
    flow = "\n".join(f"{index + 1}. {step}" for index, step in enumerate(item["flow"]))
    return f"""# {item['title']}

#workflow

## Summary

{item['summary']}

## Domains

{bullets([wiki(v) for v in item['domains']])}

## Concepts

{bullets([wiki(v) for v in item['concepts']])}

## Flow

{flow}

## Implementation

{impl}

## Important Rules

{bullets(item['rules'])}
"""


def overview_body(graph_counts: dict[str, int]) -> str:
    domains = [wiki(item["title"]) for item in DOMAINS]
    workflows = [wiki(item["title"]) for item in WORKFLOWS]
    cross = [wiki(item["title"]) for item in CROSS_CUTTING]
    graph_nodes = sum(graph_counts.values())
    return f"""# USM EAES System Overview

The USM Event Attendance & Evaluation System is a Laravel 12 web/API backend and Flutter scanner companion app for event proposals, scoped attendance capture, evaluation-gated validity, analytics, exports, and AI-assisted insights.

Graphify remains the machine-readable structural source. This vault is a curated human architecture layer that intentionally avoids mirroring every class, method, route, or file.

## Major Domains

{bullets(domains)}

## Important Workflows

{bullets(workflows)}

## Cross-Cutting Concerns

{bullets(cross)}

## Architecture Sketch

```mermaid
flowchart LR
    Auth[Authentication and Access Control] --> Org[Organizations and Membership]
    Org --> Event[Event Management]
    Event --> Scanner[Scanner Mobile App]
    Scanner --> Attendance[Attendance]
    Attendance --> Evaluation[Evaluation]
    Attendance --> Analytics[Analytics and Exports]
    Evaluation --> Analytics
    Evaluation --> AI[AI Insights]
    AI --> Analytics
    Event --> HR[HR Attendance]
```

## How to Explore

Start with the major domains above. Use Obsidian's Local Graph when inspecting a domain, concept, or workflow so the surrounding architecture stays readable.

For raw implementation-level relationships, inspect:

`graphify-out/graph.html`

For machine-readable graph data:

`graphify-out/graph.json`

This generated vault referenced {graph_nodes} Graphify-backed implementation nodes across selected `Web/` and `Application/` files.

## Generated Content Boundary

Generated sections are wrapped in `{BEGIN}` and `{END}` markers. Manual notes can be added outside those markers and will be preserved by the sync script.
"""


def domain_index_body() -> str:
    lines: list[str] = ["# Domain Index", "", "#map", "", "## Domains", ""]
    for item in DOMAINS:
        related = ", ".join(wiki(v) for v in item["related"][:4])
        lines.append(f"- {wiki(item['title'])} - related: {related}")

    lines.extend(["", "## Concepts by Domain", ""])
    by_domain: defaultdict[str, list[str]] = defaultdict(list)
    for concept in CONCEPTS:
        for domain in concept["belongs"]:
            by_domain[domain].append(concept["title"])
    for domain in sorted(by_domain):
        lines.append(f"### {domain}")
        lines.extend(f"- {wiki(concept)}" for concept in sorted(set(by_domain[domain])))
        lines.append("")

    return "\n".join(lines).rstrip() + "\n"


def architecture_map_body() -> str:
    return """# Architecture Map

#map

## Curated System Graph

```mermaid
flowchart TD
    System[USM EAES]
    System --> Auth[Authentication and Access Control]
    System --> Org[Organizations and Membership]
    System --> Event[Event Management]
    System --> Scanner[Scanner Mobile App]
    System --> Attendance[Attendance]
    System --> Evaluation[Evaluation]
    System --> Analytics[Analytics and Exports]
    System --> AI[AI Insights]
    System --> HR[HR Attendance]

    Auth --> Admin[Admin Assignment]
    Auth --> User[User]
    Auth --> Device[Device Identity]
    Org --> Organization[Organization]
    Org --> Membership[Organization Membership]
    Event --> PPA[PPA]
    Event --> Scope[Participation Scope]
    Event --> EventDay[Event Day]
    Scanner --> ScannerAssignment[Scanner Assignment]
    Scanner --> ScannerSession[Scanner Session]
    Attendance --> RawScan[Raw Scan]
    Attendance --> AttendanceRecord[Attendance Record]
    Attendance --> PendingLink[Pending Student Link]
    Evaluation --> EvalForm[Evaluation Form]
    Evaluation --> Eval[Evaluation]
    Analytics --> Export[Analytics Export]
    AI --> Sentiment[AI Sentiment and NLP]
```

## High-Impact Change Paths

- Changing [[Event]] or [[Participation Scope]] can affect [[Scanner Assignment and Activation]], [[Offline Attendance Sync]], [[Evaluation Completion]], and [[Organization Analytics]].
- Changing [[Scanner Session]] can affect [[Device Identity]], [[Attendance Scanning]], [[Offline Sync]], and backend authorization checks.
- Changing [[Attendance Record]] can affect [[Evaluation Gate]], [[Analytics and Exports]], [[Analytics Export]], and Gawad-style organization metrics.
- Changing [[Evaluation Form]] can affect [[PPA Approval]], [[Evaluation Completion]], sentiment processing, and exports.
- Changing [[Admin Assignment]] can affect every tenant-scoped dashboard, API, scanner, analytics, export, and AI query path.
"""


def readme_body() -> str:
    return """# EAES Architecture Knowledge Vault

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
"""


def build_notes(repo: Path, graph_counts: dict[str, int]) -> list[Note]:
    notes = [Note("00 - System Overview", "", "system", overview_body(graph_counts))]
    notes.extend(Note(item["title"], "Domains", "domain", domain_body(repo, graph_counts, item)) for item in DOMAINS)
    notes.extend(Note(item["title"], "Concepts", "concept", concept_body(repo, graph_counts, item)) for item in CONCEPTS)
    notes.extend(Note(item["title"], "Cross-Cutting", "cross-cutting", cross_body(repo, graph_counts, item)) for item in CROSS_CUTTING)
    notes.extend(Note(item["title"], "Workflows", "workflow", workflow_body(repo, graph_counts, item)) for item in WORKFLOWS)
    notes.append(Note("Architecture Map", "Maps", "map", architecture_map_body()))
    notes.append(Note("Domain Index", "Maps", "map", domain_index_body()))
    notes.append(Note("README", "", "readme", readme_body()))
    return notes


def obsidian_config(repo: Path) -> dict[Path, str]:
    return {
        repo / "knowledge" / ".obsidian" / "app.json": json.dumps(
            {
                "alwaysUpdateLinks": True,
                "attachmentFolderPath": "Attachments",
                "newFileLocation": "current",
                "showLineNumber": False,
            },
            indent=2,
        )
        + "\n",
        repo / "knowledge" / ".obsidian" / "graph.json": json.dumps(
            {
                "collapse-filter": False,
                "search": "",
                "showTags": True,
                "showAttachments": False,
                "hideUnresolved": False,
                "showOrphans": True,
                "collapse-color-groups": False,
                "colorGroups": [],
                "collapse-display": True,
                "showArrow": False,
                "textFadeMultiplier": 0,
                "nodeSizeMultiplier": 1,
                "lineSizeMultiplier": 1,
                "collapse-forces": True,
                "centerStrength": 0.5,
                "repelStrength": 10,
                "linkStrength": 1,
                "linkDistance": 250,
                "scale": 1,
                "close": False,
            },
            indent=2,
        )
        + "\n",
    }


def validate_links(repo: Path) -> list[str]:
    vault = repo / "knowledge"
    notes = {path.stem for path in vault.rglob("*.md")}
    missing: list[str] = []
    pattern = re.compile(r"\[\[([^\]|#]+)")
    for path in sorted(vault.rglob("*.md")):
        text = path.read_text(encoding="utf-8")
        for match in pattern.findall(text):
            if match not in notes:
                missing.append(f"{path.relative_to(repo)} -> {match}")
    return missing


def inspect_neighborhoods(repo: Path) -> dict[str, list[str]]:
    vault = repo / "knowledge"
    result: dict[str, list[str]] = {}
    pattern = re.compile(r"\[\[([^\]|#]+)")
    for title in ["Attendance", "Event Management", "Scanner Mobile App", "Evaluation", "Authorization and Tenant Scope"]:
        path = next(vault.rglob(f"{title}.md"), None)
        if path is None:
            result[title] = []
            continue
        links = sorted(set(pattern.findall(path.read_text(encoding="utf-8"))))
        result[title] = links[:20]
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate the curated EAES Obsidian architecture vault.")
    parser.add_argument("--repo", type=Path, default=Path.cwd(), help="Repository root. Defaults to current directory.")
    parser.add_argument("--check", action="store_true", help="Validate the existing vault without writing files.")
    args = parser.parse_args()

    repo = args.repo.resolve()
    graph_counts, _, _ = load_graph(repo)
    notes = build_notes(repo, graph_counts)

    if not args.check:
        changed = 0
        for note in notes:
            changed += int(write_text_if_changed(repo / note.path, generated_note(note.kind, note.body)))
        for path, text in obsidian_config(repo).items():
            changed += int(write_text_if_changed(path, text))
        print(f"Changed files: {changed}")

    missing = validate_links(repo)
    neighborhoods = inspect_neighborhoods(repo) if (repo / "knowledge").exists() else {}

    summary = {
        "domains": len(DOMAINS),
        "concepts": len(CONCEPTS),
        "workflows": len(WORKFLOWS),
        "cross_cutting": len(CROSS_CUTTING),
        "missing_links": missing,
        "local_graph_samples": neighborhoods,
    }
    print(json.dumps(summary, indent=2, ensure_ascii=False))

    return 1 if missing else 0


if __name__ == "__main__":
    raise SystemExit(main())
