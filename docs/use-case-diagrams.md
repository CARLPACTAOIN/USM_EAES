# Eventia Use Case Diagrams

Recommended count: **6 smaller use case diagrams**.

These diagrams are grouped by actor area so each diagram stays readable with only 2-3 actors or actor groups. Mermaid does not provide a native UML use-case diagram type, so these use `flowchart LR` with actor nodes outside the Eventia boundary and rounded use-case nodes inside it.

## 1. Student and Faculty Self-Service

Figure 1 presents the self-service use cases available to students and faculty or staff users in Eventia.

```mermaid
flowchart LR
    student["Student"]:::actor

    subgraph Eventia["Eventia"]
        login([Login with USM Google account])
        studentProfile([Complete student academic path])
        qrRegister([Register physical ID QR])
        viewHistory([View personal attendance history])
        submitEvaluation([Submit event evaluation])
    end

    student --> login
    student --> studentProfile
    student --> qrRegister
    student --> viewHistory
    student --> submitEvaluation

    classDef actor fill:#fff7ed,stroke:#c2410c,stroke-width:1px
```

## 2. OSA and Officer Governance

Figure 2 shows the governance use cases handled by OSA, organization advisers, and verified organization officers.

```mermaid
flowchart LR
    osa["OSA / Super Admin"]:::actor
    adviser["Organization Adviser"]:::actor
    officers["President / Secretary"]:::actor

    subgraph Eventia["Eventia"]
        manageMasterData([Manage colleges, departments, programs, and organizations])
        manageSchoolYear([Create and activate school years])
        openTransition([Open or close transition period])
        submitRoster([Draft and submit officer roster])
        verifyRoster([Verify officer roster])
        requestOfficerChange([Request officer change])
        authorityProjection([Generate scoped authority projection])
        authorityOverride([Record authority override])
    end

    osa --> manageMasterData
    osa --> manageSchoolYear
    osa --> openTransition
    osa --> authorityOverride

    officers --> submitRoster
    officers --> requestOfficerChange

    adviser --> submitRoster
    adviser --> verifyRoster
    adviser --> requestOfficerChange

    verifyRoster --> authorityProjection
    authorityOverride --> authorityProjection

    classDef actor fill:#fff7ed,stroke:#c2410c,stroke-width:1px
```

## 3. Event Proposal and PPA Packet

Figure 3 presents the event proposal and Canonical PPA Packet use cases for organizer and participant-side administrators.

```mermaid
flowchart LR
    orgAdmin["Organization Admin"]
    unitAdmin["Academic Unit / Office Admin"]
    participantAdmin["Participant Organization Admin"]

    subgraph Eventia["Eventia"]
        createProposal([Create event proposal])
        completePacket([Complete Canonical PPA Packet])
        attachRequirements([Attach supporting requirements])
        submitProposal([Submit proposal])
        inviteParticipants([Invite participant scopes])
        participantPacket([Complete participant PPA packet])
        resubmitRevision([Resubmit after revision])
    end

    orgAdmin --> createProposal
    orgAdmin --> completePacket
    orgAdmin --> attachRequirements
    orgAdmin --> submitProposal
    orgAdmin --> inviteParticipants
    orgAdmin --> resubmitRevision

    unitAdmin --> createProposal
    unitAdmin --> completePacket
    unitAdmin --> attachRequirements
    unitAdmin --> submitProposal
    unitAdmin --> inviteParticipants

    participantAdmin --> participantPacket
    participantAdmin --> attachRequirements
    participantAdmin --> resubmitRevision

    classDef actor fill:#fff7ed,stroke:#c2410c,stroke-width:1px;
    class orgAdmin,unitAdmin,participantAdmin actor;
```

## 4. Approval Workflow and Digital Permit

Figure 4 shows the approval workflow, task decision, audit trail, and digital permit use cases for institutional approvers.

```mermaid
flowchart LR
    approver["Institutional Approver"]
    osaReviewer["OSA Reviewer"]
    venueSecurity["Chief Security / Venue Custodian"]

    subgraph Eventia["Eventia"]
        viewTask([View assigned workflow task])
        reviewPacket([Review proposal or permit packet])
        approveTask([Approve task])
        requestRevision([Request revision])
        rejectTask([Reject task])
        markNotRequired([Mark conditional task not required])
        reassignTask([Reassign workflow task])
        writeTrail([Write approval trail])
        generatePermit([Generate digital permit])
    end

    approver --> viewTask
    approver --> reviewPacket
    approver --> approveTask
    approver --> requestRevision
    approver --> rejectTask
    approver --> markNotRequired

    osaReviewer --> viewTask
    osaReviewer --> reviewPacket
    osaReviewer --> approveTask
    osaReviewer --> requestRevision
    osaReviewer --> rejectTask
    osaReviewer --> reassignTask
    osaReviewer --> generatePermit

    venueSecurity --> viewTask
    venueSecurity --> reviewPacket
    venueSecurity --> approveTask
    venueSecurity --> requestRevision

    approveTask --> writeTrail
    requestRevision --> writeTrail
    rejectTask --> writeTrail
    markNotRequired --> writeTrail
    writeTrail --> generatePermit

    classDef actor fill:#fff7ed,stroke:#c2410c,stroke-width:1px;
    class approver,osaReviewer,venueSecurity actor;
```

## 5. Scanner Assignment and Attendance Capture

Figure 5 presents the scanner assignment and attendance capture use cases for scanner managers, scanner operators, and HR administrators.

```mermaid
flowchart LR
    scannerManager["Scanner Manager"]
    scanner["Scanner"]
    hrAdmin["HR Admin"]

    subgraph Eventia["Eventia"]
        assignScanner([Assign scanner to event scope])
        activateSession([Activate scanner session])
        hydrateRoster([Hydrate scoped roster])
        scanQr([Scan QR for time-in or time-out])
        manualEntry([Record manual ID entry])
        storeOffline([Store scans offline])
        syncScans([Sync captured scans])
        resolveAttendance([Resolve official attendance])
        manageHrRoster([Manage HR attendance roster])
    end

    scannerManager --> assignScanner
    scannerManager --> resolveAttendance

    scanner --> activateSession
    scanner --> hydrateRoster
    scanner --> scanQr
    scanner --> manualEntry
    scanner --> storeOffline
    scanner --> syncScans

    hrAdmin --> manageHrRoster
    hrAdmin --> assignScanner
    hrAdmin --> resolveAttendance

    syncScans --> resolveAttendance

    classDef actor fill:#fff7ed,stroke:#c2410c,stroke-width:1px;
    class scannerManager,scanner,hrAdmin actor;
```

## 6. Evaluation, Analytics, and Reports

Figure 6 shows the evaluation, attendance validation, analytics, and report export use cases available after event participation.

```mermaid
flowchart LR
    attendee["Student / Faculty Attendee"]
    orgAdmin["Organization Admin"]
    adminViewer["OSA / HR Admin"]

    subgraph Eventia["Eventia"]
        submitEvaluation([Submit activity evaluation])
        validateAttendance([Validate attendance after evaluation])
        viewOwnAnalytics([View scoped event analytics])
        filterReports([Filter reports by school year and hierarchy])
        exportReports([Export PDF or Excel reports])
        gawadMetrics([View Gawad Parangal metrics])
        nlpQuery([Ask analytics question])
    end

    attendee --> submitEvaluation
    submitEvaluation --> validateAttendance

    orgAdmin --> viewOwnAnalytics
    orgAdmin --> filterReports
    orgAdmin --> exportReports

    adminViewer --> filterReports
    adminViewer --> exportReports
    adminViewer --> gawadMetrics
    adminViewer --> nlpQuery

    classDef actor fill:#fff7ed,stroke:#c2410c,stroke-width:1px;
    class attendee,orgAdmin,adminViewer actor;
```
