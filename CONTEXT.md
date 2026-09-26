# USM EAES

USM EAES manages the digital event proposal, approval, attendance, and evaluation lifecycle for university events. This glossary keeps proposal workflow language consistent as the system moves from document/signature tracking toward guided digital approvals.

## Language

**Proposal Process Template**:
An OSA-managed process guide that defines the checklist a proposal must follow before final approval. OSA may revise templates over time through published template versions. Each template version is copied into a proposal-specific workflow run so later template edits do not silently change proposals already in progress.
_Avoid_: File template, document template, static checklist

**Canonical PPA Packet**:
The single structured proposal record organizers, co-organizers, and participant organizations complete in EAES. It captures the overlapping information required by the PPA Proposal and Student Activity Permit, plus role-specific and section-specific details, so the system can render proposal and permit outputs without requiring duplicate external form documents.
_Avoid_: External proposal file, separate permit form, duplicate document entry

**Department**:
An academic subdivision inside a College. Departments own academic Programs; a College may contain many Departments, and a Department may contain many Programs.
_Avoid_: College, office department text field

**Program**:
A degree program or course under a Department. A student's Program determines their Department and helps determine which academic organizations or societies they are eligible to join.
_Avoid_: Department, organization, society

**Academic Organization**:
A student organization whose institutional home is a Department and whose covered Programs belong to that same Department. A Society is a common academic organization type; it may cover one or more Programs but should not be treated as only College-scoped.
_Avoid_: College-only society, cross-department society

**Student Academic Path**:
The ordered academic classification used for student profile setup and filtering: College, Department, Program, eligible Organization or Society, then Year Level. Each earlier choice narrows the later choices.
_Avoid_: Free-form profile scope, manually mismatched college/program/society

**School Year**:
An OSA-controlled academic cycle used to govern term-bound organization officers, admin authority, approver assignments, memberships, proposals, and analytics. It has institutional dates and lifecycle state; the active School Year is selected by OSA, not inferred from the calendar alone.
_Avoid_: Academic-year string, display-only filter

**Active School Year**:
The single School Year OSA has activated for current officer authority, new proposal defaults, membership profiling, and operational reporting. EAES should not treat more than one School Year as active at the same time.
_Avoid_: Current year formula, date-only current year

**School Year Transition Period**:
An OSA-controlled window after a new School Year begins where outgoing officers may keep continuity authority while elections and officer profiling finish. Outgoing President and Secretary may still create and submit proposals during this window because they remain officers until OSA closes or replaces that authority.
_Avoid_: Automatic officer reset, unmanaged grace period

**School Year Rollover**:
The institutional changeover from one School Year to the next. Rollover may preserve outgoing authority during the transition period, but new-year authority becomes settled only through verified officer profiles or OSA-controlled exceptions.
_Avoid_: Automatic carry-over, silent authority renewal

**Organization Officer**:
A term-bound person holding an official position in an organization for a School Year, such as President or Secretary. Officer roles can drive organization administration and proposal preparation authority.
_Avoid_: Permanent admin, student profile membership

**Officer Roster**:
The School-Year-specific list of official officers for an organization, created by selecting registered students from that organization rather than manually encoding separate person records. The roster becomes official through adviser verification, with OSA retaining exception and audit control.
_Avoid_: Free-form officer list, OSA-maintained admin list

**Adviser Verification**:
The adviser action that confirms an organization's submitted officer roster or officer change is official for the School Year. Adviser verification is the normal authority for officer profiling, while OSA handles exceptions, disputes, and forced corrections.
_Avoid_: OSA manual approval, unchecked officer claim

**Officer Change Request**:
A submitted correction, resignation, or replacement affecting an official officer roster during a School Year. Approved changes preserve the old officer record as replaced, revoked, or expired rather than deleting history.
_Avoid_: Silent roster edit, officer deletion

**Officer-Derived Authority**:
Organization administration authority granted because a student holds a verified officer role for the active School Year or an active School Year transition period. President and Secretary are the first authority-bearing roles for Societies and academic organizations.
_Avoid_: Manually assigned society admin, permanent admin role

**Authority Projection**:
The permission-facing representation of verified officer authority used by dashboards, APIs, and scoped authorization checks. It may be implemented through admin assignments, roles, or another internal structure, but the verified Officer Roster remains the domain source of truth.
_Avoid_: Authority source of truth, officer roster replacement

**OSA Authority Override**:
An exceptional OSA action that grants, replaces, locks, or corrects organization authority outside the normal adviser-verified officer flow. Overrides require a reason and audit trail and belong to an advanced or exception-control surface, not the normal roster workflow.
_Avoid_: Normal society admin assignment, hidden permission edit

**Published Template Version**:
An immutable version of a proposal process template that can be applied to proposal submissions. Workflow runs keep their link to the published template version they copied from.
_Avoid_: Editable template, live checklist

**Active Template Version**:
The published template version OSA has selected for new matching proposal submissions. A draft proposal follows the active version only until its first submission creates a workflow run.
_Avoid_: Current task list, proposal status

**Template Applicability Rule**:
The OSA-defined rule that determines which active template version applies to a proposal based on the proposal's organizer authority, event domain, or other approved proposal classification.
_Avoid_: Approver routing, security workflow

**Workflow Run**:
The live copy of a proposal process template attached to one proposal subject. A workflow run tracks the proposal's step-by-step progress separately from the proposal's coarse lifecycle status.
_Avoid_: Proposal status, event status

**Process Step**:
One checklist item defined by a proposal process template. A process step describes what must happen in the process, not the live state of a specific proposal.
_Avoid_: Task, status

**Workflow Task**:
The live action created from a process step inside a workflow run. A workflow task carries its assignee, status, decision, remarks, and timestamps for one proposal.
_Avoid_: Process step, signature flag

**Proposal Subject**:
The item a workflow run is attached to. The first proposal subjects are organizer event proposals and participant-scope PPAs attached to a parent event; security requests and standalone venue-custodian requests are future subject types.
_Avoid_: Workflow owner, event only

**Approver**:
A person, role, or small responsible group assigned to review and decide a workflow task. Use this term for proposal and permit signers such as proponent, organization head, adviser, department chairperson, college dean, Chief Security, OSA, and venue custodian.
_Avoid_: Signatory, office actor

**Institutional Approver Account**:
An account representing a distinct university approval actor who may decide assigned workflow tasks, including OSA, Chief Security, department chairperson, college dean, adviser, organization head, and venue custodian. Separate offices such as OSA and Chief Security keep separate roles, permissions, and approval responsibilities.
_Avoid_: External signer, paper signatory, shared office role

**Assignment Resolver Rule**:
The rule that determines which approver account, role, or office receives a workflow task from proposal context such as organizer scope, college, venue, event domain, or process step configuration.
_Avoid_: Hard-coded approver, manual routing

**Participation Scope**:
The event-specific audience boundary for organizer or participant attendance, scanner hydration, PPA status, and roll-up analytics. A participant organization can complete a Canonical PPA Packet with activity role Participant without duplicating the organizer's venue-confirmation route by default.
_Avoid_: Event-wide roster, duplicated participant event

**Venue Custodian**:
The institutional approver responsible for confirming or denying use of a managed venue after the permit path reaches venue-use approval.
_Avoid_: Venue owner, reservation clerk

**Permit Copy Owner**:
An actor that must retain an official copy of the approved permit after completion. In the current process, OSA, the Office of Security, and the organizing society are permit copy owners.
_Avoid_: CC recipient, notification target

**Approval Decision**:
The recorded outcome of an approval workflow task. Standard decisions are Approved, Rejected, Revision Requested, and Not Required for conditional steps.
_Avoid_: Signature, signage

**Approval Trail**:
The auditable record of completed workflow task decisions for a proposal, including actor, role or scope, decision, remarks, timestamp, affected workflow task, and resulting proposal or workflow state. It replaces physical signature evidence for dashboard approvals.
_Avoid_: Signature sheet, e-signature image

**Organizer Checklist**:
The organizer-facing view of a workflow run. It shows the proposal's required steps, blocked items, completed tasks, returned items, and next action before OSA final approval.
_Avoid_: Form wizard, proposal status

**Digital Permit**:
The downloadable final permit artifact created only after every required workflow task is approved or marked Not Required and OSA gives final proposal approval. It shows approval metadata from the Approval Trail rather than visual signature images.
_Avoid_: Draft permit, signed paper scan

**Proposal Status**:
The coarse lifecycle state of an event proposal, such as draft, submitted, under review, revision requested, approved, or rejected. Proposal status remains separate from workflow progress.
_Avoid_: Workflow progress, task status

**Signature Flag**:
A legacy compatibility or export field that represents an approval formerly tracked as a physical signature. New workflow behavior should treat workflow task decisions as the source of truth instead of signature flags.
_Avoid_: Approval source of truth
