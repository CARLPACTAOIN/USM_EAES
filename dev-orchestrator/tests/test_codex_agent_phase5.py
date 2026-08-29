import json
import subprocess

from typer.testing import CliRunner

from dev_orchestrator.artifacts import task_run_dir
from dev_orchestrator.cli import app
from dev_orchestrator.model_client import CodexCliClient
from dev_orchestrator.models.execution import ExecutionRequest


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
        return subprocess.CompletedProcess(command, 0, stdout='{"event":"done"}\n', stderr="")

    return fake_run


def test_codex_exec_success_creates_patch_proposal_without_prompt(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    run_dir = tmp_path / ".runs" / "task-codex"
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))

    proposal = CodexCliClient(run_dir, repo, timeout_seconds=5).create_patch_proposal(
        ExecutionRequest(task="Fix sample text", allowed_files=["sample.txt"], model_selection={"model": "GPT-5.5"})
    )

    assert proposal is not None
    assert proposal.affected_files == ["sample.txt"]
    assert not (run_dir / "agent_prompt.md").exists()
    assert json.loads((run_dir / "agent_result.json").read_text(encoding="utf-8"))["parse_status"] == "success"


def test_low_risk_codex_patch_auto_applies_and_verifies(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "codex")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))

    result = CliRunner().invoke(app, ["task", "Fix typo in sample text", "--repo", str(repo), "--execute", "--json"])

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["final_status"] == "completed"
    assert payload["patch_apply_result"]["applied"] is True
    assert payload["auto_apply_decision"]["applied"] is True
    assert (repo / "sample.txt").read_text(encoding="utf-8") == "after\n"
    assert (task_run_dir(runs_dir, payload["task_id"]) / "verification.json").exists()


def test_no_auto_apply_leaves_valid_patch_pending(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "codex")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))

    result = CliRunner().invoke(
        app,
        ["task", "Fix typo in sample text", "--repo", str(repo), "--execute", "--no-auto-apply", "--json"],
    )

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["final_status"] == "patch_approval_required"
    assert payload["auto_apply_decision"]["reason"] == "Auto-apply disabled by CLI option."
    assert (repo / "sample.txt").read_text(encoding="utf-8") == "before\n"


def test_moderate_risk_codex_patch_requires_approval(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "codex")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(_proposal_payload()))

    result = CliRunner().invoke(
        app,
        ["task", "Refactor sample text workflow", "--repo", str(repo), "--execute", "--json"],
    )

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["final_status"] == "patch_approval_required"
    assert payload["auto_apply_decision"]["reason"] == "Risk is medium; approval required."
    assert (repo / "sample.txt").read_text(encoding="utf-8") == "before\n"


def test_codex_missing_command_falls_back_to_prompt(tmp_path) -> None:
    repo = _git_repo(tmp_path)
    run_dir = tmp_path / ".runs" / "task-missing"

    proposal = CodexCliClient(run_dir, repo, codex_command="missing-codex-command").create_patch_proposal(
        ExecutionRequest(task="Fix sample text", allowed_files=["sample.txt"])
    )

    assert proposal is None
    assert (run_dir / "agent_prompt.md").exists()
    result = json.loads((run_dir / "agent_result.json").read_text(encoding="utf-8"))
    assert result["parse_status"] == "missing_command"


def test_codex_invalid_json_records_failure_and_does_not_apply(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "codex")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    def fake_run(command, **kwargs):
        if "--output-last-message" not in command:
            return REAL_SUBPROCESS_RUN(command, **kwargs)
        final_path = command[command.index("--output-last-message") + 1]
        with open(final_path, "w", encoding="utf-8") as handle:
            handle.write("not json")
        return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", fake_run)

    result = CliRunner().invoke(app, ["task", "Fix typo in sample text", "--repo", str(repo), "--execute", "--json"])

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["final_status"] == "proposal_pending"
    assert payload["agent_invocation"]["parse_status"] == "invalid_json"
    assert (repo / "sample.txt").read_text(encoding="utf-8") == "before\n"


def test_codex_nonzero_exit_records_failure(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    run_dir = tmp_path / ".runs" / "task-nonzero"
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    def fake_run(command, **kwargs):
        return subprocess.CompletedProcess(command, 1, stdout="", stderr="not authenticated")

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", fake_run)

    proposal = CodexCliClient(run_dir, repo).create_patch_proposal(ExecutionRequest(task="Fix sample text"))

    assert proposal is None
    result = json.loads((run_dir / "agent_result.json").read_text(encoding="utf-8"))
    assert result["parse_status"] == "nonzero_exit"
    assert (run_dir / "agent_prompt.md").exists()


def test_codex_timeout_records_failure(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    run_dir = tmp_path / ".runs" / "task-timeout"
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    def fake_run(command, **kwargs):
        raise subprocess.TimeoutExpired(command, 1, output=b"partial", stderr=b"late")

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", fake_run)

    proposal = CodexCliClient(run_dir, repo, timeout_seconds=1).create_patch_proposal(
        ExecutionRequest(task="Fix sample text")
    )

    assert proposal is None
    result = json.loads((run_dir / "agent_result.json").read_text(encoding="utf-8"))
    assert result["parse_status"] == "timeout"
    assert result["timed_out"] is True


def test_critical_task_requires_checkpoint_before_codex_invocation(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "codex")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")

    def fail_if_called(command, **kwargs):
        if command and command[0] == "codex":
            raise AssertionError("Codex should not run before checkpoint approval.")
        return REAL_SUBPROCESS_RUN(command, **kwargs)

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", fail_if_called)

    result = CliRunner().invoke(
        app,
        ["task", "Run a destructive migration that may drop table data", "--repo", str(repo), "--execute", "--json"],
    )

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["final_status"] == "awaiting_human"
    assert payload["pending_approvals"] == ["checkpoint"]
    assert not (task_run_dir(runs_dir, payload["task_id"]) / "agent_result.json").exists()


def test_proposal_touching_disallowed_file_requires_human(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "codex")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))
    monkeypatch.setattr("dev_orchestrator.model_client.shutil.which", lambda _command: "codex")
    payload = _proposal_payload()
    payload["affected_files"] = ["outside.txt"]

    monkeypatch.setattr("dev_orchestrator.model_client.subprocess.run", _codex_success_run(payload))

    result = CliRunner().invoke(
        app,
        ["task", "Update orchestrator agent prompt", "--repo", str(repo), "--execute", "--json"],
    )

    assert result.exit_code == 0
    data = json.loads(result.stdout)
    assert data["final_status"] == "patch_approval_required"
    assert data["human_action_required"] is True
    assert data["human_escalations"][0]["triggers"][0] == "disallowed_patch_files"
