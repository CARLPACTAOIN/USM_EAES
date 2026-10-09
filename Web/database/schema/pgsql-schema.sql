--
-- PostgreSQL database dump
--

\restrict OTqnRPBRhP0CoeNpLsCrSUIatEtiKKokVRl6wEcNbkIAxbijvQoj3YwS41sxy1r

-- Dumped from database version 16.14
-- Dumped by pg_dump version 16.14

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: prevent_approval_trails_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.prevent_approval_trails_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        RAISE EXCEPTION 'approval_trails are immutable';
    END;
    $$;


--
-- Name: prevent_event_permit_versions_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.prevent_event_permit_versions_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'event_permit_versions are immutable';
        END IF;

        IF OLD.superseded_at IS NULL
            AND NEW.superseded_at IS NOT NULL
            AND (to_jsonb(NEW) - 'superseded_at' - 'updated_at') = (to_jsonb(OLD) - 'superseded_at' - 'updated_at')
        THEN
            RETURN NEW;
        END IF;

        RAISE EXCEPTION 'event_permit_versions are immutable';
    END;
    $$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admin_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_assignments (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    role_name character varying(255) NOT NULL,
    organization_id uuid,
    college_id uuid,
    academic_year character varying(255) NOT NULL,
    term_start date,
    term_end date,
    position_title character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    is_primary_admin boolean DEFAULT true NOT NULL,
    approved_by uuid,
    approved_at timestamp(0) without time zone,
    revoked_by uuid,
    revoked_at timestamp(0) without time zone,
    revocation_reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    department_id uuid,
    school_year_id uuid,
    source_type character varying(255),
    source_id uuid
);


--
-- Name: approval_trails; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.approval_trails (
    id uuid NOT NULL,
    proposal_id uuid NOT NULL,
    workflow_run_id uuid NOT NULL,
    workflow_task_id uuid NOT NULL,
    actor_id uuid NOT NULL,
    decision character varying(255) NOT NULL,
    remarks text,
    reference_id character varying(255) NOT NULL,
    previous_status character varying(255) NOT NULL,
    resulting_status character varying(255) NOT NULL,
    assigned_role character varying(255),
    assigned_scope character varying(255),
    decided_at timestamp(0) without time zone NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: attendance_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attendance_records (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    event_day_id uuid,
    student_id uuid NOT NULL,
    time_in timestamp(0) without time zone,
    time_out timestamp(0) without time zone,
    society_status character varying(255) NOT NULL,
    competition_status character varying(255) NOT NULL,
    left_early boolean DEFAULT false NOT NULL,
    valid boolean DEFAULT false NOT NULL,
    force_validated boolean DEFAULT false NOT NULL,
    validated_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event_participation_scope_id uuid,
    event_day_session_id uuid,
    completion_status character varying(255) DEFAULT 'partial'::character varying NOT NULL
);


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    id uuid NOT NULL,
    target_id uuid NOT NULL,
    admin_id uuid NOT NULL,
    action character varying(255) NOT NULL,
    details jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: canonical_ppa_attachments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.canonical_ppa_attachments (
    id uuid NOT NULL,
    canonical_ppa_packet_id uuid NOT NULL,
    attachment_type character varying(255) NOT NULL,
    path character varying(255) NOT NULL,
    original_name character varying(255) NOT NULL,
    mime_type character varying(255),
    size_bytes bigint,
    uploaded_by_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: canonical_ppa_packets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.canonical_ppa_packets (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    activity_role character varying(255) DEFAULT 'organizer'::character varying NOT NULL,
    proposal_category character varying(255),
    title character varying(255),
    proponent_name character varying(255),
    proponent_position character varying(255),
    proponent_organization character varying(255),
    proponent_email character varying(255),
    proponent_contact_number character varying(255),
    implementing_office character varying(255),
    collaborating_office character varying(255),
    source_of_fund character varying(255),
    budget_cost numeric(12,2),
    start_date date,
    end_date date,
    location_type character varying(255),
    venue_id uuid,
    location_details character varying(255),
    venue_confirmation_office character varying(255),
    venue_confirmation_date date,
    venue_confirmation_reference_number character varying(255),
    rationale text,
    general_objective text,
    specific_objectives jsonb,
    methodology text,
    major_activities jsonb,
    program_activities jsonb,
    expected_output text,
    beneficiaries text,
    budget_breakdown jsonb,
    schedule_summary jsonb,
    venue_summary jsonb,
    prepared_by_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    venue_confirmation_document_path character varying(255),
    venue_confirmation_document_original_name character varying(255),
    event_participation_scope_id uuid,
    delegate_count integer,
    supporting_document_path character varying(255),
    supporting_document_original_name character varying(255)
);


--
-- Name: colleges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.colleges (
    id uuid NOT NULL,
    university_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: departments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.departments (
    id uuid NOT NULL,
    college_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: employee_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.employee_profiles (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    employee_id character varying(255),
    office_department character varying(255),
    college_id uuid,
    position_title character varying(255),
    verification_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    verified_by uuid,
    verified_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: employee_roster_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.employee_roster_entries (
    id uuid NOT NULL,
    user_id uuid,
    employee_id character varying(255) NOT NULL,
    qr_code_value character varying(255),
    email character varying(255),
    name character varying(255) NOT NULL,
    user_type character varying(255) DEFAULT 'faculty'::character varying NOT NULL,
    office_department character varying(255),
    college_id uuid,
    position_title character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    verified_by uuid,
    verified_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: evaluation_form_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evaluation_form_versions (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    title character varying(255) DEFAULT 'Event Evaluation'::character varying NOT NULL,
    questions jsonb NOT NULL,
    created_by uuid,
    locked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: evaluations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evaluations (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    student_id uuid NOT NULL,
    section_scores jsonb NOT NULL,
    open_comment text,
    sentiment character varying(255),
    sentiment_score double precision,
    submitted_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    evaluation_form_version_id uuid
);


--
-- Name: event_day_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_day_sessions (
    id uuid NOT NULL,
    event_day_id uuid NOT NULL,
    session_number integer NOT NULL,
    name character varying(255) NOT NULL,
    start_time time(0) without time zone NOT NULL,
    end_time time(0) without time zone NOT NULL,
    required_for_attendance boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: event_days; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_days (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    day_number integer NOT NULL,
    date date NOT NULL,
    start_time time(0) without time zone NOT NULL,
    end_time time(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: event_participation_scopes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_participation_scopes (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    parent_scope_id uuid,
    linked_event_id uuid,
    participation_role character varying(255) DEFAULT 'participant'::character varying NOT NULL,
    participant_type character varying(255) DEFAULT 'organization'::character varying NOT NULL,
    organization_id uuid,
    college_id uuid,
    program_id uuid,
    participant_name_snapshot character varying(255),
    ppa_required boolean DEFAULT true NOT NULL,
    ppa_status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    ppa_softcopy_path character varying(255),
    ppa_softcopy_original_name character varying(255),
    hardcopy_submitted boolean DEFAULT false NOT NULL,
    org_head_signed boolean DEFAULT false NOT NULL,
    adviser_signed boolean DEFAULT false NOT NULL,
    submitted_by uuid,
    submitted_at timestamp(0) without time zone,
    reviewed_by uuid,
    reviewed_at timestamp(0) without time zone,
    review_remarks text,
    approved_at timestamp(0) without time zone,
    capture_mode character varying(255) DEFAULT 'self_scanning'::character varying NOT NULL,
    delegated_to_organization_id uuid,
    analytics_rollup_enabled boolean DEFAULT true NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    invitation_acknowledged_at timestamp(0) without time zone,
    invitation_acknowledged_by uuid,
    invitation_last_reminded_at timestamp(0) without time zone,
    late_cutoff_min integer,
    school_year_id uuid,
    department_id uuid
);


--
-- Name: event_permit_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_permit_versions (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    version_number integer NOT NULL,
    venue_id uuid,
    venue_name_snapshot character varying(255) NOT NULL,
    venue_location_snapshot character varying(255),
    location_type character varying(255) NOT NULL,
    location_details character varying(255) NOT NULL,
    confirmation_office character varying(255),
    confirmation_date date,
    confirmation_reference_number character varying(255),
    confirmation_document_path character varying(255),
    confirmation_document_original_name character varying(255),
    ppa_document_path character varying(255),
    ppa_document_original_name character varying(255),
    amendment_reason text,
    approved_by uuid NOT NULL,
    approved_at timestamp(0) without time zone NOT NULL,
    superseded_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    workflow_run_id uuid,
    verification_reference character varying(255),
    canonical_packet_snapshot json,
    proposal_snapshot json,
    permit_snapshot json,
    evidence_snapshot json,
    approval_metadata_snapshot json
);


--
-- Name: event_scanner_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.event_scanner_assignments (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    scanner_user_id uuid NOT NULL,
    assigned_by_user_id uuid NOT NULL,
    label character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    revoked_at timestamp(0) without time zone,
    revoked_by_user_id uuid,
    revocation_reason text,
    expires_at timestamp(0) without time zone,
    max_devices integer DEFAULT 1 NOT NULL,
    notes text,
    last_activated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event_participation_scope_id uuid,
    audience_scope_type character varying(255),
    school_year_id uuid
);


--
-- Name: events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.events (
    id uuid NOT NULL,
    organization_id uuid NOT NULL,
    parent_event_id uuid,
    title character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    location_type character varying(255) DEFAULT 'on-campus'::character varying NOT NULL,
    location_details character varying(255),
    target_demographics jsonb,
    budget_allocations jsonb,
    society_late_threshold_min integer DEFAULT 15 NOT NULL,
    general_competition_threshold_min integer DEFAULT 30 NOT NULL,
    left_early_buffer_min integer DEFAULT 15 NOT NULL,
    evaluation_open boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    proposal_category character varying(255),
    proposal_document_path character varying(255),
    proposal_document_original_name character varying(255),
    resolution_number character varying(255),
    hardcopy_submitted boolean DEFAULT false NOT NULL,
    hardcopy_submitted_at timestamp(0) without time zone,
    head_organization_signed boolean DEFAULT false NOT NULL,
    adviser_signed boolean DEFAULT false NOT NULL,
    lifecycle_phase character varying(255) DEFAULT 'scheduled'::character varying NOT NULL,
    event_domain character varying(255) DEFAULT 'student_affairs'::character varying NOT NULL,
    event_late_cutoff_min integer,
    evaluation_form_version_id uuid,
    venue_id uuid,
    venue_confirmation_office character varying(255),
    venue_confirmation_date date,
    venue_confirmation_reference_number character varying(255),
    venue_confirmation_document_path character varying(255),
    venue_confirmation_document_original_name character varying(255),
    permit_version integer DEFAULT 0 NOT NULL,
    pending_venue_amendment_reason text,
    school_year_id uuid,
    college_name_snapshot character varying(255),
    department_name_snapshot character varying(255),
    program_name_snapshot character varying(255),
    organization_name_snapshot character varying(255),
    proponent_name_snapshot character varying(255),
    proponent_user_id uuid,
    org_head_user_id uuid,
    dept_chair_user_id uuid,
    college_dean_user_id uuid,
    governance_snapshots jsonb,
    department_id uuid
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id uuid NOT NULL
);


--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id uuid NOT NULL
);


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id uuid NOT NULL,
    type character varying(255) NOT NULL,
    notifiable_type character varying(255) NOT NULL,
    notifiable_id uuid NOT NULL,
    data json NOT NULL,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: officer_change_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.officer_change_requests (
    id uuid NOT NULL,
    organization_id uuid NOT NULL,
    officer_roster_id uuid NOT NULL,
    old_officer_id uuid,
    new_user_id uuid,
    created_officer_id uuid,
    request_type character varying(255) NOT NULL,
    position_key character varying(255) NOT NULL,
    position_title character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    reason text NOT NULL,
    evidence_file_path character varying(255),
    evidence_file_name character varying(255),
    evidence_metadata json,
    requested_by uuid,
    requested_at timestamp(0) without time zone,
    decided_by uuid,
    decided_at timestamp(0) without time zone,
    decision_remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_memberships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_memberships (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    college_id uuid,
    program_id uuid,
    membership_type character varying(255) DEFAULT 'primary_academic'::character varying NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    source character varying(255) DEFAULT 'profile'::character varying NOT NULL,
    verified_by uuid,
    verified_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    year_level character varying(20),
    department_id uuid,
    school_year_id uuid
);


--
-- Name: organization_officer_rosters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_officer_rosters (
    id uuid NOT NULL,
    organization_id uuid NOT NULL,
    school_year_id uuid NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    evidence_file_path character varying(255),
    evidence_file_name character varying(255),
    attestation_text text,
    evidence_metadata json,
    submitted_by uuid,
    submitted_at timestamp(0) without time zone,
    verified_by uuid,
    verified_at timestamp(0) without time zone,
    verification_remarks text,
    rejection_remarks text,
    created_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_officers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_officers (
    id uuid NOT NULL,
    officer_roster_id uuid NOT NULL,
    user_id uuid NOT NULL,
    position_key character varying(255) NOT NULL,
    position_title character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    effective_start date,
    effective_end date,
    replacement_officer_id uuid,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_programs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_programs (
    organization_id uuid NOT NULL,
    program_id uuid NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizations (
    id uuid NOT NULL,
    college_id uuid,
    name character varying(255) NOT NULL,
    acronym character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    logo_path character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    department_id uuid
);


--
-- Name: osa_authority_overrides; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.osa_authority_overrides (
    id uuid NOT NULL,
    action character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    target_user_id uuid,
    role_name character varying(255),
    organization_id uuid,
    college_id uuid,
    department_id uuid,
    school_year_id uuid,
    academic_year character varying(255),
    term_start date,
    term_end date,
    position_title character varying(255),
    reason text NOT NULL,
    starts_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    source_assignment_id uuid,
    projected_assignment_id uuid,
    actor_id uuid NOT NULL,
    acted_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: pending_student_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pending_student_links (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    raw_scan_id uuid,
    qr_code_value character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    resolved_student_id uuid,
    resolved_by uuid,
    flagged_by uuid,
    notes text,
    resolved_at timestamp(0) without time zone,
    flagged_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event_participation_scope_id uuid
);


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: permit_copy_owners; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permit_copy_owners (
    id uuid NOT NULL,
    event_permit_version_id uuid NOT NULL,
    event_id uuid NOT NULL,
    owner_type character varying(255) NOT NULL,
    owner_id uuid,
    owner_name character varying(255) NOT NULL,
    owner_role character varying(255) NOT NULL,
    copy_label character varying(255) NOT NULL,
    assigned_at timestamp(0) without time zone NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id uuid NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: programs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.programs (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    department_id uuid NOT NULL
);


--
-- Name: proposal_process_step_dependencies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.proposal_process_step_dependencies (
    id uuid NOT NULL,
    step_id uuid NOT NULL,
    prerequisite_step_id uuid NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: proposal_process_steps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.proposal_process_steps (
    id uuid NOT NULL,
    template_version_id uuid NOT NULL,
    step_key character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    step_type character varying(255) NOT NULL,
    phase character varying(255),
    display_order integer NOT NULL,
    is_required boolean DEFAULT true NOT NULL,
    is_gate boolean DEFAULT false NOT NULL,
    condition_config json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: proposal_process_template_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.proposal_process_template_versions (
    id uuid NOT NULL,
    template_id uuid NOT NULL,
    version_number integer NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    summary text,
    published_at timestamp(0) without time zone,
    published_by uuid,
    retired_at timestamp(0) without time zone,
    retired_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: proposal_process_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.proposal_process_templates (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_by uuid,
    archived_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: proposal_template_applicability_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.proposal_template_applicability_rules (
    id uuid NOT NULL,
    template_version_id uuid NOT NULL,
    priority integer DEFAULT 0 NOT NULL,
    organizer_role character varying(255),
    organization_type character varying(255),
    college_id uuid,
    event_domain character varying(255),
    activity_category character varying(255),
    location_type character varying(255),
    is_default boolean DEFAULT false NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    effective_start timestamp(0) without time zone,
    effective_end timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: proposal_workflow_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.proposal_workflow_runs (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    template_version_id uuid NOT NULL,
    status character varying(255) DEFAULT 'in_progress'::character varying NOT NULL,
    started_by uuid,
    started_at timestamp(0) without time zone,
    submitted_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event_participation_scope_id uuid
);


--
-- Name: proposal_workflow_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.proposal_workflow_tasks (
    id uuid NOT NULL,
    workflow_run_id uuid NOT NULL,
    process_step_id uuid NOT NULL,
    status character varying(255) DEFAULT 'blocked'::character varying NOT NULL,
    display_order integer NOT NULL,
    step_key character varying(255) NOT NULL,
    step_type character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    assigned_user_id uuid,
    assigned_role character varying(255),
    assigned_scope character varying(255),
    available_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    decision character varying(255),
    remarks text,
    decided_by uuid,
    decided_at timestamp(0) without time zone,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: queue_outbox_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.queue_outbox_entries (
    id uuid NOT NULL,
    type character varying(255) NOT NULL,
    dedup_key character varying(255) NOT NULL,
    payload json NOT NULL,
    dispatch_attempts integer DEFAULT 0 NOT NULL,
    dispatched_at timestamp(0) without time zone,
    available_at timestamp(0) without time zone,
    last_error_class character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: raw_scans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.raw_scans (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    event_day_id uuid,
    student_id uuid,
    qr_code_value character varying(255),
    scan_type character varying(255) NOT NULL,
    scanned_at timestamp(0) without time zone NOT NULL,
    device_id character varying(255) NOT NULL,
    manual_entry boolean DEFAULT false NOT NULL,
    dedup_key character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    scanner_session_id uuid,
    scanner_user_id uuid,
    server_received_at timestamp(0) without time zone,
    processed_at timestamp(0) without time zone,
    rejection_reason character varying(255),
    event_participation_scope_id uuid,
    captured_by_organization_id uuid,
    captured_by_admin_scope character varying(255),
    event_day_session_id uuid
);


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: scanner_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.scanner_sessions (
    id uuid NOT NULL,
    event_id uuid NOT NULL,
    scanner_user_id uuid,
    token_id character varying(255),
    issued_by uuid NOT NULL,
    label character varying(255),
    device_id character varying(255),
    device_fingerprint_hash character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    issued_at timestamp(0) without time zone NOT NULL,
    activated_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    revoked_at timestamp(0) without time zone,
    revoked_by uuid,
    revocation_reason text,
    last_seen_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    assignment_id uuid,
    event_participation_scope_id uuid
);


--
-- Name: school_years; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_years (
    id uuid NOT NULL,
    label character varying(255) NOT NULL,
    starts_on date NOT NULL,
    ends_on date NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    activated_at timestamp(0) without time zone,
    activated_by uuid,
    closed_at timestamp(0) without time zone,
    closed_by uuid,
    transition_started_at timestamp(0) without time zone,
    transition_ends_at date,
    transition_opened_by uuid,
    transition_closed_at timestamp(0) without time zone,
    transition_closed_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id uuid,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: universities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.universities (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    domain character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid NOT NULL,
    google_sub character varying(255),
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    name character varying(255) NOT NULL,
    password character varying(255),
    organization_id uuid,
    student_id_number character varying(255),
    qr_code_value character varying(255),
    program_code character varying(6),
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    college_id uuid,
    program_id uuid,
    user_type character varying(255) DEFAULT 'student'::character varying NOT NULL,
    year_level character varying(20),
    department_id uuid
);


--
-- Name: venues; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.venues (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    campus_location character varying(255) NOT NULL,
    college_id uuid,
    managing_office character varying(255) NOT NULL,
    inquiry_instructions text,
    is_active boolean DEFAULT true NOT NULL,
    retired_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workflow_task_assignment_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workflow_task_assignment_history (
    id uuid NOT NULL,
    workflow_task_id uuid NOT NULL,
    former_assignee_id uuid,
    new_assignee_id uuid NOT NULL,
    actor_id uuid,
    reason text NOT NULL,
    reassigned_at timestamp(0) without time zone NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: admin_assignments admin_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_assignments
    ADD CONSTRAINT admin_assignments_pkey PRIMARY KEY (id);


--
-- Name: approval_trails approval_trails_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_trails
    ADD CONSTRAINT approval_trails_pkey PRIMARY KEY (id);


--
-- Name: approval_trails approval_trails_reference_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_trails
    ADD CONSTRAINT approval_trails_reference_id_unique UNIQUE (reference_id);


--
-- Name: attendance_records attendance_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: canonical_ppa_attachments canonical_ppa_attachments_canonical_ppa_packet_id_attachment_ty; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_attachments
    ADD CONSTRAINT canonical_ppa_attachments_canonical_ppa_packet_id_attachment_ty UNIQUE (canonical_ppa_packet_id, attachment_type);


--
-- Name: canonical_ppa_attachments canonical_ppa_attachments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_attachments
    ADD CONSTRAINT canonical_ppa_attachments_pkey PRIMARY KEY (id);


--
-- Name: canonical_ppa_packets canonical_ppa_packets_event_participation_scope_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_packets
    ADD CONSTRAINT canonical_ppa_packets_event_participation_scope_id_unique UNIQUE (event_participation_scope_id);


--
-- Name: canonical_ppa_packets canonical_ppa_packets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_packets
    ADD CONSTRAINT canonical_ppa_packets_pkey PRIMARY KEY (id);


--
-- Name: colleges colleges_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.colleges
    ADD CONSTRAINT colleges_code_unique UNIQUE (code);


--
-- Name: colleges colleges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.colleges
    ADD CONSTRAINT colleges_pkey PRIMARY KEY (id);


--
-- Name: departments departments_college_id_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_college_id_code_unique UNIQUE (college_id, code);


--
-- Name: departments departments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_pkey PRIMARY KEY (id);


--
-- Name: employee_profiles employee_profiles_employee_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_employee_id_unique UNIQUE (employee_id);


--
-- Name: employee_profiles employee_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_pkey PRIMARY KEY (id);


--
-- Name: employee_profiles employee_profiles_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_user_id_unique UNIQUE (user_id);


--
-- Name: employee_roster_entries employee_roster_entries_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_roster_entries
    ADD CONSTRAINT employee_roster_entries_email_unique UNIQUE (email);


--
-- Name: employee_roster_entries employee_roster_entries_employee_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_roster_entries
    ADD CONSTRAINT employee_roster_entries_employee_id_unique UNIQUE (employee_id);


--
-- Name: employee_roster_entries employee_roster_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_roster_entries
    ADD CONSTRAINT employee_roster_entries_pkey PRIMARY KEY (id);


--
-- Name: employee_roster_entries employee_roster_entries_qr_code_value_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_roster_entries
    ADD CONSTRAINT employee_roster_entries_qr_code_value_unique UNIQUE (qr_code_value);


--
-- Name: evaluation_form_versions evaluation_form_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluation_form_versions
    ADD CONSTRAINT evaluation_form_versions_pkey PRIMARY KEY (id);


--
-- Name: evaluations evaluations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluations
    ADD CONSTRAINT evaluations_pkey PRIMARY KEY (id);


--
-- Name: event_day_sessions event_day_sessions_event_day_id_session_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_day_sessions
    ADD CONSTRAINT event_day_sessions_event_day_id_session_number_unique UNIQUE (event_day_id, session_number);


--
-- Name: event_day_sessions event_day_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_day_sessions
    ADD CONSTRAINT event_day_sessions_pkey PRIMARY KEY (id);


--
-- Name: event_days event_days_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_days
    ADD CONSTRAINT event_days_pkey PRIMARY KEY (id);


--
-- Name: event_participation_scopes event_participation_scopes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_pkey PRIMARY KEY (id);


--
-- Name: event_permit_versions event_permit_versions_event_id_version_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_permit_versions
    ADD CONSTRAINT event_permit_versions_event_id_version_number_unique UNIQUE (event_id, version_number);


--
-- Name: event_permit_versions event_permit_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_permit_versions
    ADD CONSTRAINT event_permit_versions_pkey PRIMARY KEY (id);


--
-- Name: event_permit_versions event_permit_versions_verification_reference_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_permit_versions
    ADD CONSTRAINT event_permit_versions_verification_reference_unique UNIQUE (verification_reference);


--
-- Name: event_scanner_assignments event_scanner_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_scanner_assignments
    ADD CONSTRAINT event_scanner_assignments_pkey PRIMARY KEY (id);


--
-- Name: events events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: officer_change_requests officer_change_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.officer_change_requests
    ADD CONSTRAINT officer_change_requests_pkey PRIMARY KEY (id);


--
-- Name: organization_memberships organization_memberships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_memberships
    ADD CONSTRAINT organization_memberships_pkey PRIMARY KEY (id);


--
-- Name: organization_officer_rosters organization_officer_rosters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officer_rosters
    ADD CONSTRAINT organization_officer_rosters_pkey PRIMARY KEY (id);


--
-- Name: organization_officers organization_officers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officers
    ADD CONSTRAINT organization_officers_pkey PRIMARY KEY (id);


--
-- Name: organization_programs organization_programs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_programs
    ADD CONSTRAINT organization_programs_pkey PRIMARY KEY (organization_id, program_id);


--
-- Name: organizations organizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_pkey PRIMARY KEY (id);


--
-- Name: osa_authority_overrides osa_authority_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: pending_student_links pending_student_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_student_links
    ADD CONSTRAINT pending_student_links_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: permit_copy_owners permit_copy_owner_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permit_copy_owners
    ADD CONSTRAINT permit_copy_owner_unique UNIQUE (event_permit_version_id, owner_type, owner_name);


--
-- Name: permit_copy_owners permit_copy_owners_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permit_copy_owners
    ADD CONSTRAINT permit_copy_owners_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: programs programs_department_id_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT programs_department_id_code_unique UNIQUE (department_id, code);


--
-- Name: programs programs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT programs_pkey PRIMARY KEY (id);


--
-- Name: proposal_process_step_dependencies proposal_process_step_dependencies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_step_dependencies
    ADD CONSTRAINT proposal_process_step_dependencies_pkey PRIMARY KEY (id);


--
-- Name: proposal_process_steps proposal_process_steps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_steps
    ADD CONSTRAINT proposal_process_steps_pkey PRIMARY KEY (id);


--
-- Name: proposal_process_steps proposal_process_steps_template_version_id_step_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_steps
    ADD CONSTRAINT proposal_process_steps_template_version_id_step_key_unique UNIQUE (template_version_id, step_key);


--
-- Name: proposal_process_template_versions proposal_process_template_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_template_versions
    ADD CONSTRAINT proposal_process_template_versions_pkey PRIMARY KEY (id);


--
-- Name: proposal_process_template_versions proposal_process_template_versions_template_id_version_number_u; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_template_versions
    ADD CONSTRAINT proposal_process_template_versions_template_id_version_number_u UNIQUE (template_id, version_number);


--
-- Name: proposal_process_templates proposal_process_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_templates
    ADD CONSTRAINT proposal_process_templates_pkey PRIMARY KEY (id);


--
-- Name: proposal_template_applicability_rules proposal_template_applicability_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_template_applicability_rules
    ADD CONSTRAINT proposal_template_applicability_rules_pkey PRIMARY KEY (id);


--
-- Name: proposal_workflow_runs proposal_workflow_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_runs
    ADD CONSTRAINT proposal_workflow_runs_pkey PRIMARY KEY (id);


--
-- Name: proposal_workflow_runs proposal_workflow_runs_scope_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_runs
    ADD CONSTRAINT proposal_workflow_runs_scope_unique UNIQUE (event_participation_scope_id);


--
-- Name: proposal_workflow_tasks proposal_workflow_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_tasks
    ADD CONSTRAINT proposal_workflow_tasks_pkey PRIMARY KEY (id);


--
-- Name: proposal_workflow_tasks proposal_workflow_tasks_workflow_run_id_process_step_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_tasks
    ADD CONSTRAINT proposal_workflow_tasks_workflow_run_id_process_step_id_unique UNIQUE (workflow_run_id, process_step_id);


--
-- Name: queue_outbox_entries queue_outbox_entries_dedup_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_outbox_entries
    ADD CONSTRAINT queue_outbox_entries_dedup_key_unique UNIQUE (dedup_key);


--
-- Name: queue_outbox_entries queue_outbox_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_outbox_entries
    ADD CONSTRAINT queue_outbox_entries_pkey PRIMARY KEY (id);


--
-- Name: raw_scans raw_scans_dedup_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_dedup_key_unique UNIQUE (dedup_key);


--
-- Name: raw_scans raw_scans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_pkey PRIMARY KEY (id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: scanner_sessions scanner_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scanner_sessions
    ADD CONSTRAINT scanner_sessions_pkey PRIMARY KEY (id);


--
-- Name: school_years school_years_label_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_years
    ADD CONSTRAINT school_years_label_unique UNIQUE (label);


--
-- Name: school_years school_years_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_years
    ADD CONSTRAINT school_years_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: proposal_process_step_dependencies step_prereq_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_step_dependencies
    ADD CONSTRAINT step_prereq_unique UNIQUE (step_id, prerequisite_step_id);


--
-- Name: universities universities_domain_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.universities
    ADD CONSTRAINT universities_domain_unique UNIQUE (domain);


--
-- Name: universities universities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.universities
    ADD CONSTRAINT universities_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_google_sub_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_google_sub_unique UNIQUE (google_sub);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_qr_code_value_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_qr_code_value_unique UNIQUE (qr_code_value);


--
-- Name: users users_student_id_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_student_id_number_unique UNIQUE (student_id_number);


--
-- Name: venues venues_name_campus_location_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venues
    ADD CONSTRAINT venues_name_campus_location_unique UNIQUE (name, campus_location);


--
-- Name: venues venues_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venues
    ADD CONSTRAINT venues_pkey PRIMARY KEY (id);


--
-- Name: workflow_task_assignment_history workflow_task_assignment_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_task_assignment_history
    ADD CONSTRAINT workflow_task_assignment_history_pkey PRIMARY KEY (id);


--
-- Name: admin_assignments_active_primary_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX admin_assignments_active_primary_unique ON public.admin_assignments USING btree (role_name, COALESCE((organization_id)::text, '00000000-0000-0000-0000-000000000000'::text), COALESCE((college_id)::text, '00000000-0000-0000-0000-000000000000'::text), academic_year) WHERE (((status)::text = 'active'::text) AND (is_primary_admin = true));


--
-- Name: admin_assignments_active_source_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX admin_assignments_active_source_unique ON public.admin_assignments USING btree (source_type, source_id) WHERE (((status)::text = 'active'::text) AND (source_type IS NOT NULL) AND (source_id IS NOT NULL));


--
-- Name: admin_assignments_org_school_year_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_assignments_org_school_year_status_index ON public.admin_assignments USING btree (organization_id, school_year_id, status);


--
-- Name: admin_assignments_role_name_academic_year_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_assignments_role_name_academic_year_status_index ON public.admin_assignments USING btree (role_name, academic_year, status);


--
-- Name: admin_assignments_source_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_assignments_source_index ON public.admin_assignments USING btree (source_type, source_id);


--
-- Name: admin_assignments_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_assignments_user_id_status_index ON public.admin_assignments USING btree (user_id, status);


--
-- Name: admin_assignments_user_school_year_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_assignments_user_school_year_status_index ON public.admin_assignments USING btree (user_id, school_year_id, status);


--
-- Name: approval_trails_proposal_id_decided_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX approval_trails_proposal_id_decided_at_index ON public.approval_trails USING btree (proposal_id, decided_at);


--
-- Name: approval_trails_workflow_run_id_decided_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX approval_trails_workflow_run_id_decided_at_index ON public.approval_trails USING btree (workflow_run_id, decided_at);


--
-- Name: approval_trails_workflow_task_id_decided_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX approval_trails_workflow_task_id_decided_at_index ON public.approval_trails USING btree (workflow_task_id, decided_at);


--
-- Name: attendance_records_event_day_session_id_student_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX attendance_records_event_day_session_id_student_id_index ON public.attendance_records USING btree (event_day_session_id, student_id);


--
-- Name: attendance_records_event_participation_scope_id_student_id_inde; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX attendance_records_event_participation_scope_id_student_id_inde ON public.attendance_records USING btree (event_participation_scope_id, student_id);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: canonical_ppa_attachments_path_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX canonical_ppa_attachments_path_index ON public.canonical_ppa_attachments USING btree (path);


--
-- Name: canonical_ppa_packets_activity_role_proposal_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX canonical_ppa_packets_activity_role_proposal_category_index ON public.canonical_ppa_packets USING btree (activity_role, proposal_category);


--
-- Name: canonical_ppa_packets_organizer_event_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX canonical_ppa_packets_organizer_event_unique ON public.canonical_ppa_packets USING btree (event_id) WHERE (event_participation_scope_id IS NULL);


--
-- Name: departments_college_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX departments_college_id_status_index ON public.departments USING btree (college_id, status);


--
-- Name: eaes_attendance_legacy_day_identity_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eaes_attendance_legacy_day_identity_unique ON public.attendance_records USING btree (event_id, event_day_id, student_id) WHERE ((event_day_session_id IS NULL) AND (event_day_id IS NOT NULL) AND (event_participation_scope_id IS NULL));


--
-- Name: eaes_attendance_legacy_identity_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eaes_attendance_legacy_identity_unique ON public.attendance_records USING btree (event_id, student_id) WHERE ((event_day_session_id IS NULL) AND (event_day_id IS NULL) AND (event_participation_scope_id IS NULL));


--
-- Name: eaes_attendance_legacy_session_identity_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eaes_attendance_legacy_session_identity_unique ON public.attendance_records USING btree (event_id, event_day_session_id, student_id) WHERE ((event_day_session_id IS NOT NULL) AND (event_participation_scope_id IS NULL));


--
-- Name: eaes_attendance_scoped_day_identity_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eaes_attendance_scoped_day_identity_unique ON public.attendance_records USING btree (event_id, event_day_id, student_id, event_participation_scope_id) WHERE ((event_day_session_id IS NULL) AND (event_day_id IS NOT NULL) AND (event_participation_scope_id IS NOT NULL));


--
-- Name: eaes_attendance_scoped_legacy_day_identity_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eaes_attendance_scoped_legacy_day_identity_unique ON public.attendance_records USING btree (event_id, student_id, event_participation_scope_id) WHERE ((event_day_session_id IS NULL) AND (event_day_id IS NULL) AND (event_participation_scope_id IS NOT NULL));


--
-- Name: eaes_attendance_scoped_session_identity_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eaes_attendance_scoped_session_identity_unique ON public.attendance_records USING btree (event_id, event_day_session_id, student_id, event_participation_scope_id) WHERE ((event_day_session_id IS NOT NULL) AND (event_participation_scope_id IS NOT NULL));


--
-- Name: eaes_pending_link_legacy_pending_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eaes_pending_link_legacy_pending_unique ON public.pending_student_links USING btree (event_id, qr_code_value) WHERE (((status)::text = 'pending'::text) AND (event_participation_scope_id IS NULL));


--
-- Name: eaes_pending_link_scope_pending_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eaes_pending_link_scope_pending_unique ON public.pending_student_links USING btree (event_id, event_participation_scope_id, qr_code_value) WHERE (((status)::text = 'pending'::text) AND (event_participation_scope_id IS NOT NULL));


--
-- Name: employee_roster_entries_college_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_roster_entries_college_id_status_index ON public.employee_roster_entries USING btree (college_id, status);


--
-- Name: employee_roster_entries_user_type_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_roster_entries_user_type_status_index ON public.employee_roster_entries USING btree (user_type, status);


--
-- Name: eps_active_linked_event_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eps_active_linked_event_unique ON public.event_participation_scopes USING btree (event_id, linked_event_id) WHERE (((status)::text = 'active'::text) AND ((participation_role)::text = 'participant'::text) AND (linked_event_id IS NOT NULL));


--
-- Name: eps_active_organizer_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eps_active_organizer_unique ON public.event_participation_scopes USING btree (event_id) WHERE (((status)::text = 'active'::text) AND ((participation_role)::text = 'organizer'::text));


--
-- Name: eps_active_participant_college_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eps_active_participant_college_unique ON public.event_participation_scopes USING btree (event_id, college_id) WHERE (((status)::text = 'active'::text) AND ((participation_role)::text = 'participant'::text) AND ((participant_type)::text = 'college'::text) AND (organization_id IS NULL) AND (college_id IS NOT NULL));


--
-- Name: eps_active_participant_dept_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eps_active_participant_dept_unique ON public.event_participation_scopes USING btree (event_id, department_id) WHERE (((status)::text = 'active'::text) AND ((participation_role)::text = 'participant'::text) AND ((participant_type)::text = 'department'::text) AND (department_id IS NOT NULL));


--
-- Name: eps_active_participant_org_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX eps_active_participant_org_unique ON public.event_participation_scopes USING btree (event_id, organization_id) WHERE (((status)::text = 'active'::text) AND ((participation_role)::text = 'participant'::text) AND (organization_id IS NOT NULL));


--
-- Name: eps_department_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX eps_department_status_index ON public.event_participation_scopes USING btree (department_id, status);


--
-- Name: eps_invitation_ack_reminder_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX eps_invitation_ack_reminder_index ON public.event_participation_scopes USING btree (invitation_acknowledged_at, invitation_last_reminded_at);


--
-- Name: eps_school_year_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX eps_school_year_status_index ON public.event_participation_scopes USING btree (school_year_id, status);


--
-- Name: esa_school_year_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX esa_school_year_status_index ON public.event_scanner_assignments USING btree (school_year_id, status);


--
-- Name: event_day_sessions_event_day_id_required_for_attendance_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_day_sessions_event_day_id_required_for_attendance_index ON public.event_day_sessions USING btree (event_day_id, required_for_attendance);


--
-- Name: event_participation_scopes_college_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_participation_scopes_college_id_status_index ON public.event_participation_scopes USING btree (college_id, status);


--
-- Name: event_participation_scopes_event_id_participation_role_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_participation_scopes_event_id_participation_role_index ON public.event_participation_scopes USING btree (event_id, participation_role);


--
-- Name: event_participation_scopes_event_id_ppa_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_participation_scopes_event_id_ppa_status_index ON public.event_participation_scopes USING btree (event_id, ppa_status);


--
-- Name: event_participation_scopes_event_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_participation_scopes_event_id_status_index ON public.event_participation_scopes USING btree (event_id, status);


--
-- Name: event_participation_scopes_linked_event_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_participation_scopes_linked_event_id_index ON public.event_participation_scopes USING btree (linked_event_id);


--
-- Name: event_participation_scopes_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_participation_scopes_organization_id_status_index ON public.event_participation_scopes USING btree (organization_id, status);


--
-- Name: event_permit_versions_event_id_superseded_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_permit_versions_event_id_superseded_at_index ON public.event_permit_versions USING btree (event_id, superseded_at);


--
-- Name: event_scanner_assignments_event_id_scanner_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_scanner_assignments_event_id_scanner_user_id_index ON public.event_scanner_assignments USING btree (event_id, scanner_user_id);


--
-- Name: event_scanner_assignments_event_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_scanner_assignments_event_id_status_index ON public.event_scanner_assignments USING btree (event_id, status);


--
-- Name: event_scanner_assignments_event_participation_scope_id_status_i; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_scanner_assignments_event_participation_scope_id_status_i ON public.event_scanner_assignments USING btree (event_participation_scope_id, status);


--
-- Name: event_scanner_assignments_scanner_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_scanner_assignments_scanner_user_id_status_index ON public.event_scanner_assignments USING btree (scanner_user_id, status);


--
-- Name: events_department_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX events_department_id_status_index ON public.events USING btree (department_id, status);


--
-- Name: events_sy_org_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX events_sy_org_index ON public.events USING btree (school_year_id, organization_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON public.model_has_permissions USING btree (model_id, model_type);


--
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_roles_model_id_model_type_index ON public.model_has_roles USING btree (model_id, model_type);


--
-- Name: notifications_notifiable_type_notifiable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_notifiable_type_notifiable_id_index ON public.notifications USING btree (notifiable_type, notifiable_id);


--
-- Name: officer_change_requests_new_user_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX officer_change_requests_new_user_index ON public.officer_change_requests USING btree (new_user_id);


--
-- Name: officer_change_requests_old_officer_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX officer_change_requests_old_officer_index ON public.officer_change_requests USING btree (old_officer_id);


--
-- Name: officer_change_requests_org_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX officer_change_requests_org_status_index ON public.officer_change_requests USING btree (organization_id, status);


--
-- Name: officer_change_requests_roster_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX officer_change_requests_roster_status_index ON public.officer_change_requests USING btree (officer_roster_id, status);


--
-- Name: officer_rosters_org_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX officer_rosters_org_status_index ON public.organization_officer_rosters USING btree (organization_id, status);


--
-- Name: officer_rosters_org_sy_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX officer_rosters_org_sy_index ON public.organization_officer_rosters USING btree (organization_id, school_year_id);


--
-- Name: organization_memberships_college_id_program_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_memberships_college_id_program_id_index ON public.organization_memberships USING btree (college_id, program_id);


--
-- Name: organization_memberships_department_program_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_memberships_department_program_index ON public.organization_memberships USING btree (department_id, program_id);


--
-- Name: organization_memberships_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_memberships_organization_id_status_index ON public.organization_memberships USING btree (organization_id, status);


--
-- Name: organization_memberships_school_year_org_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_memberships_school_year_org_status_index ON public.organization_memberships USING btree (school_year_id, organization_id, status);


--
-- Name: organization_memberships_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_memberships_user_id_status_index ON public.organization_memberships USING btree (user_id, status);


--
-- Name: organization_memberships_year_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_memberships_year_level_index ON public.organization_memberships USING btree (year_level);


--
-- Name: organization_officers_roster_position_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_officers_roster_position_index ON public.organization_officers USING btree (officer_roster_id, position_key);


--
-- Name: organization_officers_user_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_officers_user_status_index ON public.organization_officers USING btree (user_id, status);


--
-- Name: organizations_type_college_acronym_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX organizations_type_college_acronym_unique ON public.organizations USING btree (type, COALESCE((college_id)::text, '00000000-0000-0000-0000-000000000000'::text), acronym);


--
-- Name: osa_authority_overrides_action_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX osa_authority_overrides_action_status_index ON public.osa_authority_overrides USING btree (action, status);


--
-- Name: osa_authority_overrides_actor_acted_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX osa_authority_overrides_actor_acted_index ON public.osa_authority_overrides USING btree (actor_id, acted_at);


--
-- Name: osa_authority_overrides_org_sy_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX osa_authority_overrides_org_sy_status_index ON public.osa_authority_overrides USING btree (organization_id, school_year_id, status);


--
-- Name: osa_authority_overrides_target_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX osa_authority_overrides_target_status_index ON public.osa_authority_overrides USING btree (target_user_id, status);


--
-- Name: pending_student_links_event_id_qr_code_value_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pending_student_links_event_id_qr_code_value_status_index ON public.pending_student_links USING btree (event_id, qr_code_value, status);


--
-- Name: pending_student_links_event_participation_scope_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pending_student_links_event_participation_scope_id_status_index ON public.pending_student_links USING btree (event_participation_scope_id, status);


--
-- Name: permit_copy_owners_event_id_owner_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permit_copy_owners_event_id_owner_type_index ON public.permit_copy_owners USING btree (event_id, owner_type);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: proposal_process_steps_template_version_id_display_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX proposal_process_steps_template_version_id_display_order_index ON public.proposal_process_steps USING btree (template_version_id, display_order);


--
-- Name: proposal_process_template_versions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX proposal_process_template_versions_status_index ON public.proposal_process_template_versions USING btree (status);


--
-- Name: proposal_process_templates_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX proposal_process_templates_status_index ON public.proposal_process_templates USING btree (status);


--
-- Name: proposal_template_applicability_rules_is_default_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX proposal_template_applicability_rules_is_default_status_index ON public.proposal_template_applicability_rules USING btree (is_default, status);


--
-- Name: proposal_template_applicability_rules_priority_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX proposal_template_applicability_rules_priority_status_index ON public.proposal_template_applicability_rules USING btree (priority, status);


--
-- Name: proposal_workflow_runs_event_organizer_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX proposal_workflow_runs_event_organizer_unique ON public.proposal_workflow_runs USING btree (event_id) WHERE (event_participation_scope_id IS NULL);


--
-- Name: proposal_workflow_runs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX proposal_workflow_runs_status_index ON public.proposal_workflow_runs USING btree (status);


--
-- Name: proposal_workflow_tasks_assigned_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX proposal_workflow_tasks_assigned_user_id_status_index ON public.proposal_workflow_tasks USING btree (assigned_user_id, status);


--
-- Name: proposal_workflow_tasks_workflow_run_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX proposal_workflow_tasks_workflow_run_id_status_index ON public.proposal_workflow_tasks USING btree (workflow_run_id, status);


--
-- Name: queue_outbox_entries_type_dispatched_at_available_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_outbox_entries_type_dispatched_at_available_at_index ON public.queue_outbox_entries USING btree (type, dispatched_at, available_at);


--
-- Name: raw_scans_event_day_session_id_student_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX raw_scans_event_day_session_id_student_id_index ON public.raw_scans USING btree (event_day_session_id, student_id);


--
-- Name: raw_scans_event_participation_scope_id_student_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX raw_scans_event_participation_scope_id_student_id_index ON public.raw_scans USING btree (event_participation_scope_id, student_id);


--
-- Name: scanner_sessions_assignment_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX scanner_sessions_assignment_id_status_index ON public.scanner_sessions USING btree (assignment_id, status);


--
-- Name: scanner_sessions_event_participation_scope_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX scanner_sessions_event_participation_scope_id_status_index ON public.scanner_sessions USING btree (event_participation_scope_id, status);


--
-- Name: scanner_sessions_token_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX scanner_sessions_token_id_index ON public.scanner_sessions USING btree (token_id);


--
-- Name: school_years_one_active_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX school_years_one_active_unique ON public.school_years USING btree (status) WHERE ((status)::text = 'active'::text);


--
-- Name: school_years_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX school_years_status_index ON public.school_years USING btree (status);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: users_year_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_year_level_index ON public.users USING btree (year_level);


--
-- Name: venues_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX venues_is_active_index ON public.venues USING btree (is_active);


--
-- Name: workflow_task_assignment_history_new_assignee_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX workflow_task_assignment_history_new_assignee_idx ON public.workflow_task_assignment_history USING btree (new_assignee_id, reassigned_at);


--
-- Name: workflow_task_assignment_history_task_time_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX workflow_task_assignment_history_task_time_idx ON public.workflow_task_assignment_history USING btree (workflow_task_id, reassigned_at);


--
-- Name: approval_trails approval_trails_no_delete; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER approval_trails_no_delete BEFORE DELETE ON public.approval_trails FOR EACH ROW EXECUTE FUNCTION public.prevent_approval_trails_mutation();


--
-- Name: approval_trails approval_trails_no_update; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER approval_trails_no_update BEFORE UPDATE ON public.approval_trails FOR EACH ROW EXECUTE FUNCTION public.prevent_approval_trails_mutation();


--
-- Name: event_permit_versions event_permit_versions_no_delete; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER event_permit_versions_no_delete BEFORE DELETE ON public.event_permit_versions FOR EACH ROW EXECUTE FUNCTION public.prevent_event_permit_versions_mutation();


--
-- Name: event_permit_versions event_permit_versions_no_update; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER event_permit_versions_no_update BEFORE UPDATE ON public.event_permit_versions FOR EACH ROW EXECUTE FUNCTION public.prevent_event_permit_versions_mutation();


--
-- Name: admin_assignments admin_assignments_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_assignments
    ADD CONSTRAINT admin_assignments_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: admin_assignments admin_assignments_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_assignments
    ADD CONSTRAINT admin_assignments_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE SET NULL;


--
-- Name: admin_assignments admin_assignments_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_assignments
    ADD CONSTRAINT admin_assignments_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: admin_assignments admin_assignments_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_assignments
    ADD CONSTRAINT admin_assignments_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: admin_assignments admin_assignments_revoked_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_assignments
    ADD CONSTRAINT admin_assignments_revoked_by_foreign FOREIGN KEY (revoked_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: admin_assignments admin_assignments_school_year_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_assignments
    ADD CONSTRAINT admin_assignments_school_year_id_foreign FOREIGN KEY (school_year_id) REFERENCES public.school_years(id) ON DELETE SET NULL;


--
-- Name: admin_assignments admin_assignments_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_assignments
    ADD CONSTRAINT admin_assignments_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: approval_trails approval_trails_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_trails
    ADD CONSTRAINT approval_trails_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: approval_trails approval_trails_proposal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_trails
    ADD CONSTRAINT approval_trails_proposal_id_foreign FOREIGN KEY (proposal_id) REFERENCES public.events(id);


--
-- Name: approval_trails approval_trails_workflow_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_trails
    ADD CONSTRAINT approval_trails_workflow_run_id_foreign FOREIGN KEY (workflow_run_id) REFERENCES public.proposal_workflow_runs(id);


--
-- Name: approval_trails approval_trails_workflow_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approval_trails
    ADD CONSTRAINT approval_trails_workflow_task_id_foreign FOREIGN KEY (workflow_task_id) REFERENCES public.proposal_workflow_tasks(id);


--
-- Name: attendance_records attendance_records_event_day_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_event_day_id_foreign FOREIGN KEY (event_day_id) REFERENCES public.event_days(id) ON DELETE SET NULL;


--
-- Name: attendance_records attendance_records_event_day_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_event_day_session_id_foreign FOREIGN KEY (event_day_session_id) REFERENCES public.event_day_sessions(id) ON DELETE SET NULL;


--
-- Name: attendance_records attendance_records_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: attendance_records attendance_records_event_participation_scope_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_event_participation_scope_id_foreign FOREIGN KEY (event_participation_scope_id) REFERENCES public.event_participation_scopes(id) ON DELETE SET NULL;


--
-- Name: attendance_records attendance_records_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: attendance_records attendance_records_validated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_records
    ADD CONSTRAINT attendance_records_validated_by_foreign FOREIGN KEY (validated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: audit_logs audit_logs_admin_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_admin_id_foreign FOREIGN KEY (admin_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: canonical_ppa_attachments canonical_ppa_attachments_canonical_ppa_packet_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_attachments
    ADD CONSTRAINT canonical_ppa_attachments_canonical_ppa_packet_id_foreign FOREIGN KEY (canonical_ppa_packet_id) REFERENCES public.canonical_ppa_packets(id) ON DELETE CASCADE;


--
-- Name: canonical_ppa_attachments canonical_ppa_attachments_uploaded_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_attachments
    ADD CONSTRAINT canonical_ppa_attachments_uploaded_by_user_id_foreign FOREIGN KEY (uploaded_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: canonical_ppa_packets canonical_ppa_packets_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_packets
    ADD CONSTRAINT canonical_ppa_packets_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: canonical_ppa_packets canonical_ppa_packets_event_participation_scope_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_packets
    ADD CONSTRAINT canonical_ppa_packets_event_participation_scope_id_foreign FOREIGN KEY (event_participation_scope_id) REFERENCES public.event_participation_scopes(id) ON DELETE CASCADE;


--
-- Name: canonical_ppa_packets canonical_ppa_packets_prepared_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_packets
    ADD CONSTRAINT canonical_ppa_packets_prepared_by_user_id_foreign FOREIGN KEY (prepared_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: canonical_ppa_packets canonical_ppa_packets_venue_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.canonical_ppa_packets
    ADD CONSTRAINT canonical_ppa_packets_venue_id_foreign FOREIGN KEY (venue_id) REFERENCES public.venues(id) ON DELETE RESTRICT;


--
-- Name: colleges colleges_university_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.colleges
    ADD CONSTRAINT colleges_university_id_foreign FOREIGN KEY (university_id) REFERENCES public.universities(id) ON DELETE CASCADE;


--
-- Name: departments departments_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE CASCADE;


--
-- Name: employee_profiles employee_profiles_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE SET NULL;


--
-- Name: employee_profiles employee_profiles_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: employee_profiles employee_profiles_verified_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_verified_by_foreign FOREIGN KEY (verified_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: employee_roster_entries employee_roster_entries_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_roster_entries
    ADD CONSTRAINT employee_roster_entries_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE SET NULL;


--
-- Name: employee_roster_entries employee_roster_entries_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_roster_entries
    ADD CONSTRAINT employee_roster_entries_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: employee_roster_entries employee_roster_entries_verified_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_roster_entries
    ADD CONSTRAINT employee_roster_entries_verified_by_foreign FOREIGN KEY (verified_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: evaluation_form_versions evaluation_form_versions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluation_form_versions
    ADD CONSTRAINT evaluation_form_versions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: evaluation_form_versions evaluation_form_versions_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluation_form_versions
    ADD CONSTRAINT evaluation_form_versions_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: evaluations evaluations_evaluation_form_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluations
    ADD CONSTRAINT evaluations_evaluation_form_version_id_foreign FOREIGN KEY (evaluation_form_version_id) REFERENCES public.evaluation_form_versions(id) ON DELETE SET NULL;


--
-- Name: evaluations evaluations_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluations
    ADD CONSTRAINT evaluations_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: evaluations evaluations_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluations
    ADD CONSTRAINT evaluations_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: event_day_sessions event_day_sessions_event_day_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_day_sessions
    ADD CONSTRAINT event_day_sessions_event_day_id_foreign FOREIGN KEY (event_day_id) REFERENCES public.event_days(id) ON DELETE CASCADE;


--
-- Name: event_days event_days_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_days
    ADD CONSTRAINT event_days_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_participation_scopes event_participation_scopes_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_delegated_to_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_delegated_to_organization_id_foreign FOREIGN KEY (delegated_to_organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_participation_scopes event_participation_scopes_invitation_acknowledged_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_invitation_acknowledged_by_foreign FOREIGN KEY (invitation_acknowledged_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_linked_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_linked_event_id_foreign FOREIGN KEY (linked_event_id) REFERENCES public.events(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_parent_scope_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_parent_scope_id_foreign FOREIGN KEY (parent_scope_id) REFERENCES public.event_participation_scopes(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_program_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_program_id_foreign FOREIGN KEY (program_id) REFERENCES public.programs(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_reviewed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_school_year_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_school_year_id_foreign FOREIGN KEY (school_year_id) REFERENCES public.school_years(id) ON DELETE SET NULL;


--
-- Name: event_participation_scopes event_participation_scopes_submitted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_participation_scopes
    ADD CONSTRAINT event_participation_scopes_submitted_by_foreign FOREIGN KEY (submitted_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: event_permit_versions event_permit_versions_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_permit_versions
    ADD CONSTRAINT event_permit_versions_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: event_permit_versions event_permit_versions_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_permit_versions
    ADD CONSTRAINT event_permit_versions_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_permit_versions event_permit_versions_venue_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_permit_versions
    ADD CONSTRAINT event_permit_versions_venue_id_foreign FOREIGN KEY (venue_id) REFERENCES public.venues(id) ON DELETE RESTRICT;


--
-- Name: event_permit_versions event_permit_versions_workflow_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_permit_versions
    ADD CONSTRAINT event_permit_versions_workflow_run_id_foreign FOREIGN KEY (workflow_run_id) REFERENCES public.proposal_workflow_runs(id) ON DELETE RESTRICT;


--
-- Name: event_scanner_assignments event_scanner_assignments_assigned_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_scanner_assignments
    ADD CONSTRAINT event_scanner_assignments_assigned_by_user_id_foreign FOREIGN KEY (assigned_by_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: event_scanner_assignments event_scanner_assignments_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_scanner_assignments
    ADD CONSTRAINT event_scanner_assignments_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: event_scanner_assignments event_scanner_assignments_event_participation_scope_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_scanner_assignments
    ADD CONSTRAINT event_scanner_assignments_event_participation_scope_id_foreign FOREIGN KEY (event_participation_scope_id) REFERENCES public.event_participation_scopes(id) ON DELETE SET NULL;


--
-- Name: event_scanner_assignments event_scanner_assignments_revoked_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_scanner_assignments
    ADD CONSTRAINT event_scanner_assignments_revoked_by_user_id_foreign FOREIGN KEY (revoked_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: event_scanner_assignments event_scanner_assignments_scanner_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_scanner_assignments
    ADD CONSTRAINT event_scanner_assignments_scanner_user_id_foreign FOREIGN KEY (scanner_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: event_scanner_assignments event_scanner_assignments_school_year_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.event_scanner_assignments
    ADD CONSTRAINT event_scanner_assignments_school_year_id_foreign FOREIGN KEY (school_year_id) REFERENCES public.school_years(id) ON DELETE SET NULL;


--
-- Name: events events_college_dean_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_college_dean_user_id_foreign FOREIGN KEY (college_dean_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: events events_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: events events_dept_chair_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_dept_chair_user_id_foreign FOREIGN KEY (dept_chair_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: events events_evaluation_form_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_evaluation_form_version_id_foreign FOREIGN KEY (evaluation_form_version_id) REFERENCES public.evaluation_form_versions(id) ON DELETE SET NULL;


--
-- Name: events events_org_head_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_org_head_user_id_foreign FOREIGN KEY (org_head_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: events events_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: events events_parent_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_parent_event_id_foreign FOREIGN KEY (parent_event_id) REFERENCES public.events(id) ON DELETE SET NULL;


--
-- Name: events events_proponent_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_proponent_user_id_foreign FOREIGN KEY (proponent_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: events events_school_year_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_school_year_id_foreign FOREIGN KEY (school_year_id) REFERENCES public.school_years(id) ON DELETE SET NULL;


--
-- Name: events events_venue_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_venue_id_foreign FOREIGN KEY (venue_id) REFERENCES public.venues(id) ON DELETE RESTRICT;


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: officer_change_requests officer_change_requests_created_officer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.officer_change_requests
    ADD CONSTRAINT officer_change_requests_created_officer_id_foreign FOREIGN KEY (created_officer_id) REFERENCES public.organization_officers(id) ON DELETE SET NULL;


--
-- Name: officer_change_requests officer_change_requests_decided_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.officer_change_requests
    ADD CONSTRAINT officer_change_requests_decided_by_foreign FOREIGN KEY (decided_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: officer_change_requests officer_change_requests_new_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.officer_change_requests
    ADD CONSTRAINT officer_change_requests_new_user_id_foreign FOREIGN KEY (new_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: officer_change_requests officer_change_requests_officer_roster_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.officer_change_requests
    ADD CONSTRAINT officer_change_requests_officer_roster_id_foreign FOREIGN KEY (officer_roster_id) REFERENCES public.organization_officer_rosters(id) ON DELETE CASCADE;


--
-- Name: officer_change_requests officer_change_requests_old_officer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.officer_change_requests
    ADD CONSTRAINT officer_change_requests_old_officer_id_foreign FOREIGN KEY (old_officer_id) REFERENCES public.organization_officers(id) ON DELETE SET NULL;


--
-- Name: officer_change_requests officer_change_requests_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.officer_change_requests
    ADD CONSTRAINT officer_change_requests_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: officer_change_requests officer_change_requests_requested_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.officer_change_requests
    ADD CONSTRAINT officer_change_requests_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organization_memberships organization_memberships_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_memberships
    ADD CONSTRAINT organization_memberships_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE SET NULL;


--
-- Name: organization_memberships organization_memberships_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_memberships
    ADD CONSTRAINT organization_memberships_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: organization_memberships organization_memberships_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_memberships
    ADD CONSTRAINT organization_memberships_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_memberships organization_memberships_program_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_memberships
    ADD CONSTRAINT organization_memberships_program_id_foreign FOREIGN KEY (program_id) REFERENCES public.programs(id) ON DELETE SET NULL;


--
-- Name: organization_memberships organization_memberships_school_year_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_memberships
    ADD CONSTRAINT organization_memberships_school_year_id_foreign FOREIGN KEY (school_year_id) REFERENCES public.school_years(id) ON DELETE SET NULL;


--
-- Name: organization_memberships organization_memberships_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_memberships
    ADD CONSTRAINT organization_memberships_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: organization_memberships organization_memberships_verified_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_memberships
    ADD CONSTRAINT organization_memberships_verified_by_foreign FOREIGN KEY (verified_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organization_officer_rosters organization_officer_rosters_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officer_rosters
    ADD CONSTRAINT organization_officer_rosters_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organization_officer_rosters organization_officer_rosters_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officer_rosters
    ADD CONSTRAINT organization_officer_rosters_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_officer_rosters organization_officer_rosters_school_year_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officer_rosters
    ADD CONSTRAINT organization_officer_rosters_school_year_id_foreign FOREIGN KEY (school_year_id) REFERENCES public.school_years(id) ON DELETE CASCADE;


--
-- Name: organization_officer_rosters organization_officer_rosters_submitted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officer_rosters
    ADD CONSTRAINT organization_officer_rosters_submitted_by_foreign FOREIGN KEY (submitted_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organization_officer_rosters organization_officer_rosters_verified_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officer_rosters
    ADD CONSTRAINT organization_officer_rosters_verified_by_foreign FOREIGN KEY (verified_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organization_officers organization_officers_officer_roster_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officers
    ADD CONSTRAINT organization_officers_officer_roster_id_foreign FOREIGN KEY (officer_roster_id) REFERENCES public.organization_officer_rosters(id) ON DELETE CASCADE;


--
-- Name: organization_officers organization_officers_replacement_officer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officers
    ADD CONSTRAINT organization_officers_replacement_officer_id_foreign FOREIGN KEY (replacement_officer_id) REFERENCES public.organization_officers(id) ON DELETE SET NULL;


--
-- Name: organization_officers organization_officers_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_officers
    ADD CONSTRAINT organization_officers_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: organization_programs organization_programs_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_programs
    ADD CONSTRAINT organization_programs_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_programs organization_programs_program_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_programs
    ADD CONSTRAINT organization_programs_program_id_foreign FOREIGN KEY (program_id) REFERENCES public.programs(id) ON DELETE CASCADE;


--
-- Name: organizations organizations_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE CASCADE;


--
-- Name: organizations organizations_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: osa_authority_overrides osa_authority_overrides_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: osa_authority_overrides osa_authority_overrides_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE SET NULL;


--
-- Name: osa_authority_overrides osa_authority_overrides_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: osa_authority_overrides osa_authority_overrides_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: osa_authority_overrides osa_authority_overrides_projected_assignment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_projected_assignment_id_foreign FOREIGN KEY (projected_assignment_id) REFERENCES public.admin_assignments(id) ON DELETE SET NULL;


--
-- Name: osa_authority_overrides osa_authority_overrides_school_year_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_school_year_id_foreign FOREIGN KEY (school_year_id) REFERENCES public.school_years(id) ON DELETE SET NULL;


--
-- Name: osa_authority_overrides osa_authority_overrides_source_assignment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_source_assignment_id_foreign FOREIGN KEY (source_assignment_id) REFERENCES public.admin_assignments(id) ON DELETE SET NULL;


--
-- Name: osa_authority_overrides osa_authority_overrides_target_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.osa_authority_overrides
    ADD CONSTRAINT osa_authority_overrides_target_user_id_foreign FOREIGN KEY (target_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pending_student_links pending_student_links_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_student_links
    ADD CONSTRAINT pending_student_links_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: pending_student_links pending_student_links_event_participation_scope_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_student_links
    ADD CONSTRAINT pending_student_links_event_participation_scope_id_foreign FOREIGN KEY (event_participation_scope_id) REFERENCES public.event_participation_scopes(id) ON DELETE SET NULL;


--
-- Name: pending_student_links pending_student_links_flagged_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_student_links
    ADD CONSTRAINT pending_student_links_flagged_by_foreign FOREIGN KEY (flagged_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pending_student_links pending_student_links_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_student_links
    ADD CONSTRAINT pending_student_links_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: pending_student_links pending_student_links_raw_scan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_student_links
    ADD CONSTRAINT pending_student_links_raw_scan_id_foreign FOREIGN KEY (raw_scan_id) REFERENCES public.raw_scans(id) ON DELETE SET NULL;


--
-- Name: pending_student_links pending_student_links_resolved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_student_links
    ADD CONSTRAINT pending_student_links_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pending_student_links pending_student_links_resolved_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pending_student_links
    ADD CONSTRAINT pending_student_links_resolved_student_id_foreign FOREIGN KEY (resolved_student_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: permit_copy_owners permit_copy_owners_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permit_copy_owners
    ADD CONSTRAINT permit_copy_owners_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: permit_copy_owners permit_copy_owners_event_permit_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permit_copy_owners
    ADD CONSTRAINT permit_copy_owners_event_permit_version_id_foreign FOREIGN KEY (event_permit_version_id) REFERENCES public.event_permit_versions(id) ON DELETE CASCADE;


--
-- Name: programs programs_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT programs_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE RESTRICT;


--
-- Name: proposal_process_step_dependencies proposal_process_step_dependencies_prerequisite_step_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_step_dependencies
    ADD CONSTRAINT proposal_process_step_dependencies_prerequisite_step_id_foreign FOREIGN KEY (prerequisite_step_id) REFERENCES public.proposal_process_steps(id) ON DELETE CASCADE;


--
-- Name: proposal_process_step_dependencies proposal_process_step_dependencies_step_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_step_dependencies
    ADD CONSTRAINT proposal_process_step_dependencies_step_id_foreign FOREIGN KEY (step_id) REFERENCES public.proposal_process_steps(id) ON DELETE CASCADE;


--
-- Name: proposal_process_steps proposal_process_steps_template_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_steps
    ADD CONSTRAINT proposal_process_steps_template_version_id_foreign FOREIGN KEY (template_version_id) REFERENCES public.proposal_process_template_versions(id) ON DELETE CASCADE;


--
-- Name: proposal_process_template_versions proposal_process_template_versions_published_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_template_versions
    ADD CONSTRAINT proposal_process_template_versions_published_by_foreign FOREIGN KEY (published_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: proposal_process_template_versions proposal_process_template_versions_retired_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_template_versions
    ADD CONSTRAINT proposal_process_template_versions_retired_by_foreign FOREIGN KEY (retired_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: proposal_process_template_versions proposal_process_template_versions_template_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_template_versions
    ADD CONSTRAINT proposal_process_template_versions_template_id_foreign FOREIGN KEY (template_id) REFERENCES public.proposal_process_templates(id) ON DELETE CASCADE;


--
-- Name: proposal_process_templates proposal_process_templates_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_process_templates
    ADD CONSTRAINT proposal_process_templates_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: proposal_template_applicability_rules proposal_template_applicability_rules_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_template_applicability_rules
    ADD CONSTRAINT proposal_template_applicability_rules_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE SET NULL;


--
-- Name: proposal_template_applicability_rules proposal_template_applicability_rules_template_version_id_forei; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_template_applicability_rules
    ADD CONSTRAINT proposal_template_applicability_rules_template_version_id_forei FOREIGN KEY (template_version_id) REFERENCES public.proposal_process_template_versions(id) ON DELETE CASCADE;


--
-- Name: proposal_workflow_runs proposal_workflow_runs_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_runs
    ADD CONSTRAINT proposal_workflow_runs_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: proposal_workflow_runs proposal_workflow_runs_event_participation_scope_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_runs
    ADD CONSTRAINT proposal_workflow_runs_event_participation_scope_id_foreign FOREIGN KEY (event_participation_scope_id) REFERENCES public.event_participation_scopes(id) ON DELETE CASCADE;


--
-- Name: proposal_workflow_runs proposal_workflow_runs_started_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_runs
    ADD CONSTRAINT proposal_workflow_runs_started_by_foreign FOREIGN KEY (started_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: proposal_workflow_runs proposal_workflow_runs_template_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_runs
    ADD CONSTRAINT proposal_workflow_runs_template_version_id_foreign FOREIGN KEY (template_version_id) REFERENCES public.proposal_process_template_versions(id) ON DELETE CASCADE;


--
-- Name: proposal_workflow_tasks proposal_workflow_tasks_assigned_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_tasks
    ADD CONSTRAINT proposal_workflow_tasks_assigned_user_id_foreign FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: proposal_workflow_tasks proposal_workflow_tasks_decided_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_tasks
    ADD CONSTRAINT proposal_workflow_tasks_decided_by_foreign FOREIGN KEY (decided_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: proposal_workflow_tasks proposal_workflow_tasks_process_step_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_tasks
    ADD CONSTRAINT proposal_workflow_tasks_process_step_id_foreign FOREIGN KEY (process_step_id) REFERENCES public.proposal_process_steps(id) ON DELETE CASCADE;


--
-- Name: proposal_workflow_tasks proposal_workflow_tasks_workflow_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proposal_workflow_tasks
    ADD CONSTRAINT proposal_workflow_tasks_workflow_run_id_foreign FOREIGN KEY (workflow_run_id) REFERENCES public.proposal_workflow_runs(id) ON DELETE CASCADE;


--
-- Name: raw_scans raw_scans_captured_by_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_captured_by_organization_id_foreign FOREIGN KEY (captured_by_organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: raw_scans raw_scans_event_day_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_event_day_id_foreign FOREIGN KEY (event_day_id) REFERENCES public.event_days(id) ON DELETE SET NULL;


--
-- Name: raw_scans raw_scans_event_day_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_event_day_session_id_foreign FOREIGN KEY (event_day_session_id) REFERENCES public.event_day_sessions(id) ON DELETE SET NULL;


--
-- Name: raw_scans raw_scans_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: raw_scans raw_scans_event_participation_scope_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_event_participation_scope_id_foreign FOREIGN KEY (event_participation_scope_id) REFERENCES public.event_participation_scopes(id) ON DELETE SET NULL;


--
-- Name: raw_scans raw_scans_scanner_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_scanner_session_id_foreign FOREIGN KEY (scanner_session_id) REFERENCES public.scanner_sessions(id) ON DELETE SET NULL;


--
-- Name: raw_scans raw_scans_scanner_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_scanner_user_id_foreign FOREIGN KEY (scanner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: raw_scans raw_scans_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raw_scans
    ADD CONSTRAINT raw_scans_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: scanner_sessions scanner_sessions_assignment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scanner_sessions
    ADD CONSTRAINT scanner_sessions_assignment_id_foreign FOREIGN KEY (assignment_id) REFERENCES public.event_scanner_assignments(id) ON DELETE SET NULL;


--
-- Name: scanner_sessions scanner_sessions_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scanner_sessions
    ADD CONSTRAINT scanner_sessions_event_id_foreign FOREIGN KEY (event_id) REFERENCES public.events(id) ON DELETE CASCADE;


--
-- Name: scanner_sessions scanner_sessions_event_participation_scope_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scanner_sessions
    ADD CONSTRAINT scanner_sessions_event_participation_scope_id_foreign FOREIGN KEY (event_participation_scope_id) REFERENCES public.event_participation_scopes(id) ON DELETE SET NULL;


--
-- Name: scanner_sessions scanner_sessions_issued_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scanner_sessions
    ADD CONSTRAINT scanner_sessions_issued_by_foreign FOREIGN KEY (issued_by) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: scanner_sessions scanner_sessions_revoked_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scanner_sessions
    ADD CONSTRAINT scanner_sessions_revoked_by_foreign FOREIGN KEY (revoked_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: scanner_sessions scanner_sessions_scanner_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scanner_sessions
    ADD CONSTRAINT scanner_sessions_scanner_user_id_foreign FOREIGN KEY (scanner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: school_years school_years_activated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_years
    ADD CONSTRAINT school_years_activated_by_foreign FOREIGN KEY (activated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: school_years school_years_closed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_years
    ADD CONSTRAINT school_years_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: school_years school_years_transition_closed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_years
    ADD CONSTRAINT school_years_transition_closed_by_foreign FOREIGN KEY (transition_closed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: school_years school_years_transition_opened_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_years
    ADD CONSTRAINT school_years_transition_opened_by_foreign FOREIGN KEY (transition_opened_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: users users_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE SET NULL;


--
-- Name: users users_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: users users_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: users users_program_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_program_id_foreign FOREIGN KEY (program_id) REFERENCES public.programs(id) ON DELETE SET NULL;


--
-- Name: venues venues_college_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venues
    ADD CONSTRAINT venues_college_id_foreign FOREIGN KEY (college_id) REFERENCES public.colleges(id) ON DELETE RESTRICT;


--
-- Name: workflow_task_assignment_history workflow_task_assignment_history_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_task_assignment_history
    ADD CONSTRAINT workflow_task_assignment_history_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: workflow_task_assignment_history workflow_task_assignment_history_former_assignee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_task_assignment_history
    ADD CONSTRAINT workflow_task_assignment_history_former_assignee_id_foreign FOREIGN KEY (former_assignee_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: workflow_task_assignment_history workflow_task_assignment_history_new_assignee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_task_assignment_history
    ADD CONSTRAINT workflow_task_assignment_history_new_assignee_id_foreign FOREIGN KEY (new_assignee_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: workflow_task_assignment_history workflow_task_assignment_history_workflow_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_task_assignment_history
    ADD CONSTRAINT workflow_task_assignment_history_workflow_task_id_foreign FOREIGN KEY (workflow_task_id) REFERENCES public.proposal_workflow_tasks(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict OTqnRPBRhP0CoeNpLsCrSUIatEtiKKokVRl6wEcNbkIAxbijvQoj3YwS41sxy1r

--
-- PostgreSQL database dump
--

\restrict plqLkYVd5BcTyypjVNaYHj1h8PFONyVtWsdXF93hnRaNfuOhtSvjCTl1aIwfgxV

-- Dumped from database version 16.14
-- Dumped by pg_dump version 16.14

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	0001_01_01_000003_create_usm_tables	1
5	2026_05_30_125036_create_personal_access_tokens_table	1
6	2026_05_30_125038_create_permission_tables	1
7	2026_06_25_000001_create_pending_student_links_table	1
8	2026_06_26_000001_add_proposal_document_fields_to_events_table	1
9	2026_06_26_000002_create_programs_table	1
10	2026_06_26_000003_create_organization_programs_table	1
11	2026_06_26_000004_add_college_and_program_to_users_table	1
12	2026_06_27_000001_create_admin_onboarding_tables	1
13	2026_06_29_000001_use_uuid_tokenable_ids_for_sanctum_tokens	1
14	2026_06_30_000001_add_event_lifecycle_and_scanner_sessions	1
15	2026_06_30_000002_create_event_scanner_assignments_table	1
16	2026_07_03_000001_add_participation_scopes_memberships_and_hr_domain	1
17	2026_07_10_000001_create_notifications_and_participation_acknowledgements	1
18	2026_07_10_000002_add_year_level_to_student_profiles	1
19	2026_07_17_000001_add_organizer_attendance_policy_cutoffs	1
20	2026_07_21_000001_add_event_evaluation_form_versions	1
21	2026_08_08_145452_add_active_participation_scope_uniqueness	1
22	2026_08_08_163440_add_scanner_sync_concurrency_constraints	1
23	2026_08_08_174102_create_queue_outbox_entries_table	1
24	2026_09_13_152610_create_venues_table	1
25	2026_09_13_152612_create_event_permit_versions_table	1
26	2026_09_13_152614_add_venue_confirmation_fields_to_events_table	1
27	2026_09_17_235712_create_event_day_sessions_table	1
28	2026_09_24_082958_create_departments_table	1
29	2026_09_24_111012_create_school_years_table	1
30	2026_09_24_113429_add_department_scope_to_programs_and_organizations	1
31	2026_09_24_172210_add_department_and_school_year_to_student_profiles	1
32	2026_09_24_174043_create_canonical_ppa_packets_table	1
33	2026_09_24_180000_create_organization_officer_rosters_and_officers_tables	1
34	2026_09_26_041926_add_permit_evidence_to_canonical_ppa_packets_table	1
35	2026_09_26_042527_make_generated_permit_snapshot_documents_nullable	1
36	2026_09_26_043000_create_officer_change_requests_table	1
37	2026_09_26_051924_add_authority_projection_columns_to_admin_assignments_table	1
38	2026_09_26_171609_create_osa_authority_overrides_table	1
39	2026_09_26_174710_add_school_year_and_governance_snapshots_to_events_table	1
40	2026_09_26_180000_create_proposal_workflow_tables	1
41	2026_09_26_183000_add_school_year_and_department_to_scopes_and_assignments	1
42	2026_09_26_201541_create_workflow_task_assignment_history_table	1
43	2026_09_26_205151_create_approval_trails_table	1
44	2026_09_27_000956_create_permit_copy_owners_table	1
45	2026_09_27_001001_add_workflow_snapshots_to_event_permit_versions_table	1
46	2026_09_27_010000_contract_legacy_hierarchy_and_authority_paths	1
47	2026_09_27_193033_add_scope_and_participant_fields_to_canonical_ppa_packets_table	1
48	2026_09_28_151022_add_participation_scope_to_proposal_workflow_runs_table	1
49	2026_09_28_235340_enforce_department_owned_program_integrity	1
50	2026_09_29_043323_create_canonical_ppa_attachments_table	2
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 50, true);


--
-- PostgreSQL database dump complete
--

\unrestrict plqLkYVd5BcTyypjVNaYHj1h8PFONyVtWsdXF93hnRaNfuOhtSvjCTl1aIwfgxV

