# **PRODUCT REQUIREMENTS DOCUMENT**

## **USM Event Attendance & Evaluation System (EAES)**

**Prepared by:** Carl John H. Pactao-in

**Program:** BS Information Systems

**Document Version:** 2.0 (Updated)

**Classification:** Capstone Thesis Specification

**Date:** May 2026

## **1\. Executive Summary & Project Objectives**

### **1.1 Project Overview**

The **USM Event Attendance & Evaluation System (EAES)** is a multi-platform enterprise web and mobile application developed as a capstone project for the University of Southern Mindanao (USM). The system digitalizes the event lifecycle—focusing on digital event proposals (PPA), secure QR-code-based attendance tracking, and mandatory event evaluations.

By replacing outdated paper templates and manual workflows, the system enforces data transparency, eliminates manual transcriptions, and gives the Office of Student Affairs (OSA) verifiable engagement data.

### **1.2 Updated Scope Boundaries (Removal of Accomplishment Reports)**

To align with real-world administrative pipelines and reduce technical debt, **the digital generation of the Semestral Accomplishment Report has been completely removed from the system scope**.

Instead, the system focuses purely on the **Evaluation Module**. The system aggregates and processes evaluation scores, feedback, and sentiment data on an event-by-event basis. These structured evaluation summaries are made easily exportable (via PDF/Excel) so that student officers can print and manually attach them as official verification documents to their physical semestral accomplishment reports.

### **1.3 Strategic Objectives**

* **Digitize PPA Workflows:** Enable Society Admins to submit digital event proposals and receive digital approvals from OSA.  
* **Streamline Event Traffic:** Minimize bottlenecking at event entry/exit points via high-speed QR code scanning on a dedicated Flutter companion app.  
* **Enforce the Evaluation Gate:** Secure data validity by marking a student's attendance as "Valid" only after they complete a mandatory digital evaluation form.  
* **Deliver Advanced Offline capabilities:** Ensure zero-loss attendance capture in venues with weak to no cellular reception.  
* **Integrate Elite AI Analytics:** Use Google Gemini 1.5 Pro to conduct tag-aware sentiment analysis on tagalog/english student comments and provide an NLP data query assistant for administrators.

## **2\. User Personas & Access Control Matrix (Laravel Spatie RBAC)**

### **2.1 Role-Based Access Control Implementation details**

The system implements Role-Based Access Control using the **Spatie Laravel-Permission (spatie/laravel-permission)** package. Under this architecture:

* Permissions are defined as granular verbs (e.g., create-events, approve-proposals, force-validate-attendance).  
* Roles are assigned to users and represent groups of permissions (e.g., osa\_admin, society\_admin, student).  
* To accommodate USM's institutional hierarchy (University ![][image1] College ![][image1] Department ![][image1] Program) and organization scopes, every administrative query resolves the user's active Authority Projection for the exact School Year and organization, Department, College, or University scope. Verified Officer Rosters are the source of truth for normal student-organization authority; `admin_assignments` and Spatie roles are permission-facing projections and must not independently grant cross-scope access. Student profile and membership columns remain audience and demographic data, not administrative authority.

// Conceptual Laravel Spatie Middleware Check with Tenant Scope  
public function handle($request, Closure $next, $permission)  
{  
    $user \= Auth::user();  
      
    if (\!$user-\>hasPermissionTo($permission)) {  
        abort(403, 'Unauthorized action.');  
    }  
      
    // Enforce Tenant Boundary Check  
    if ($request-\>has('event\_id')) {  
        $event \= Event::findOrFail($request-\>input('event\_id'));  
        if ($user-\>role \!== 'osa\_admin' && $event-\>organization\_id \!== $user-\>organization\_id) {  
            abort(403, 'You do not have access to this organization\\'s data.');  
        }  
    }

    return $next($request);  
}

### **2.2 RBAC Role Map & Permissions**

Using Spatie's conventions, roles are seeded with the following permissions:

| Role Name (spatie\_roles) | Scoped Access boundaries | Core Spatie Permissions Assigned |
| :---- | :---- | :---- |
| **Super Admin (OSA)** | Universal (across all colleges and societies) | manage-organizations, approve-proposals, view-all-analytics, force-validate-any, manage-global-settings |
| **USG Admin** | University-wide events only | create-proposals, assign-scanners-own, set-attendance-policy, view-own-analytics |
| **LSG Admin** | Constituent societies inside their College boundary | create-proposals, assign-scanners-own, set-attendance-policy, view-college-analytics, view-constituent-data |
| **Society Admin** | Verified President/Secretary authority, strictly restricted to the exact Society or recognized organization and School Year | create-proposals, assign-scanners-own, set-attendance-policy, view-own-analytics, force-validate-own |
| **Academic Unit Admin** | Academic unit/college unit event organizer scope | create-proposals, invite-participant-scopes, assign-scanners-own, set-attendance-policy, view-college-analytics, view-constituent-data, force-validate-own |
| **Office Event Admin** | Administrative office event organizer scope | create-proposals, invite-participant-scopes, assign-scanners-own, set-attendance-policy, view-own-analytics, force-validate-own |
| **HR Admin** | HR-domain faculty/staff attendance scope | create-hr-events, set-attendance-policy, manage-faculty-roster, assign-hr-scanners, view-hr-analytics, export-hr-attendance |
| **Scanner** | Temporarily locked to a single assigned event session | scan-qr-codes, manual-entry-id |
| **Faculty** | Own personal profiles | view-own-history, submit-evaluations |
| **Student** | Own personal profile and personal logs | register-profile, view-own-history, submit-evaluations |

Organization administration and institutional proposal approval are separate authority paths. For recognized student organizations, adviser-verified President and Secretary Officer Roster entries generate School-Year-bound Authority Projections; OSA may use a reasoned, audited override only for exceptions, corrections, locks, disputes, or the absence of a valid normal path. Institutional Approver Accounts resolve proposal decisions from real institutional context: the Proponent is a verified Secretary or President who prepared the packet, the Organization Head is the verified President, the Adviser is the verified organization adviser, the Department Chairperson comes from the organizer's Department, and the College Dean comes from that Department's College. Chief Security/Office of Security and OSA are distinct entities with separate roles, permissions, and Workflow Tasks. A broad Spatie role establishes permission eligibility but never authorizes a proposal decision by itself; the user must also be the resolved assignee, an authorized replacement, or an OSA administrative exception for the exact Workflow Task and scope. Reassignments preserve the previous assignment and reason in the Approval Trail.

## **3\. Offline-First Architecture & Sync Strategy**

The companion mobile scanner application (developed in Flutter) must support zero-connectivity attendance tracking. This section details the caching, synchronization, drift-handling, and conflict-resolution workflows.

       \[ONLINE STATE\]  
             │  
             ▼  
┌───────────────────────────┐  
│  Hydrate Local SQLite     │ \<─── Syncs student roster (\~1.2MB for 15k users)  
└────────────┬──────────────┘  
             │  
             ▼  
       \[OFFLINE STATE\]  
┌───────────────────────────┐  
│     Scan QR Codes         │   
├───────────────────────────┤  
│ Write to SQLite Local DB  │ \<─── Checks against pre-cached database  
└────────────┬──────────────┘  
             │  
             ▼  
       \[ONLINE DETECTED\]  
┌───────────────────────────┐      ┌──────────────────────────────────┐  
│   Concurrent Bulk Sync    │ ───\> │ Redis queue throttles requests   │  
└───────────────────────────┘      │ Bulk INSERT with IDEMPOTENCY KEY │  
                                   └──────────────────────────────────┘

### **3.1 Local Roster Hydration (Pre-Caching)**

To allow the scanner to display student details (Name, Course) without an active internet connection, the device must "hydrate" its local SQLite database before an event begins.

* **Roster Compression Strategy:** Instead of downloading bloated user records, the API exposes a highly optimized hydration payload containing only essential identification metadata:  
  * student\_id (Integer/String)  
  * qr\_code\_value (String)  
  * name (String, max 40 chars)  
  * program\_code (String, max 6 chars)  
* **Data Footprint:** On average, a hydrated record is approximately **80 bytes**.  
  `Roster Size = 15,000 students × 80 bytes ≈ 1.2 MB`  
  This lightweight **1.2 MB** payload is compressed via gzip on transfer, allowing sync to complete in under 3 seconds over standard local Wi-Fi.  
* **Scope-aware hydration:** The scanner app hydrates only the roster attached to the activated `event_participation_scope_id`. Society scanners hydrate their society membership, LSG/USG central scanners hydrate only approved college/university participant scopes allowed by `capture_mode`, non-academic organization scanners hydrate active `organization_memberships`, and HR scanners hydrate verified faculty/staff roster entries only.
* **Sync Triggers:** The scanner app automatically downloads a fresh participant roster when:  
  1. The scanner successfully logs into an event session while online.  
  2. The user manually taps "Sync Student Directory" while an active internet connection is detected.

### **3.2 Handling Database Drift and Unresolved Scans**

**Scenario:** A student registers their profile or edits their details on the web portal *after* the scanner app has gone offline, or while the scanning device is operating in an offline state.

1. **Unresolved Scan Capture:** When a student scans an ID that does not exist in the scanner's local SQLite database, the app will *not* block the scan.  
2. **Local Registry Fallback:** The app records the scan as an **Unresolved Scan Record** in the SQLite database, using the raw QR code value. The UI displays an orange confirmation warning: *"Scan Saved Offline (Pending Registration Lookup on Sync)"*.  
3. **Server-Side Resolution Pipeline:**  
   * Upon network reconnection, the bulk sync API accepts the raw QR value.  
   * The backend runs a query against the PostgreSQL master database to find a matching qr\_code\_value.  
   * If a matching student record is found, the backend links the scan to that student's canonical user\_id.  
   * **The Dead-End Case (Unknown QR):** If the QR value is not registered to *any* user in the PostgreSQL database, the record is placed in a pending\_student\_link table on the Society Admin's dashboard. A dashboard alert prompts the officer to manually map that raw QR scan to a student or flag it as an unaccredited participant.

### **3.3 High-Concurrency Sync and Queue Throttling**

When an event with thousands of attendees concludes, multiple scanner devices will connect to the internet simultaneously and upload thousands of scans. To prevent API timeouts, database row locking, or memory exhaustion:

* **Batch Sync API Payload:** The scanning client sends lists of scans in chunks of 200 records via a single POST request containing a JSON array.  
* **Idempotency Safeguard:** Every scan record in the JSON array is submitted with an idempotency\_key generated on the mobile client:  
  `idempotency_key = md5(event_id + student_id + scan_type + scanned_at)`  
* **Server-Side Throttling and Job Dispatching:**  
  * When the SyncAttendanceController receives the bulk payload, it verifies the idempotency keys to instantly reject duplicate submissions.  
  * Instead of executing heavy SQL calculations synchronously, the controller writes the raw records directly into the raw\_scans table using a high-speed bulk INSERT statement and immediately returns an HTTP 200 to free up the mobile app.  
  * The controller dispatches a background worker job, ResolveAttendanceJob, to the **Laravel Queue** (backed by Redis or database queue driver).  
  * **Pessimistic Locking & Chunking:** The background job processes unresolved logs in sequential database transactions, using pessimistic row locking (sharedLock() or lockForUpdate()) scoped strictly to the affected event\_id to prevent deadlocks:

// High-Concurrency Transaction Block in Laravel Job  
DB::transaction(function () use ($eventId, $studentIds) {  
    // Lock the affected student rows for this event to prevent concurrent write collisions  
    $existingRecords \= AttendanceRecord::where('event\_id', $eventId)  
        \-\>whereIn('student\_id', $studentIds)  
        \-\>lockForUpdate()  
        \-\>get()  
        \-\>keyBy('student\_id');

    // Perform validation and resolve Earliest-In / Latest-Out mapping...  
});

## **4\. Complete Epic & Feature Breakdown**

### **Epic 1: Authentication & Onboarding**

* **Feature 1.1 \- Google OAuth Registration & Login:** Enforced through Laravel Socialite. On Callback, the system checks if the email domain matches the institutional @usm.edu.ph constraint. On success, Laravel Sanctum issues an API bearer token.  
* **Feature 1.2 \- Student Profile Hydration & QR Registration:** Upon initial registration, students complete their Student Academic Path in the order College ![][image1] Department ![][image1] Program ![][image1] eligible Organization or Society ![][image1] Year Level, then parse their physical ID QR code using their webcam. Each selection filters the next options, and the backend validates that the Department belongs to the College, the Program belongs to the Department, and a selected Academic Organization covers that Program within the same Department. Year Level remains a controlled demographic used for cohort filtering in analytics and reports; it is not an administrative tenant scope. A student may hold multiple School-Year-specific organization memberships, but every membership preserves its own organization, Department, Program, status, and School Year context.
* **Feature 1.3 \- School Year and Officer Governance:** OSA/Super Admin creates and controls first-class School Years and explicitly activates exactly one School Year for current onboarding defaults, officer authority, proposals, memberships, and reporting. School Year Rollover does not automatically erase authority: OSA may open a School Year Transition Period during which outgoing verified Presidents and Secretaries remain officers and may create and submit proposals until incoming officers are adviser-verified or OSA closes/revokes the outgoing authority. Each recognized student organization maintains a School-Year-specific Officer Roster by selecting existing registered members rather than re-encoding person records. The outgoing President or Secretary normally drafts/submits the incoming roster; the organization Adviser may initiate it when neither is available. President, Secretary, and Adviser are required roles, supporting evidence or attestation is required, and Adviser Verification makes the roster official. Verified President and Secretary entries automatically create scoped organization-admin Authority Projections. Mid-year changes use Officer Change Requests and preserve replaced, revoked, and expired history. OSA controls recognized organizations, School Years, audit visibility, disputes, forced corrections, locks, and reasoned Authority Overrides through OSA-only advanced pages; OSA does not normally profile officers or manually assign every organization admin. Super Admin bootstrap and HR Admin provisioning remain separate.
* **Onboarding Separation:** Institutional Google login still requires `@usm.edu.ph`. Accounts with `s.` email prefixes default to `user_type=student` and must complete student onboarding. Non-`s.` institutional accounts default to `faculty_or_staff_pending`; HR roster verification or imported employee records are the source of truth before faculty/staff attendance scanning is trusted.
* **Onboarding Master Data Rule:** Colleges, Departments, Programs, School Years, and recognized Organizations are official institutional master data maintained by OSA/Super Admin. USG is University-scoped, each LSG is College-scoped, and each Society or Academic Organization is Department-backed and may cover only Programs from that same Department. Non-academic organizations may be University-wide or optionally College-affiliated; administrative and HR offices remain outside the academic hierarchy unless explicitly attached. Seed data must use explicit known mappings rather than infer Department ownership from names.

### **Epic 2: Event Proposal & Permit System (PPA)**

* **Feature 2.1 \- Digital PPA Packet and Workflow Binding:** Society, USG, LSG, non-academic organization, academic unit, and administrative office admins construct one Canonical PPA Packet in EAES instead of uploading or retyping separate external PPA Proposal and Student Activity Permit forms. The packet includes the Student Activity Permit activity-role choice: Organizer, Co-Organizer, or Participant. The guided packet captures shared fields once and then collects role-specific and section-specific proposal, campus/off-campus/online permit, activity checklist, budget, beneficiary, schedule, and venue details as needed; generated proposal and permit views render from this same source of truth. Draft saves remain permissive. Before first submission, the system validates every required packet field and externally obtained venue detail: an on-campus organizer/co-organizer proposal selects an active OSA-maintained venue or records an allowed manual campus venue, while an off-campus organizer/co-organizer proposal provides a venue name/address and must not reference the campus catalog; the owning organizer also records the confirming office, a non-future confirmation date, an optional reference number, and a private PDF, DOC, DOCX, JPG, JPEG, or PNG evidence file up to 10 MB. A participant packet attaches to the parent event's participant scope, inherits the parent event's approved venue, dates, and permit context, and must not start a duplicate venue-confirmation route. First submission creates one Workflow Run bound to the active applicable Published Template Version. Later template edits never silently change that run. Workflow progress remains separate from `events.status` and `events.lifecycle_phase`. HR events retain their documented exemptions.
* **Feature 2.2 \- Proposal Process Template Governance:** OSA/Super Admin manages reusable Proposal Process Templates rather than a hard-coded signature checklist. Draft template versions may be edited; published versions are immutable and may be retired only for future submissions. A template defines ordered Process Steps, explicit prerequisites, proposal/permit/venue-use phases, required and conditional tasks, submission and Digital Permit gates, Assignment Resolver Rules, and applicability by organizer authority/type, college where relevant, event domain, proposal category, and location type. One active default rule handles otherwise unmatched organizer event proposals. In-progress Workflow Runs retain their original Published Template Version and resolved task assignments. Automatic migration to a newer version is prohibited; any future migration must be an explicit OSA administrative action with an audit record.
* **Feature 2.3 \- Institutional Approval Tasks and Dashboard Decisions:** Configured Process Steps create live Workflow Tasks for the real institutional actors in the PPA process. Proposal preparation/approval may involve the verified Proponent, Organization Head, and Adviser. Permit approval may involve the Organization Head, Adviser, Department Chairperson, College Dean, Chief Security/Office of Security, and OSA. Venue-use steps may resolve to a Venue Custodian after the permit route. Assignment Resolver Rules use the proposal's School Year, organization, Department, derived College, venue, event domain, template configuration, Officer Roster, and active Institutional Approver Accounts to resolve an eligible account. The resulting task assignment and scope are the final authority to decide that task. Chief Security and OSA must resolve as distinct entities and may never share an undifferentiated office role. Canonical decisions are `approved`, `rejected`, `revision_requested`, and `not_required`. Rejection is final unless OSA records an administrative exception. Remarks are required for rejection and revision requests and optional for approvals and Not Required decisions. Controlled reassignment is exceptional and records the former assignee, replacement, actor, reason, and time. Dashboard decisions replace physical signatures as the system source of truth; visual or handwritten signature images are not required.
* **Feature 2.4 \- Organizer Checklist and Revision Loop:** Each organizer proposal workspace includes an Organizer Checklist rendered as a two-pane action center. The left pane shows the complete ordered process with a compact connected numbered rail and visible blocked, available, pending, completed, Not Required, rejected, and revision-requested states. The right pane focuses on the current action, approver remarks, unmet gates, and the existing proposal packet edit/review action. Blocked future tasks remain visible but unavailable until their prerequisites are complete. A revision request returns the relevant task and proposal to the organizer, preserves the same Workflow Run, displays the approver's remarks, and gates resubmission until required packet corrections and returned tasks are resolved. Notifications cover assignment, submission, approval, revision request, rejection, reassignment, and final permit approval, with action-worthy events linking to the relevant proposal or task.
* **Feature 2.5 \- Approval Trail, Digital Permit, and Permit Versioning:** Every Workflow Task decision writes an immutable Approval Trail containing proposal, Workflow Run, Workflow Task, actor, Institutional Approver Account or role/scope, decision, remarks, previous and resulting status, timestamp, and verification/reference identifier; existing cross-system audit logging remains in place. A Digital Permit becomes downloadable only after every required permit-gating task is approved or marked Not Required and OSA gives final approval. The generated permit displays approval metadata blocks rather than signature images and creates official Permit Copy Owner records for OSA, the Office of Security, and the organizing society/organization. Final approval creates an immutable, sequential permit version containing the approved Canonical PPA Packet, rendered proposal/permit snapshots, venue/evidence, workflow reference, and approval metadata snapshots. Before the first event day, an owning organizer may start a venue amendment on an approved scheduled event by supplying a reason. The active permit is superseded, the proposal returns to `revision_requested`, scanner access locks, and active scanner sessions are revoked while scanner assignments remain. The organizer supplies replacement venue details, evidence, and PPA corrections through the existing Workflow Run; successful required approvals create the next permit version and unlock scanning, while rejection never silently reactivates a superseded permit. Historical permit packets, evidence, copy ownership, and Approval Trails remain immutable.
* **Feature 2.6 \- Multi-Organization Event Linking and Participant PPAs:** Allows USG/LSG/academic-unit/office organizers to list a parent event and invite sub-organizational PPAs as participant scopes under a master permit. Each `event_participation_scope` records organizer vs participant role, participant type, PPA status, capture mode, and roll-up analytics eligibility. The organizing organization is always represented by the organizer attendance scope and is automatically included in its own event audience; it must not submit a duplicate participant PPA for the same event. The Events dashboard separates **Organized Events** from **Participating Events** so invited organizations can find submitted parent events, complete or resubmit a Canonical PPA Packet with activity role `Participant`, and manage scanners only for their own approved scope. Organizer drafts remain private until the parent proposal is submitted. Participant organizations inherit the parent event's confirmed venue, dates, and permit context and do not submit separate venue confirmation. Participant packets may use a participant-specific Proposal Process Template, Workflow Run, checklist, approvals, and revision loop, but they must not duplicate organizer venue-use, permit-copy-owner, or venue-custodian routing unless a future approved template explicitly adds a participant-specific requirement. Participant scanners cannot be assigned, activated, hydrated, synced, or counted as official analytics participants until their participant PPA is approved or marked Not Required.
* **Feature 2.7 \- Event Domains:** `events.event_domain` separates student-affairs, HR, academic-records, and academic-unit contexts. HR events use faculty/staff attendance rosters, do not require OSA PPA approval, and are managed by HR Admins through HR-domain permissions. The first Proposal Workflow Module release binds organizer event proposals and participant-scope PPAs; security requests, standalone venue requests, and other Proposal Subject types require later explicit scope.
* **Organizer Rule \- Recognition and Graduation:** Recognition and Graduation events are no longer modeled as a separate records-office admin role or organization. Until a dedicated staff-office module exists, OSA assigns these events to an active approved organizer through the normal proposal workflow, and university-wide audience calculations remain based on event audience rules rather than student membership in the organizer.
* **Venue and Office Routing Boundary:** EAES models Chief Security, department chairperson, college dean, OSA, and Venue Custodian as real Institutional Approver Accounts that can receive configured Workflow Tasks. It also records OSA, Office of Security, and the organizing society/organization as Permit Copy Owners. The first release does not build a separate OP-to-Venue-Custodian-to-Records correspondence subsystem, reservation calendar, reservation guarantee, schedule-conflict authority, payment flow, or visual/e-signature image flow. Venue availability and reservation correspondence remain external unless a later approved template represents an interaction as an ordinary configured Process Step; EAES-recorded venue usage warnings remain informational and do not grant or deny a reservation.

### **Epic 3: Mobile Companion App (Scanner Mode)**

* **Feature 3.1 \- Account-Authenticated, Event-Scoped Scanner Sessions:** Scanner access is account-based and assignment-scoped. A user may assign scanners only when their active Authority Projection or explicit delegated assignment covers the exact School Year, organization, and `event_participation_scope_id`; a broad role never grants cross-organization scanner power. Eligible institutional student/officer accounts are selected for approved events within that scope. The Flutter scanner app authenticates the user with Google OAuth, lists only active `event_scanner_assignments` for that account, and activates a selected assignment. Activation is the only user-facing scanner session creation path and internally creates a device-bound `scanner_sessions` token for roster hydration, offline capture, and sync. Normal student login alone does not grant scanner hydrate/sync access; only the activated scanner session token can call scanner validation, hydrate, and sync endpoints. `scanner_sessions` remain event-scoped, revocable, expiring, auditable, and device-bound as the runtime session layer, not as shareable dashboard-generated scanner links.
* **Feature 3.2 \- Dual-Mode Attendance Interface:** Allows toggle transitions between Time-In and Time-Out states, accompanied by audio and visual success/failure indicator cues.  
* **Feature 3.3 \- Offline SQLite Storage & Sync Engine:** Executes background synchronizations using the client connectivity listeners and sync throttling models detailed in Section 3\. The mobile app blocks new scan capture outside the allowed event scan window but continues syncing previously captured valid offline scans until the sync grace period closes.

### **Epic 4: Attendance Processing Engine**

* **Feature 4.1 \- Earliest-In / Latest-Out Resolution:** Merges multi-device raw logs down to a single canonical record containing the minimum entry timestamp and maximum exit timestamp per student.  
* **Feature 4.2 \- Organizer-Owned Late Cutoff:** The event organizer may set an optional event-level **Late cutoff** in minutes after the scheduled start time from a separate Attendance Rules control. OSA does not review or approve this attendance policy value. When the organizer leaves the event cutoff unset, each approved participating organization may set a `late_cutoff_min` fallback for its own `event_participation_scope`; otherwise the organizer's event cutoff applies to all scopes. Attendance rules are editable only before the event starts. Attendance reports and analytics use one official attendance status based on this event-first, scope-fallback policy.
* **Feature 4.3 \- Multi-Day and Multi-Session Attendance Management:** Dynamically tracks attendance per event day and per configurable same-day session. Whole-day events may define multiple non-overlapping sessions (for example, pre-lunch and post-lunch segments) with independent Time-In/Time-Out windows, while single-session days remain the default for simpler events and legacy records. Attendance reports present session-level completion (`complete` / `partial`) and an integrated event summary; the event-level evaluation gate remains one submission per event.

### **Epic 5: Student Evaluation Portal & Gate Enforcement**

* **Feature 5.1 \- Event-Specific Digital Activity Evaluation Forms:** Each event proposal carries its own organizer-drafted evaluation form using dynamic 1-5 Likert questions plus one open comment field. OSA reviews the exact form with the PPA metadata, and approval requires a locked event-level evaluation form snapshot that all attendees answer for that approved event.
* **Feature 5.2 \- Evaluation Gate Mechanism:** Keeps a student's AttendanceRecord.valid status set to false until they submit their evaluation form. The submission window closes exactly 24 hours after the event concludes, using the final configured session end time on the final event day when sessions exist.
* **Feature 5.3 \- Event Lifecycle Automation:** Proposal approval remains separate from event runtime state. Approved events use `events.lifecycle_phase` with `scheduled`, `active`, `ended`, `evaluation_open`, `evaluation_closed`, `processing`, and `completed` phases computed from `event_days`, the 24-hour evaluation window, unresolved scans/pending links, sentiment processing, and scanner-session closure.

### **Epic 6: Analytics & Compiled Export Module**

* **Feature 6.1 \- Aggregated Evaluation Analytics:** Real-time dashboards visualize average rating scores, attendance percentages, comment sentiment trends, and demographic breakdowns with first-class School Year, College, Department, Program, Organization or Society, Year Level, and event-type filters. Department appears between College and Program, and each selection narrows the remaining valid options. Historical reports resolve immutable event, participation, membership, and permit snapshots instead of silently changing when master data or officer rosters change.
* **Feature 6.2 \- Verifiable Export Engine:** Generates highly secure, timestamped PDF/Excel outputs of verified attendance and evaluation summaries, including student Program and Year Level report columns. Officers print these digital certificates and attach them to their physical semestral accomplishment reports.
* **Feature 6.3 \- Gawad Parangal Metric Tracking:** An OSA dashboard calculates system-measurable indicators such as Attendance Rates, Membership Active ratios, and Evaluation Averages from the corrected School Year, Department, Program, Organization, membership-status, and Participation Scope model, with manual entry fields for non-system metrics.

## **5\. System Algorithms & Logic Flowcharts**

### **5.1 Earliest-In / Latest-Out (EILO) Sync Resolution Algorithm**

                  ┌───────────────────────────────┐  
                  │   Job Triggered: Event Sync   │  
                  └───────────────┬───────────────┘  
                                  │  
                                  ▼  
                     Retrieve raw\_scans where   
                    event\_id matches current ID  
                                  │  
                                  ▼  
                     Group scans by student\_id,
                  participation scope, day, and
                configured event-day session when present
                                  │  
                                  ▼  
                     For each student grouped:  
                                  │  
      ┌───────────────────────────┴───────────────────────────┐  
      ▼                                                       ▼  
\[Time-In Scans\]                                        \[Time-Out Scans\]  
  Find earliest scanned\_at                               Find latest scanned\_at  
  Store as time\_in                                       Store as time\_out  
      │                                                       │  
      └───────────────────────────┬───────────────────────────┘  
                                  │  
                                  ▼  
                   Evaluate Punctuality Statuses:  
                 Compare time\_in vs. session
                  start\_time using official
                  event/scope late-cutoff logic
                                  │  
                                  ▼  
                     Check Left-Early anomalies:  
                      Is time\_out \< session
                       end\_time \-
                       left\_early\_buffer?  
                                  │  
                                  ▼  
                  UPSERT canonical attendance\_record
            keyed by event, scope, day, session, and student

## **6\. Embedded AI Engine Specification**

### **6.1 LLM Choice: Google Gemini 1.5 Pro**

* **Provider:** Google AI Studio API (Free Tier API Key).  
* **Selection Rationale:** The free tier of **Gemini 1.5 Pro** offers a massive 1-million-token context window and is highly capable at complex logical reasoning, Tagalog/Hiligaynon processing, and structured JSON outputs compared to Flash.  
* **Fallback Strategy:** If rate limits are hit on the 1.5 Pro free tier, the system automatically falls back to **Gemini 2.0 Flash** via a unified abstraction wrapper in Laravel.

### **6.2 Sentiment Analysis Pipeline (Evaluations Module)**

* **Trigger:** Dispatched automatically as a batch background job when an event's evaluation window closes (24 hours post-event).  
* **Input Data:** An array containing anonymized student evaluation feedback text (no PII included to ensure privacy):

\[  
  {"id": 1042, "comment": "The orientation was very helpful but the venue was too crowded and hot."},  
  {"id": 1043, "comment": "Sobra ganda ng pagkakalatag ng activity\! Thank you PSITS officers\!"}  
\]

* **Prompt Engineering Specification:**

SYSTEM PROMPT:  
You are an expert student feedback sentiment classifier for the University of Southern Mindanao.   
Analyze the following list of student comments. Determine if the sentiment is positive, neutral, or negative, and assign a confidence score (0.0 to 1.0).  
Your response must be returned strictly in JSON format matching the schema requested.   
Comments can be written in Tagalog, English, Taglish, or regional Mindanao dialects.

JSON Response Schema:  
{  
  "results": \[  
    {  
      "id": \<integer\>,  
      "sentiment": "positive" | "neutral" | "negative",  
      "score": \<float\>  
    }  
  \]  
}

* **Storage Mapping:** The background job parses the JSON response and updates the evaluations.sentiment and evaluations.sentiment\_score columns on matching IDs in PostgreSQL.

### **6.3 NLP Query Assistant (Admin Dashboard Chat)**

* **Implementation Pattern:** System uses a structured **Text-to-SQL / ORM Query Builder mapping pattern**. The LLM reads the natural language question, processes the user's Laravel Spatie role scope, and outputs structured query parameters.  
* **Safety & Security Boundary:** To prevent prompt injection and unauthorized cross-tenant access, the database schema is mapped via read-only SQL views, and the backend dynamically appends the user's active Authority Projection for the selected School Year and exact organization, Department, College, or University scope as hard parameters to the query wrapper before execution.

## **7\. Database Entity Schema Blueprint (PostgreSQL 16\)**

┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐  
│  universities   │─\< │    colleges     │─\< │   departments   │─\< │    programs     │  
└─────────────────┘   └─────────────────┘   └────────┬────────┘   └─────────────────┘  
                                                             │  
                                                             ▼  
┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐  
│  school\_years   │─\< │ officer\_rosters │─\< │  organizations  │─\< │     events      │  
└─────────────────┘   └─────────────────┘   └─────────────────┘   └────────┬────────┘  
                                                                          │  
┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐           ▼  
│      users      │─\< │   raw\_scans    │─\< │attendance\_recs │       event\_days  
└────────┬────────┘   └─────────────────┘   └─────────────────┘  
         ▼  
   evaluations

### **7.1 Primary Data Tables**

#### **universities**

* id (UUID, Primary Key)  
* name (String)  
* domain (String, e.g., "usm.edu.ph")

#### **colleges**

* id (UUID, Primary Key)  
* university\_id (UUID, Foreign Key)  
* name (String)  
* code (String, e.g., "CEIT")

#### **departments**

* id (UUID, Primary Key)  
* college\_id (UUID, Foreign Key to colleges)  
* name (String)  
* code (String; unique with college\_id)  
* status, timestamps  

#### **organizations**

* id (UUID, Primary Key)  
* college\_id (UUID, Nullable; used only for genuinely College-scoped organizations such as an LSG)  
* department\_id (UUID, Nullable; required for Societies and Department-backed Academic Organizations)  
* name (String)  
* acronym (String, e.g., "PSITS")  
* type (Enum/String: society, academic\_organization, usg, lsg, non\_academic\_organization, academic\_unit, administrative\_office)  
* logo\_path (String, Nullable)  
* status (String, e.g., "active")
* Scope constraint: USG is University-wide; LSG is College-scoped; Society/Academic Organization is Department-backed; a non-academic organization may be University-wide or College-affiliated.  

#### **programs**

* id (UUID, Primary Key)  
* department\_id (UUID, Foreign Key to departments)  
* name (String)  
* code (String, unique with department\_id)  
* A Program's College is always resolved through its Department; no direct `college_id` is stored.  

#### **organization\_programs**

* organization\_id (UUID, Foreign Key)  
* program\_id (UUID, Foreign Key)
* Constraint: an Academic Organization may link only to Programs owned by its own Department.  

#### **users**

* id (UUID, Primary Key)  
* google\_sub (String, Unique)  
* email (String, Unique)  
* name (String)  
* role (String, handled via Spatie roles relationship)  
* college\_id / department\_id / program\_id / organization\_id (UUID, Nullable current Student Academic Path projections, not admin tenant scope)  
* user\_type (Enum/String: student, faculty, staff, faculty\_or\_staff\_pending)  
* year\_level (String, Nullable; controlled student demographic for analytics/report filters)
* student\_id\_number (String, Unique, Nullable)  
* qr\_code\_value (String, Unique, Nullable)

#### **school\_years**

* id (UUID, Primary Key)  
* label (String, Unique), starts\_on, ends\_on  
* status (draft, active, closed)  
* transition\_started\_at, transition\_ends\_at, transition\_closed\_by, transition\_closed\_at (Nullable)  
* activated\_by, activated\_at, closed\_by, closed\_at, timestamps  
* Exactly one School Year may be active. A transition window belongs to that active School Year and preserves outgoing authority without becoming a second active status; OSA lifecycle actions, not calendar inference, are authoritative.  

#### **organization\_memberships**

* id (UUID, Primary Key)  
* user\_id (UUID, Foreign Key to users)  
* organization\_id (UUID, Foreign Key to organizations)  
* year\_level (String, Nullable; mirrors current profile-sourced student standing where applicable)
* school\_year\_id / college\_id / department\_id / program\_id (UUID, Foreign Keys as applicable)  
* membership\_type (primary\_academic, secondary\_org, non\_academic)  
* status (active, inactive, pending, rejected)  
* source, verified\_by, verified\_at  
* timestamps

#### **employee\_profiles / employee\_roster\_entries**

* Employee profile and imported roster tables store faculty/staff `employee_id`, email, office/department, college, position title, verification status, and QR code value where available.  
* HR roster records are the verification source for HR-domain scanner hydration and sync validation.

#### **organization\_officer\_rosters / organization\_officers**

* Officer Roster: id, organization\_id, school\_year\_id, status (draft, submitted, changes\_requested, verified, locked, superseded), evidence/attestation metadata, submitted\_by/at, verified\_by/at, verification\_remarks, timestamps  
* Organization Officer: id, officer\_roster\_id, user\_id, position\_key (president, secretary, adviser, or optional configured position), status (active, replaced, revoked, expired), effective dates, replacement/revocation metadata, timestamps  
* President and Secretary must be selected from registered members of the organization. Adviser selection resolves an eligible institutional account. A verified roster requires President, Secretary, and Adviser.  

#### **officer\_change\_requests**

* id, organization\_id, school\_year\_id, officer\_roster\_id, change\_type, affected\_officer\_id, proposed\_user\_id, reason, evidence metadata  
* status, requested\_by/at, adviser\_decision\_by/at, OSA override metadata, timestamps  
* Approved changes preserve the former officer row as replaced, revoked, or expired rather than deleting it.  

#### **admin\_assignments**

* id (UUID, Primary Key)  
* user\_id (UUID, Foreign Key to users)  
* role\_name (String)  
* organization\_id (UUID, Nullable)  
* college\_id / department\_id (UUID, Nullable)  
* school\_year\_id (UUID, Foreign Key), term\_start, term\_end, position\_title  
* source\_type / source\_id (verified officer, OSA override, bootstrap, or independent HR source)  
* status (active, expired, revoked)  
* is\_primary\_admin (Boolean)  
* approved\_by, approved\_at, revoked\_by, revoked\_at, revocation\_reason
* This table is an Authority Projection for authorization consumers. Verified Officer Rosters remain the source of truth for normal student-organization authority.  

#### **osa\_authority\_overrides**

* id, user\_id, school\_year\_id, organization/Department/College scope, role\_name, action (grant, replace, lock, revoke, correct), reason, effective dates, status  
* created\_by/at, revoked\_by/at, audit metadata  
* Overrides are managed only from OSA advanced/exception pages and project into `admin_assignments` when active.  

#### **venues**

* id (UUID, Primary Key)
* name, campus\_location
* college\_id (UUID, Nullable owning college)
* managing\_office, inquiry\_instructions (Nullable)
* is\_active, retired\_at, timestamps

#### **events**

* id (UUID, Primary Key)  
* organization\_id (UUID, Foreign Key)  
* school\_year\_id (UUID, Foreign Key to school\_years)  
* title (String)  
* status (Enum: "draft", "submitted", "under\_review", "revision\_requested", "approved", "rejected", "completed")
* lifecycle\_phase (Enum: "scheduled", "active", "ended", "evaluation\_open", "evaluation\_closed", "processing", "completed")  
* event\_domain (Enum/String: student\_affairs, hr, academic\_records, academic\_unit)  
* start\_date (Date)  
* end\_date (Date)  
* venue\_id (UUID, Nullable Foreign Key to venues; required for submitted on-campus organizer PPAs)
* location\_type, location\_details (on-campus catalog snapshot or off-campus venue name/address)
* venue\_confirmation\_office, venue\_confirmation\_date, venue\_confirmation\_reference\_number
* venue\_confirmation\_document\_path, venue\_confirmation\_document\_original\_name
* permit\_version (Integer, Default 0), pending\_venue\_amendment\_reason (Nullable)
* society\_late\_threshold\_min (Integer, Default 15, legacy fallback)
* general\_competition\_threshold\_min (Integer, Default 30, legacy compatibility)
* event\_late\_cutoff\_min (Integer, Nullable, organizer-owned event policy)
* left\_early\_buffer\_min (Integer, Default 15\)  
* evaluation\_open (Boolean, Default false)

#### **event\_days**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* day\_number (Integer)  
* date (Date)  
* start\_time (Time)  
* end\_time (Time)

#### **event\_day\_sessions**

* id (UUID, Primary Key)
* event\_day\_id (UUID, Foreign Key to event\_days)
* session\_number (Integer, ordered within the day)
* name (String)
* start\_time (Time)
* end\_time (Time)
* required\_for\_attendance (Boolean, Default true)

#### **event\_permit\_versions**

* id (UUID, Primary Key), event\_id, version\_number (sequential and unique per event)
* venue\_id (Nullable), venue\_name\_snapshot, venue\_location\_snapshot
* location\_type, location\_details
* confirmation\_office, confirmation\_date, confirmation\_reference\_number
* confirmation\_document\_path, confirmation\_document\_original\_name
* ppa\_document\_path, ppa\_document\_original\_name, amendment\_reason
* school\_year, College, Department, Program, Organization, and approver human-readable snapshots  
* approved\_by, approved\_at, superseded\_at, timestamps

#### **event\_participation\_scopes**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key to events)  
* parent\_scope\_id (UUID, Nullable self-reference)  
* linked\_event\_id (UUID, Nullable)  
* school\_year\_id (UUID, Foreign Key to school\_years)  
* participation\_role (organizer, participant)  
* participant\_type (college, organization, society, program, non\_academic\_org, academic\_unit, administrative\_office, custom\_roster, hr\_unit)  
* organization\_id / college\_id / department\_id / program\_id (UUID, Nullable)  
* participant\_name\_snapshot  
* ppa\_required, ppa\_status, ppa\_softcopy\_path, hardcopy\_submitted, org\_head\_signed, adviser\_signed  
* late\_cutoff\_min (Integer, Nullable; participant-scope fallback only when the parent event cutoff is unset)
* submitted\_by, submitted\_at, reviewed\_by, reviewed\_at, review\_remarks, approved\_at  
* capture\_mode (self\_scanning, parent\_scanning, delegated\_scanning, central\_gate)  
* delegated\_to\_organization\_id (UUID, Nullable)  
* analytics\_rollup\_enabled, status, timestamps

#### **scanner\_sessions**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* assignment\_id (UUID, Foreign Key to event\_scanner\_assignments, Nullable for legacy deep links)  
* event\_participation\_scope\_id (UUID, Foreign Key to event\_participation\_scopes, Nullable for legacy sessions)  
* scanner\_user\_id (UUID, Foreign Key to users, Nullable)  
* token\_id (String reference to Sanctum token)  
* issued\_by (UUID, Foreign Key to users)  
* label (String, Nullable)  
* device\_id (String, Nullable)  
* device\_fingerprint\_hash (String, Nullable)  
* status (Enum: "active", "expired", "revoked")  
* issued\_at, activated\_at, expires\_at, revoked\_at, last\_seen\_at (DateTime TZ)  
* revoked\_by (UUID, Nullable)  
* revocation\_reason (Text, Nullable)

#### **event\_scanner\_assignments**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* event\_participation\_scope\_id (UUID, Foreign Key to event\_participation\_scopes)  
* audience\_scope\_type (String, Nullable)  
* scanner\_user\_id (UUID, Foreign Key to users)  
* assigned\_by\_user\_id (UUID, Foreign Key to users)  
* label (String, Nullable)  
* status (Enum/String: "active", "revoked", "expired")  
* revoked\_at, revoked\_by\_user\_id, revocation\_reason (Nullable)  
* expires\_at (DateTime TZ, Nullable; normally aligned to sync grace)  
* max\_devices (Integer, Default 1)  
* notes (Text, Nullable)  
* last\_activated\_at (DateTime TZ, Nullable)  
* timestamps

#### **raw\_scans**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* event\_participation\_scope\_id (UUID, Foreign Key to event\_participation\_scopes, Nullable for legacy scans)  
* event\_day\_id (UUID, Foreign Key, Nullable)  
* event\_day\_session\_id (UUID, Foreign Key to event\_day\_sessions, Nullable for legacy/full-day scans)
* scanner\_session\_id (UUID, Foreign Key, Nullable)  
* scanner\_user\_id (UUID, Foreign Key, Nullable)  
* captured\_by\_organization\_id (UUID, Nullable)  
* captured\_by\_admin\_scope (String, Nullable)  
* student\_id (UUID, Foreign Key)  
* scan\_type (Enum: "time\_in", "time\_out")  
* scanned\_at (DateTime TZ)  
* server\_received\_at (DateTime TZ, Nullable)  
* processed\_at (DateTime TZ, Nullable)  
* device\_id (String)  
* manual\_entry (Boolean, Default false)  
* dedup\_key (String, Unique)

#### **attendance\_records**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* event\_participation\_scope\_id (UUID, Foreign Key to event\_participation\_scopes, Nullable for legacy records)  
* event\_day\_id (UUID, Foreign Key, Nullable)  
* event\_day\_session\_id (UUID, Foreign Key to event\_day\_sessions, Nullable for legacy/full-day records)
* student\_id (UUID, Foreign Key)  
* time\_in (DateTime TZ, Nullable)  
* time\_out (DateTime TZ, Nullable)  
* society\_status (Enum: "present\_on\_time", "late", "late\_cutoff", "absent"; official event/scope cutoff status)
* competition\_status (Enum: "present\_on\_time", "late", "late\_cutoff", "absent"; compatibility mirror of official status)
* left\_early (Boolean, Default false)  
* completion\_status (Enum/String: "complete", "partial"; per-session Time-In/Time-Out completion summary)
* valid (Boolean, Default false) \-\> *Toggled to true via the Evaluation Gate*  
* force\_validated (Boolean, Default false)  
* validated\_by (UUID, Foreign Key, Nullable)

#### **evaluations**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* student\_id (UUID, Foreign Key)  
* section\_scores (JSONB)  
* open\_comment (Text, Nullable)  
* sentiment (Enum: "positive", "neutral", "negative", "unprocessed", Nullable)  
* sentiment\_score (Float, Nullable)  
* submitted\_at (DateTime TZ)

## **8\. Non-Functional Requirements**

* **Local Write Speeds:** Device SQLite database writes must commit in under **100 ms** during continuous QR scanning to prevent interface lag.  
* **Concurrency Protection:** The Laravel API bulk sync endpoint must use background queues to handle up to **2,000 scans/minute** without performance degradation on primary database servers.  
* **Security & PII Shielding:** Student names, course details, and raw scan keys are encrypted in-transit over HTTPS. In-app camera scanners cannot decode or display other student database details except name and course validation cards.  
* **Audit Trail Compliance:** Any force-validations of attendance or override changes to evaluations are stored in an immutable audit\_logs database table tracking the target ID, admin user ID, and timestamp.

**END OF PRODUCT REQUIREMENTS DOCUMENT**
