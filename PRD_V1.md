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
* To accommodate the multi-tenant hierarchical structure of USM (Universities ![][image1] Colleges ![][image1] Societies ![][image1] Programs), permissions are strictly scoped in database queries by joining the user's organization\_id or college\_id.

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
| **USG Admin** | University-wide events only | create-proposals, assign-scanners-own, view-own-analytics |
| **ARO Admin** | University-level ARO events, including Recognition and Graduation | create-proposals, assign-scanners-own, view-own-analytics |
| **LSG Admin** | Constituent societies inside their College boundary | create-proposals, view-college-analytics, view-constituent-data |
| **Society Admin** | Strictly restricted to their own specific Society | create-proposals, assign-scanners-own, set-late-threshold, view-own-analytics, force-validate-own |
| **Scanner** | Temporarily locked to a single assigned event session | scan-qr-codes, manual-entry-id |
| **Faculty** | Own personal profiles | view-own-history, submit-evaluations |
| **Student** | Own personal profile and personal logs | register-profile, view-own-history, submit-evaluations |

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
* **Data Footprint:** On average, a hydrated record is approximately ![][image2].  
  ![][image3]  
  This lightweight ![][image4] payload is compressed via gzip on transfer, allowing sync to complete in under 3 seconds over standard local Wi-Fi.  
* **Sync Triggers:** The scanner app automatically downloads a fresh student roster when:  
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
  ![][image5]  
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
* **Feature 1.2 \- Student Profile Hydration & QR Registration:** Upon initial registration, students must fill out their hierarchical profile (College ![][image1] Society ![][image1] Program) and parse their physical ID QR code using their webcam.  
* **Feature 1.3 \- Admin Applications & Assignment Governance:** Students use one institutional account and may apply for USG, ARO, LSG, or Society Admin authority. OSA reviews applications, may approve new Society registrations, and creates term-bound `admin_assignments`. `admin_assignments` are the source of truth for administrative authority; Spatie roles plus `users.organization_id` / `users.college_id` are compatibility projections synchronized only by `AdminAssignmentService`.
* **Onboarding Master Data Rule:** Colleges and Programs are official institutional master data seeded before deployment and maintained only by OSA/Super Admin. USG, ARO, and per-college LSG shells are seeded governance organizations. Society records may be requested by students/officers but become active only after OSA approval.

### **Epic 2: Event Proposal & Permit System (PPA)**

* **Feature 2.1 \- Digital PPA Submission:** Society, USG, LSG, and ARO Admins construct digital proposals featuring all fields from the official paper forms, including budget allocations, location classifications (on-campus vs. off-campus), and target demographics.  
* **Feature 2.2 \- OSA Proposal Review Pipeline:** A central dashboard allowing OSA Directors to approve or reject PPAs. The scanner interface for any event remains locked until the proposal status transitions to Approved.  
* **Feature 2.3 \- Multi-Organization Event Linking:** Allows USG/LSG/ARO to list a parent event and link sub-organizational PPAs as participant proposals under a master permit.
* **Organizer Rule \- Recognition and Graduation:** Recognition and Graduation events are ARO-owned university-level events. They should be created under an ARO organization (`organizations.type = "aro"`) rather than under a Society, LSG, or USG organizer unless an approved deviation explicitly says otherwise. Their attendee audience can span multiple colleges and societies, so organizer ownership must not be treated as the student-membership boundary.

### **Epic 3: Mobile Companion App (Scanner Mode)**

* **Feature 3.1 \- Deep-Linked Scanner Sessions:** Society officers can be designated as scanners. Tapping their unique tokenized link launches the Flutter scanner app and locks them into an event-scoped scanning interface.  
* **Feature 3.2 \- Dual-Mode Attendance Interface:** Allows toggle transitions between Time-In and Time-Out states, accompanied by audio and visual success/failure indicator cues.  
* **Feature 3.3 \- Offline SQLite Storage & Sync Engine:** Executes background synchronizations using the client connectivity listeners and sync throttling models detailed in Section 3\.

### **Epic 4: Attendance Processing Engine**

* **Feature 4.1 \- Earliest-In / Latest-Out Resolution:** Merges multi-device raw logs down to a single canonical record containing the minimum entry timestamp and maximum exit timestamp per student.  
* **Feature 4.2 \- Dual-Threshold Calculations:** Independently evaluates the **Society Threshold** (configurable per event) and the **General Competition Threshold** (set globally by OSA) to assign correct statuses.  
* **Feature 4.3 \- Multi-Day Session Management:** Dynamically tracks attendance on a daily basis for multi-day events, generating daily canonical entries while presenting an integrated summary.

### **Epic 5: Student Evaluation Portal & Gate Enforcement**

* **Feature 5.1 \- Digital Activity Evaluation Form:** Houses the official USM-OSA digital evaluation format containing Likert scale categories and open comment text fields.  
* **Feature 5.2 \- Evaluation Gate Mechanism:** Keeps a student's AttendanceRecord.valid status set to false until they submit their evaluation form. The submission window closes exactly 24 hours after the event (or final day) concludes.

### **Epic 6: Analytics & Compiled Export Module**

* **Feature 6.1 \- Aggregated Evaluation Analytics:** Real-time dashboards visualizing average rating scores, attendance percentages, demographic breakdowns, and comment sentiment trends.  
* **Feature 6.2 \- Verifiable Export Engine:** Generates highly secure, timestamped PDF/Excel outputs of verified attendance and evaluation summaries. Officers print these digital certificates and attach them to their physical semestral accomplishment reports.  
* **Feature 6.3 \- Gawad Parangal Metric Tracking:** An OSA dashboard calculating system-measurable indicators (e.g., Attendance Rates, Membership Active ratios, Evaluation Averages) combined with manual entry fields for non-system metrics.

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
                     Group scans by student\_id  
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
                 Compare time\_in vs. start\_time   
                  using Dual-Threshold logic  
                                  │  
                                  ▼  
                     Check Left-Early anomalies:  
                      Is time\_out \< end\_time \-   
                       left\_early\_buffer?  
                                  │  
                                  ▼  
                  UPSERT canonical attendance\_record

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
* **Safety & Security Boundary:** To prevent prompt injection and unauthorized cross-tenant access, the database schema is mapped via read-only SQL views, and the backend dynamically appends the Spatie-resolved organization\_id or college\_id as hard parameters to the query wrapper before execution.

## **7\. Database Entity Schema Blueprint (PostgreSQL 16\)**

┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐  
│  universities   │ ───\<  │    colleges     │ ───\<  │  organizations  │  
└─────────────────┘       └─────────────────┘       └────────┬────────┘  
                                                             │  
                                                             ▼  
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐  
│      users      │ ───\<  │  raw\_scans (DB) │ ───\<  │     events      │  
└────────┬────────┘       └────────┬────────┘       └────────┬────────┘  
         │                         │                         │  
         ▼                         ▼                         ▼  
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐  
│   evaluations   │ ───\<  │attendance\_recs  │ ───\<  │   event\_days    │  
└─────────────────┘       └─────────────────┘       └─────────────────┘

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

#### **organizations**

* id (UUID, Primary Key)  
* college\_id (UUID, Foreign Key, Nullable for university-wide orgs)  
* name (String)  
* acronym (String, e.g., "PSITS")  
* type (Enum: "society", "usg", "lsg", "aro")
* logo\_path (String, Nullable)  
* status (String, e.g., "active")

#### **programs**

* id (UUID, Primary Key)  
* college\_id (UUID, Foreign Key)  
* name (String)  
* code (String, unique with college\_id)

#### **organization\_programs**

* organization\_id (UUID, Foreign Key)  
* program\_id (UUID, Foreign Key)

#### **users**

* id (UUID, Primary Key)  
* google\_sub (String, Unique)  
* email (String, Unique)  
* name (String)  
* role (String, handled via Spatie roles relationship)  
* organization\_id (UUID, Foreign Key, Nullable)  
* student\_id\_number (String, Unique, Nullable)  
* qr\_code\_value (String, Unique, Nullable)

#### **admin\_applications**

* id (UUID, Primary Key)  
* applicant\_id (UUID, Foreign Key to users)  
* request\_type (String: existing\_usg, existing\_aro, existing\_lsg, existing\_society, new\_society)  
* role\_name (String)  
* organization\_id (UUID, Nullable)  
* college\_id (UUID, Nullable)  
* organization\_name / organization\_acronym (Nullable for new Society requests)  
* academic\_year, term\_start, term\_end, position\_title  
* proof\_document\_path, logo\_path (Nullable)  
* status (pending, approved, rejected)  
* reviewed\_by, reviewed\_at, review\_remarks

#### **admin\_assignments**

* id (UUID, Primary Key)  
* user\_id (UUID, Foreign Key to users)  
* role\_name (String)  
* organization\_id (UUID, Nullable)  
* college\_id (UUID, Nullable)  
* academic\_year, term\_start, term\_end, position\_title  
* status (active, expired, revoked)  
* is\_primary\_admin (Boolean)  
* approved\_by, approved\_at, revoked\_by, revoked\_at, revocation\_reason

#### **events**

* id (UUID, Primary Key)  
* organization\_id (UUID, Foreign Key)  
* title (String)  
* status (Enum: "draft", "submitted", "under\_review", "approved", "rejected", "completed")  
* start\_date (Date)  
* end\_date (Date)  
* society\_late\_threshold\_min (Integer, Default 15\)  
* general\_competition\_threshold\_min (Integer, Default 30\)  
* left\_early\_buffer\_min (Integer, Default 15\)  
* evaluation\_open (Boolean, Default false)

#### **event\_days**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* day\_number (Integer)  
* date (Date)  
* start\_time (Time)  
* end\_time (Time)

#### **raw\_scans**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* event\_day\_id (UUID, Foreign Key, Nullable)  
* student\_id (UUID, Foreign Key)  
* scan\_type (Enum: "time\_in", "time\_out")  
* scanned\_at (DateTime TZ)  
* device\_id (String)  
* manual\_entry (Boolean, Default false)  
* dedup\_key (String, Unique)

#### **attendance\_records**

* id (UUID, Primary Key)  
* event\_id (UUID, Foreign Key)  
* event\_day\_id (UUID, Foreign Key, Nullable)  
* student\_id (UUID, Foreign Key)  
* time\_in (DateTime TZ, Nullable)  
* time\_out (DateTime TZ, Nullable)  
* society\_status (Enum: "present\_on\_time", "late", "late\_cutoff", "absent")  
* competition\_status (Enum: "present\_on\_time", "late", "late\_cutoff", "absent")  
* left\_early (Boolean, Default false)  
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

* **Local Write Speeds:** Device SQLite database writes must commit in under ![][image6] during continuous QR scanning to prevent interface lag.  
* **Concurrency Protection:** The Laravel API bulk sync endpoint must use background queues to handle up to ![][image7] without performance degradation on primary database servers.  
* **Security & PII Shielding:** Student names, course details, and raw scan keys are encrypted in-transit over HTTPS. In-app camera scanners cannot decode or display other student database details except name and course validation cards.  
* **Audit Trail Compliance:** Any force-validations of attendance or override changes to evaluations are stored in an immutable audit\_logs database table tracking the target ID, admin user ID, and timestamp.

**END OF PRODUCT REQUIREMENTS DOCUMENT**

[image1]: <data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABUAAAAZCAYAAADe1WXtAAABYklEQVR4Xq2UX0rEMBDGU1jBRUEQS7F2M2nxxeeCZ/AWgpdw7yD45gE8gzfaRy+h07QxM5lJtgv+lmyZ+b7Jv9muMf9OpSembyYJ34xPZ7S8kMsbunx86FRRLfoUSGmBVaYTKN9VwlrfH0q3Kv85FaWC3nTbtjsAeHPOnRNLhHWQIDML2I1hGK5w0i8L8JDKvjAMlaxgjLX2dRohXtmDZTliTGv63j12XXc/R6mK1HV9ice5BTZsEsf87MWntQfMvXS7bpvOafDS95OBDzhYIDGAfwL1APzgpN9Y/5zOSVCOkbn5pmkucMJ3vIKtUEWCwDUWbXCXHz7J0oUmUOg7vex5g8f9dL17CllaH/2lWSnow+PeTb9TfAlu5L9IGnvUJGMcxzPs9rVZYy4hqkVCTSmIo6Uc00scrZ0MYYQ4yyz6Das+2nVqYOasICirAbYbbX05jcyoBNty7CgQltVFE3n8C0ZFKuuNQNxjAAAAAElFTkSuQmCC>

[image2]: <data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEkAAAAZCAYAAAB9/QMrAAAFcElEQVR4Xs1YS4gcRRieYSKs+FpNNpudR1f37soaEIysGiLxhQrmkIsKRjaH3LIH9RBQUS/x4EG9hBBQ1hchB1FhQVZRNIfFBAx6EMR1g4mHhLBBJSyKHoxm9fu6/uquruma6Zn0iB/8W11//Y+qr/+q6tlKZYCoevquvgj68XFRRgwL7eHaNf2gnCj9o+qdgU/vRc8OHZDG6h5VW1SLmBZDhzgdhnzo1aWIfRGb/xolzGlkZOTqVqt1M8XoMmGlE4bhcLPZvHF6evoKe9jFxMTERqXUsSAIzqJdZHzX5rJQwpp7AhbxKmQFBByGvIfnd0HEDbYN9Jugn4f8hIV/iPYC5AkM1Ww7A/hfifEdkLcDIanbunLHc5XxfIa8gzlILOWhuCeABU+3Ws2kekS3HYtbZct+FIa70P9HBWrG2HCS0H0MOZd6pjCTYPyyKykMoyHE3enqS4XNIpNh8ffZWlnYb6wE9oWMvwxpxha6gyQvcczBIEjikeAlyS0Rt+9DJzupml/QPlgVUzzvDlTwQ71eb7GP8TOQP7hg7SUkBcGzNkl5eVySeKbF51mesQXf8Pj4+HWId9RLkgA2Y5TMy5Gg3AVRFCmOTU5OXku7xCYPNORCReZJDNpzkIeNDQmySbK2UkySPh/yISSdgDwPOYn+p2j/RntgdHT0Ktqg/yX6Z42gvyK+T1q6g8gzi/Z3mWsinIfJx5i0hXwA+QhyiX1n7Bv4PMMW8qftn0G80JTZh2D8M2RNEi9DNhtb5ZBkYEjqtJWEpGNTU1PXWOoqdIfoi/NllvPgQS8ErnE+jh0viAQYPwzbnWm16SchkfM/ZEZgdz36X0Mi+qE9ie1aN+Po7/OSZMDSh+FpyOZGo9EM9A3GRKuQW2mjPCSZ7TayoStJbWcSdDOSZ9HoQnNB4HYVmwhyXKkgShy1XUxSoqgmVXKU/hh7Ssl2IyFo56HbDr/9Ep8FsaPRqK9Po+Zt8LSKZiFbrZEaiPrVmuw65SGph0pqI4mLlBxnjA7nxKjSVbwa98Nwr9JVkVlBG0mV5Azi2cnt/xmIncPzHC6luIVui5xnn0jeWKB/3/3cyUCcTuBxna0Po/Am6FcgS416fYMk95Jk61wEgSpMEmHetsztc8g26p3jwSaphh2wPopCEvwjfV0CHdS4DsT4VunzKibKNUqghH1XT3AiGFuqa5JYxmsI/oBtA92bTGLrXLRVkqxW5Ww3gtc7dKvIvwftAs+qxE1gkyRrWAzT77bMQW4DVfUK4t9l+qjUTfD7Aj4XbLu2rQeDR3B4PofHWjV9U49Bfx7lfwf7PKvQX2ZAvmGx2ap0dR1IglkwaYSkS6jOOAd1zEkdZC77OaAfQn2RLHP7pdoUGN8LeZlDsNsGOUI9YyHfa+ivVdJfAlXY7uEYyVX6rL1Txmo4V19Hf0H6DtLMSBRchOF3CPIiBc+nwPjttjH0W6A/jfYrtPuYDBN6Z3SjvsZ9kNKm7wvMAXkLcpGLjKvEZaCijwGM79c9GmSNZCvyJuZ2XMZcb7OGa/B9XOlPheOcM+QNDnC+eF6ALMk8vocssggsfwtWXgQdgtwLhxm0u8h6ztzjN4W3ezeSPWo+NL3ICcA8yDHW9ccxSLB/aOcB1T/MSvPFkh/Y8jGpJyOfIawwVuAY5jOccWpDziJ6R6cg/n9yucBib0HZ328WHOrvpMxl0jlXcRSLkmOVo+oC8ejdMRdKf5GfAjn3oD0Cedq1KS1ZX2F8TrHeN1gu5GJ4SfG7Bod6xfPvl3YMen5uMdj5cnLnqP53yF2L1elrDX05lYiB5q/q6APN0R3FD2aimG2XcnZRwKRfDDD0ZaDEWRUL1W4lxWd68d9/AXoaeACkElIDAAAAAElFTkSuQmCC>

[image3]: <data:image/png;base64,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>

[image4]: <data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEEAAAAZCAYAAABuKkPfAAAEMElEQVR4XtVWPWgUQRS+IxEUAwbNedzfzO2l8gcETxB/yogKamMSBRsrsbAKaCA2goWI2gSLKIqkCoKgnZYWosHCNBEri4SEFCmCwYAkmPi92ZnbmdnZnzuTkHzwbnfe+96bN9/Ozl4mI5FVN1FIJKRFE4WaoAq4+C5fFAQ3+LECYUS4Q3CVDCMghKlhj9u3GYhcTdbpNeAkOJ3bEC2to6WkeKQomYJiolgsVkxP3Hb1UalUDpNVq9XOJD5nrIcxPs05/wn+a9jDUqm0z+YpUIw4jLFpzS5Y42nO+EvUfK4MOceR3qbXcvVjwPO8PJIfMc6XU9AF8vn8buQMiyY4/w1bw+RfbV6AbKZcLpfAuQnuLGxV5ly1mQqIX5O1p8C7DgF7C4UCtGS9GI9SPudsicbKZA78/DM4VVUrvKrA00ZPMJfLdSDpIyWbYTcwWQ+4NPkD5cMCd2H8AU/lLWru1Pk6crmuDvDewfpRY4bq2BwC/CdgL2B3qTfqUY8j/6Jc7JTvCbrGzrzkx5hbZGOBcmCKoDMsOeQQCx2QDQjRFDAegs2hwZru16FEQKPHwH3KrBoCWVHrMRZwDtxBlwg8JEIA5JyGf4XiqD9APtG6a/EKSgRnQw7InbAMW9CLYbtSw4ue5x3R6AZoLrkT6rLOKvh5nUNjxF7VarU9jLtFiNsJQjgmYvMQ61AjEAf7dRBIeCcox9722H7DqPEdB2xXVLouAi0S/HF7y9IY/tt0H7UTwiIEgG8BIiyD05dJXImEU4QmgFmyyL1P+fV6fYcd19vQRaAxcjxqmr4wioPYCC7t8j5JBDpkC8qwi3Dhf2BfwDmYToFMWITUiRLIuwxbweswYicHQ//PlS0C0C4PsHuKSWeBuk8hgmsnUD9/YUuoddaOOxF9MEZAUuiCBZxE3jyaupOxvs0umCL4hbj/ufxBZ4HnVfP0Oim+W4RsrAiNLxXERXy8EYhbmr0T4qDX8UXgM0x793B/qlyu7NVoBsRc5k6gGuM0N33a7PPBLUL8TiBU5f8IHPaLDWcaEYyvQ+S2DoCcA2imx/KRmAUxcCTZr4MU8pZoGH5cx/Q8WwQVEiL4XwCXCHjF+Bu5E+bsoAlZ0fMPkwlqpF4/ahxs8J0nv7QnGn9S86/JhujdHtXzA/iTIX4FvAVcn+HJF7N+oB0PYEzmN3YCHbI0J2yC/m0qP9AGEW7IuWdprALI72T+v1iKvacvkJYXhtwB/iIsI6WJg8XRqfsJ9gu+M+STTyfgmnmD5iwBtC0sTRyIQjQ6DOH71t3dvZ/7J/2UyW3U7xNXR0yzSfD6Mw1xHFsyFVrN2xTI5lSPW6ZX0Ujz3SRmJBK2MLZK7+vYh7uU73XHEtFi2rZCS2ts8ZWKRjO10nLT8iLwX+nrLlAsNmqija+bboZYVmxwgxA3Z1zMjWYz/gGkwVKxwnp6PAAAAABJRU5ErkJggg==>

[image5]: <data:image/png;base64,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>

[image6]: <data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAD0AAAAZCAYAAACCXybJAAAEFklEQVR4Xs1YPWgUQRTeJRECKiomOZO7m7kfJQQFxUMkINqoIKhVQFHQUlArLURtxFYEsfMPSWWlYiFYiAQtBFPY+NPEIkEQixgIRjAhxu/tzOzOzs3c3J6L+sFjZt5789775m8vCYJOEZqKfNAqbCtbBIuDRfWXoGfOoYocQijkGIpghvONM+OPAyioQLkFlHDEc6htyODaGQYHB8umTkelUllbKpU2NRqNFaZNgXM+ABmp1+v9gaXmJsW/QrVaLaDQa4zzBdNGANkNsD+CfGWMPUE7AzkLU5fhtwf6t5Dbsn1Pet85cukF0tYm39Ci02ExdtHu9fX1rUKB45Bl0wn2o6SHHI911UoP5+wpdJ+lqhv9B5AvyoeA8TDpkblb13thKTQbPAHInCJtADoit4gd3mXobyp/SY52f0JPh+vSSwvBOKtRpsjmqUfA7eS2ZISH9BRkHqQbekqML8SkGTtEfYoROwRxXNIf0PUmyI9OXDQIxXUz3o2ueq3eH/tIP4VisViitwbtegzpVPnXxkN6PiGdQJFGIT2q7yINn1OpOqgbxo8eLSp8qmPlcnkH+hOQb7g+S2gvFwqFlWg/Icc02gXO+GQSKAgR+wjkFmxnII8hc5TXy7pz0myZ5lKfKdJatr5eQZrskcKsRI5ljlmQ3qJMIHdJzn0W6+Qi4SRspalMnLBfyk4gG9UkRmbCGGEHpMN4pxVp6mMRxhOfZKdj0hJmKUzkeDU0NLRaWVVMfS7666CbULWgPwL5CXmI3T6JRasHxhfFiXZIc+tOE+ne9E5rcJHWQRRljvFkh6J3Ik06TOrUNiDE+Dz5aaIffzdcpGVBU9FOqIdMbpMiLXe95UNGdl2fQATjFtK2nbaQjkBvBvTPId9pDh61jbrdChdpggz2C4n26scSurvKn+4R+nNc/hgRZEL1o2cGizasTVXm+JgL0ixNmvtJU00YX1V2evGxAFfEw+kBisPc6BfUsvkTkz4H0H/E6/qyVqutIR36O6lQJL2hKsd9OgzdUhBzCUMkv4ji9yX0rKAjugh5Xa1WCqTYjhowvk71xKQQAjtYpDpR725SqRMWaPcYuoPIuVmNm3KLlWMUOBJ5L0VfO5Iofht0k2jfoD0HmYX9Pn1OhEcUmIo/DbmDqzAatZz/UEYbuPbJUoIcY6KfqusYlydRkynUcALtO8gHyD3IC+imzTxNcFZkgE4ArTCCjkZ/mAiehpe43/Il3Z8sih8ikhmvGboH8vSQyGM/IP/Iae/1bgf+ctxIE8oQKeWaYZ6GzmZlQdhhigzTrK5WpQafvRXanutyzKrPFWYSc5w7RAJ3GrcljRZ+KVMLv/8K2tVwluw0tDTlDVsqi86i8qLpfTDHOYHC+kILu8+rc8h/NeSIJN5vqTIwYAe7bLYAAAAASUVORK5CYII=>

[image7]: <data:image/png;base64,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>
