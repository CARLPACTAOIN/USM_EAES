from __future__ import annotations

import json
import shutil
import subprocess
import uuid
from pathlib import Path
from typing import Protocol

from pydantic import ValidationError

from dev_orchestrator.artifacts import read_json_artifact, write_json_artifact, write_text_artifact
from dev_orchestrator.models.execution import AgentInvocationResult, ExecutionRequest, PatchProposal


class ModelClient(Protocol):
    def create_patch_proposal(self, request: ExecutionRequest) -> PatchProposal | None:
        ...


class MockModelClient:
    def __init__(self, run_dir: Path, proposal: PatchProposal | None = None) -> None:
        self.run_dir = run_dir
        self.proposal = proposal

    def create_patch_proposal(self, request: ExecutionRequest) -> PatchProposal | None:
        proposal = self.proposal or PatchProposal(
            patch_id=f"patch-{uuid.uuid4().hex[:12]}",
            summary=f"Mock patch proposal for: {request.task}",
            affected_files=[],
            unified_diff="",
            verification_plan=request.plan or ["Run selected deterministic verification."],
            risk_notes=["Mock proposal contains no source changes."],
            requires_approval=True,
        )
        _write_agent_result(
            self.run_dir,
            AgentInvocationResult(provider="mock", parse_status="success"),
        )
        return proposal


class CodexCliClient:
    def __init__(
        self,
        run_dir: Path,
        repo_root: Path,
        codex_command: str = "codex",
        timeout_seconds: int = 600,
    ) -> None:
        self.run_dir = run_dir
        self.repo_root = repo_root
        self.codex_command = codex_command
        self.timeout_seconds = timeout_seconds

    def create_patch_proposal(self, request: ExecutionRequest) -> PatchProposal | None:
        codex_path = _resolve_command(self.codex_command)
        if codex_path is None:
            return _fallback_prompt(
                self.run_dir,
                request,
                AgentInvocationResult(
                    provider="codex",
                    command=[self.codex_command],
                    parse_status="missing_command",
                    error="Codex command was not found.",
                ),
            )

        schema_path = write_json_artifact(self.run_dir, "codex_output_schema.json", _proposal_output_schema())
        final_message_path = self.run_dir / "codex_final_message.json"
        command = [
            codex_path,
            "exec",
            "--cd",
            str(self.repo_root),
            "--sandbox",
            "read-only",
            "--ask-for-approval",
            "never",
            "--model",
            str(request.model_selection.get("model", "")),
            "--output-schema",
            str(schema_path),
            "--output-last-message",
            str(final_message_path),
            "--json",
            "-",
        ]
        if not request.model_selection.get("model"):
            command = [part for part in command if part != "--model" and part != ""]

        prompt = _render_prompt(request)
        try:
            completed = subprocess.run(
                command,
                cwd=str(self.repo_root),
                input=prompt,
                capture_output=True,
                text=True,
                timeout=self.timeout_seconds,
                check=False,
            )
        except subprocess.TimeoutExpired as exc:
            write_text_artifact(self.run_dir, "codex_stdout.jsonl", _as_text(exc.stdout))
            write_text_artifact(self.run_dir, "codex_stderr.txt", _as_text(exc.stderr))
            return _fallback_prompt(
                self.run_dir,
                request,
                AgentInvocationResult(
                    provider="codex",
                    command=command,
                    stdout_path="codex_stdout.jsonl",
                    stderr_path="codex_stderr.txt",
                    final_message_path="codex_final_message.json",
                    schema_path="codex_output_schema.json",
                    timed_out=True,
                    parse_status="timeout",
                    error=f"Codex timed out after {self.timeout_seconds} seconds.",
                ),
            )

        write_text_artifact(self.run_dir, "codex_stdout.jsonl", completed.stdout)
        write_text_artifact(self.run_dir, "codex_stderr.txt", completed.stderr)
        if completed.returncode != 0:
            return _fallback_prompt(
                self.run_dir,
                request,
                AgentInvocationResult(
                    provider="codex",
                    command=command,
                    exit_code=completed.returncode,
                    stdout_path="codex_stdout.jsonl",
                    stderr_path="codex_stderr.txt",
                    final_message_path="codex_final_message.json",
                    schema_path="codex_output_schema.json",
                    parse_status="nonzero_exit",
                    error="Codex exited with a non-zero status.",
                ),
            )

        try:
            proposal = _read_proposal(final_message_path)
        except (OSError, json.JSONDecodeError, ValidationError) as exc:
            return _fallback_prompt(
                self.run_dir,
                request,
                AgentInvocationResult(
                    provider="codex",
                    command=command,
                    exit_code=completed.returncode,
                    stdout_path="codex_stdout.jsonl",
                    stderr_path="codex_stderr.txt",
                    final_message_path="codex_final_message.json",
                    schema_path="codex_output_schema.json",
                    parse_status="invalid_json",
                    error=str(exc),
                ),
            )

        _write_agent_result(
            self.run_dir,
            AgentInvocationResult(
                provider="codex",
                command=command,
                exit_code=completed.returncode,
                stdout_path="codex_stdout.jsonl",
                stderr_path="codex_stderr.txt",
                final_message_path="codex_final_message.json",
                schema_path="codex_output_schema.json",
                parse_status="success",
            ),
        )
        return proposal


class ExternalPromptClient:
    def __init__(self, run_dir: Path) -> None:
        self.run_dir = run_dir

    def create_patch_proposal(self, request: ExecutionRequest) -> PatchProposal | None:
        return _fallback_prompt(
            self.run_dir,
            request,
            AgentInvocationResult(provider="prompt", parse_status="fallback_prompt"),
        )


def model_client_for_provider(
    provider: str,
    run_dir: Path,
    repo_root: Path | None = None,
    timeout_seconds: int = 600,
    codex_command: str = "codex",
) -> ModelClient:
    normalized = provider.lower()
    if normalized == "mock":
        return MockModelClient(run_dir)
    if normalized == "codex":
        return CodexCliClient(
            run_dir=run_dir,
            repo_root=repo_root or Path.cwd(),
            codex_command=codex_command,
            timeout_seconds=timeout_seconds,
        )
    return ExternalPromptClient(run_dir)


def _resolve_command(command: str) -> str | None:
    path = Path(command)
    if path.exists():
        return str(path)
    return shutil.which(command)


def _as_text(value: object) -> str:
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode("utf-8", errors="replace")
    return str(value)


def _read_proposal(final_message_path: Path) -> PatchProposal:
    data = json.loads(final_message_path.read_text(encoding="utf-8"))
    if "patch_id" not in data:
        data["patch_id"] = f"patch-{uuid.uuid4().hex[:12]}"
    if "requires_approval" not in data:
        data["requires_approval"] = True
    return PatchProposal.model_validate(data)


def _fallback_prompt(
    run_dir: Path,
    request: ExecutionRequest,
    result: AgentInvocationResult,
) -> None:
    write_text_artifact(run_dir, "agent_prompt.md", _render_prompt(request))
    _write_agent_result(run_dir, result)
    return None


def _write_agent_result(run_dir: Path, result: AgentInvocationResult) -> None:
    write_json_artifact(run_dir, "agent_result.json", result.model_dump(mode="json"))


def _proposal_output_schema() -> dict[str, object]:
    return {
        "type": "object",
        "additionalProperties": False,
        "required": ["summary", "affected_files", "unified_diff", "verification_plan", "risk_notes"],
        "properties": {
            "summary": {"type": "string"},
            "affected_files": {"type": "array", "items": {"type": "string"}},
            "unified_diff": {"type": "string"},
            "verification_plan": {"type": "array", "items": {"type": "string"}},
            "risk_notes": {"type": "array", "items": {"type": "string"}},
        },
    }


def _render_prompt(request: ExecutionRequest) -> str:
    return "\n".join(
        [
            "# Development Patch Proposal Request",
            "",
            "Return only JSON matching the supplied output schema.",
            "Generate a unified diff only. Do not mutate files, run write commands, include secrets, or output prose.",
            "The diff must touch only the allowed files unless a risk note explains why a broader change is needed.",
            "",
            f"## Task\n{request.task}",
            "",
            f"## Assessment\n```json\n{json.dumps(request.assessment, indent=2, sort_keys=True)}\n```",
            "",
            f"## Allowed Files\n{chr(10).join(request.allowed_files) or '(none selected)'}",
            "",
            f"## Plan\n{chr(10).join(request.plan) or '(planner skipped)'}",
            "",
            f"## Baseline Dirty Files\n{chr(10).join(request.baseline_dirty_files) or '(clean baseline)'}",
        ]
    )
