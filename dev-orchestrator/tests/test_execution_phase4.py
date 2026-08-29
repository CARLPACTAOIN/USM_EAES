import json
import subprocess

from typer.testing import CliRunner

from dev_orchestrator.artifacts import task_run_dir, write_json_artifact, write_run_checkpoint, write_text_artifact
from dev_orchestrator.cli import app
from dev_orchestrator.config import OrchestratorConfig
from dev_orchestrator.models.execution import PatchProposal
from dev_orchestrator.nodes.executor import executor_node
from dev_orchestrator.tools.patches import apply_patch_proposal


def _repo(tmp_path):
    (tmp_path / ".git").mkdir()
    (tmp_path / "AGENTS.md").write_text("Agent rules", encoding="utf-8")
    (tmp_path / ".agents" / "skills").mkdir(parents=True)
    (tmp_path / "PRD_V1.md").write_text("# PRD", encoding="utf-8")
    (tmp_path / "PRD_PROGRESS_AUDIT.md").write_text("# Audit", encoding="utf-8")
    return tmp_path


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


def test_mock_model_creates_patch_proposal_without_mutating_files(tmp_path, monkeypatch) -> None:
    repo = _repo(tmp_path)
    run_dir = tmp_path / ".runs" / "task-1"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "mock")

    result = executor_node(
        {
            "task": "Rename dashboard label",
            "task_id": "task-1",
            "execute": True,
            "repo_root": str(repo),
            "run_dir": str(run_dir),
            "assessment": {"recommended_model_tier": "cheap"},
            "context_packet": {"selected_files": ["README.md"]},
            "repository_context": {"baseline_changed_files": [], "target_paths": ["."]},
        },
        OrchestratorConfig.from_env(repo),
    )

    assert result["status"] == "patch_approval_required"
    assert (run_dir / "proposal.json").exists()
    assert (run_dir / "proposal.patch").exists()
    assert result["changed_files"] == []


def test_task_execute_saves_proposal_and_checkpoint(tmp_path, monkeypatch) -> None:
    repo = _repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "mock")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))

    result = CliRunner().invoke(app, ["task", "Rename dashboard label", "--repo", str(repo), "--execute", "--json"])

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    run_dir = task_run_dir(runs_dir, payload["task_id"])
    assert payload["final_status"] == "patch_approval_required"
    assert (run_dir / "proposal.json").exists()
    assert (run_dir / "proposal.patch").exists()
    assert (run_dir / "state.json").exists()


def test_critical_task_pauses_then_resume_checkpoint_creates_proposal(tmp_path, monkeypatch) -> None:
    repo = _repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    runner = CliRunner()
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "mock")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))

    first = runner.invoke(
        app,
        ["task", "Run a destructive migration that may drop table data", "--repo", str(repo), "--execute", "--json"],
    )
    assert first.exit_code == 0
    first_payload = json.loads(first.stdout)
    assert first_payload["final_status"] == "awaiting_human"

    resumed = runner.invoke(app, ["resume", first_payload["task_id"], "--repo", str(repo), "--approve-checkpoint", "--json"])
    assert resumed.exit_code == 0
    resumed_payload = json.loads(resumed.stdout)
    assert resumed_payload["final_status"] == "patch_approval_required"
    assert (task_run_dir(runs_dir, first_payload["task_id"]) / "proposal.json").exists()


def test_resume_approve_patch_applies_patch_and_verifies(tmp_path, monkeypatch) -> None:
    repo = _git_repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    task_id = "task-apply"
    run_dir = task_run_dir(runs_dir, task_id)
    proposal = PatchProposal(
        patch_id="patch-1",
        summary="Change sample text.",
        affected_files=["sample.txt"],
        unified_diff="diff --git a/sample.txt b/sample.txt\nindex 641e9fb..b66ba06 100644\n--- a/sample.txt\n+++ b/sample.txt\n@@ -1 +1 @@\n-before\n+after\n",
        verification_plan=["git diff --check"],
    )
    state = {
        "task": "Change sample text",
        "task_id": task_id,
        "execute": True,
        "repo_root": str(repo),
        "run_dir": str(run_dir),
        "assessment": {"needs_tests": True, "task_type": "implementation", "complexity": "simple"},
        "repository_context": {"baseline_changed_files": []},
        "context_packet": {"agents": [{"source": "AGENTS.md"}], "selected_files": ["sample.txt"]},
        "patch_proposal": proposal.model_dump(mode="json"),
    }
    write_json_artifact(run_dir, "proposal.json", proposal.model_dump(mode="json"))
    write_text_artifact(run_dir, "proposal.patch", proposal.unified_diff)
    write_run_checkpoint(run_dir, task_id, "patch_pending", state, pending_approvals=["patch"])
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))

    result = CliRunner().invoke(app, ["resume", task_id, "--repo", str(repo), "--approve-patch", "--json"])

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["patch_apply_result"]["applied"] is True
    assert (repo / "sample.txt").read_text(encoding="utf-8") == "after\n"
    assert (run_dir / "verification.json").exists()


def test_invalid_patch_fails_safely(tmp_path) -> None:
    repo = _git_repo(tmp_path)
    run_dir = tmp_path / ".runs" / "task-invalid"
    proposal = PatchProposal(patch_id="patch-bad", summary="Bad patch", affected_files=["sample.txt"], unified_diff="bad")
    write_text_artifact(run_dir, "proposal.patch", proposal.unified_diff)

    result = apply_patch_proposal(repo, run_dir, run_dir / "proposal.patch", proposal, [])

    assert result.applied is False
    assert result.failure_reason == "git apply --check failed."


def test_new_unrelated_dirty_files_block_patch_apply(tmp_path) -> None:
    repo = _git_repo(tmp_path)
    run_dir = tmp_path / ".runs" / "task-dirty"
    proposal = PatchProposal(
        patch_id="patch-1",
        summary="Change sample text.",
        affected_files=["sample.txt"],
        unified_diff="diff --git a/sample.txt b/sample.txt\nindex 641e9fb..b66ba06 100644\n--- a/sample.txt\n+++ b/sample.txt\n@@ -1 +1 @@\n-before\n+after\n",
    )
    write_text_artifact(run_dir, "proposal.patch", proposal.unified_diff)
    (repo / "other.txt").write_text("new\n", encoding="utf-8")

    result = apply_patch_proposal(repo, run_dir, run_dir / "proposal.patch", proposal, [])

    assert result.applied is False
    assert "New unrelated dirty files" in result.failure_reason


def test_rejected_patch_records_escalation(tmp_path, monkeypatch) -> None:
    repo = _repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    task_id = "task-reject"
    state = {
        "task": "Change sample text",
        "task_id": task_id,
        "execute": True,
        "repo_root": str(repo),
        "status": "patch_approval_required",
        "final_status": "patch_approval_required",
    }
    write_run_checkpoint(task_run_dir(runs_dir, task_id), task_id, "patch_pending", state, pending_approvals=["patch"])
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))

    result = CliRunner().invoke(app, ["resume", task_id, "--repo", str(repo), "--reject", "not safe", "--json"])

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["final_status"] == "rejected"
    assert payload["human_escalations"][0]["reason"] == "not safe"


def test_provider_unavailable_writes_agent_prompt(tmp_path, monkeypatch) -> None:
    repo = _repo(tmp_path)
    runs_dir = tmp_path / ".runs"
    monkeypatch.setenv("DEV_ORCH_PROVIDER", "codex")
    monkeypatch.setenv("DEV_ORCH_CODEX_COMMAND", "definitely-missing-codex-command")
    monkeypatch.setenv("DEV_ORCH_RUNS_DIR", str(runs_dir))

    result = CliRunner().invoke(app, ["task", "Rename dashboard label", "--repo", str(repo), "--execute", "--json"])

    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    run_dir = task_run_dir(runs_dir, payload["task_id"])
    assert payload["final_status"] == "proposal_pending"
    assert (run_dir / "agent_prompt.md").exists()
