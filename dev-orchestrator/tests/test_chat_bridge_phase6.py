import json
import subprocess

from typer.testing import CliRunner

from dev_orchestrator.artifacts import task_run_dir
from dev_orchestrator.chat_bridge import continue_chat_task, parse_chat_approval, start_chat_task
from dev_orchestrator.cli import app
from dev_orchestrator.config import OrchestratorConfig
from dev_orchestrator.models.chat import ChatApprovalDecisionType, ChatApprovalTarget, ChatRunRequest


REAL_SUBPROCESS_RUN = subprocess.run


def _git_repo(tmp_path):
    repo = tmp_path
    subprocess.run(["git", "init"], cwd=repo, check=True, capture_output=True, text=True)
    (repo / "AGENTS.md").write_text("Agent rules", encoding="utf-8")
    (repo / ".agents" / "skills").mkdir(parents=True)
    (repo / "PRD_V1.md").write_text("# PRD", encoding="utf-8")
    (repo / "PRD_PROGRESS_AUDIT.md").write_text("# Audit", encoding="utf-8")
    (repo / "sample.txt").write_text("before\n", encoding="utf-8")
    subprocess.run(["git", "add", "."], cwd=repo, check=True, capture_output=True, text=True)
    subprocess.run(
        ["git", "-c", "user.email=test@example.com", "-c", "user.name=Test", "commit", "-m", "initial"],
        cwd=repo,
        check=True,
        capture_output=True,
        text=True,
    )
    return repo


def _proposal_payload() -> dict[str, object]:
    return {
        "summary": "Change sample text.",
        "affected_files": ["sample.txt"],
        "unified_diff": (
            "diff --git a/sample.txt b/sample.txt\n"
            "index 641e9fb..b66ba06 100644\n"
            "--- a/sample.txt\n"
            "+++ b/sample.txt\n"
            "@@ -1 +1 @@\n"
            "-before\n"
            "+after\n"
        ),
        "verification_plan": ["git diff --check"],
        "risk_notes": [],
    }


def _codex_success_run(payload: dict[str, object]):
    def fake_run(command, **kwargs):
        if "--output-last-message" not in command:
            return REAL_SUBPROCESS_RUN(command, **kwargs)
        final_path = command[command.index("--output-last-message") + 1]
        with open(final_path, "w", encoding="utf-8") as handle:
            json.dump(payload, handle)
        return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

    return fake_run


def test_chat_start_low_risk_completes_with_mocked_codex(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(tmp_path / ".runs"))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")
    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))
    config = OrchestratorConfig.from_env(repo)

    summary = start_chat_task(repo, config, ChatRunRequest(task="Fix typo in sample text"))

    assert summary.final_status == "completed"
    assert summary.approval_required is None
    assert summary.changed_files == ["sample.txt"]
    assert (repo / "sample.txt").read_text(encoding="utf-8") == "after\n"


def test_chat_start_moderate_task_returns_patch_approval_card(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(tmp_path / ".runs"))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")
    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))
    config = OrchestratorConfig.from_env(repo)

    summary = start_chat_task(repo, config, ChatRunRequest(task="Refactor sample text workflow"))

    assert summary.final_status == "patch_approval_required"
    assert summary.approval_required is not None
    assert summary.approval_required.target == ChatApprovalTarget.Patch
    assert summary.approval_required.affected_files == ["sample.txt"]
    assert "patch" in summary.approval_required.proposal_paths


def test_chat_start_critical_task_returns_checkpoint_card_before_codex(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(tmp_path / ".runs"))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    def fail_if_codex(command, **kwargs):
        if command and command[0] == "codex":
            raise AssertionError("Codex should not run before checkpoint approval.")
        return REAL_SUBPROCESS_RUN(command, **kwargs)

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", fail_if_codex)
    config = OrchestratorConfig.from_env(repo)

    summary = start_chat_task(repo, config, ChatRunRequest(task="Run a destructive migration that may drop table data"))

    assert summary.final_status == "awaiting_human"
    assert summary.approval_required is not None
    assert summary.approval_required.target == ChatApprovalTarget.Checkpoint


def test_chat_text_approve_resumes_only_pending_patch(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(tmp_path / ".runs"))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")
    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))
    config = OrchestratorConfig.from_env(repo)
    pending = start_chat_task(repo, config, ChatRunRequest(task="Refactor sample text workflow"))

    decision = parse_chat_approval(pending.task_id, "approve", ["patch"])
    summary = continue_chat_task(repo, config, decision)

    assert summary.final_status == "completed"
    assert (repo / "sample.txt").read_text(encoding="utf-8") == "after\n"


def test_chat_text_approve_patch_parses_explicit_target() -> None:
    decision = parse_chat_approval("task-1", "approve patch", ["checkpoint", "patch"])

    assert decision.decision == ChatApprovalDecisionType.Approve
    assert decision.target == ChatApprovalTarget.Patch


def test_chat_text_reject_records_rejection(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(tmp_path / ".runs"))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")
    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))
    config = OrchestratorConfig.from_env(repo)
    pending = start_chat_task(repo, config, ChatRunRequest(task="Refactor sample text workflow"))

    decision = parse_chat_approval(pending.task_id, "reject not safe", ["patch"])
    summary = continue_chat_task(repo, config, decision)

    assert summary.final_status == "rejected"
    assert "not safe" in summary.short_message
    assert (repo / "sample.txt").read_text(encoding="utf-8") == "before\n"


def test_ambiguous_chat_approval_returns_clarification() -> None:
    decision = parse_chat_approval("task-1", "approve", ["checkpoint", "patch"])

    assert decision.decision == ChatApprovalDecisionType.NeedsClarification


def test_chat_cli_hooks_return_json_summary(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")
    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))
    runner = CliRunner()

    started = runner.invoke(
        app,
        ["chat-start", "Refactor sample text workflow", "--repo", str(repo), "--json"],
    )
    assert started.exit_code == 0
    start_payload = json.loads(started.stdout)
    assert start_payload["approval_required"]["target"] == "patch"

    approved = runner.invoke(
        app,
        ["chat-approve", start_payload["task_id"], "--repo", str(repo), "--target", "patch", "--json"],
    )
    assert approved.exit_code == 0
    approved_payload = json.loads(approved.stdout)
    assert approved_payload["final_status"] == "completed"
    assert (task_run_dir(runs_dir, start_payload["task_id"]) / "review.json").exists()
