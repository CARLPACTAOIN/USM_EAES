from dev_orchestrator.graph.routing import route_after_verify
from dev_orchestrator.nodes.repair import collect_verification_failures, diagnose_node, repair_node
from dev_orchestrator.nodes.verifier import verifier_node
from dev_orchestrator.nodes.verifier import select_verification_commands


def test_verifier_selects_laravel_tests_and_pint_for_php_changes() -> None:
    commands = select_verification_commands(
        ["Web/app/Http/Controllers/Api/AttendanceSyncController.php"],
        {"needs_tests": True},
    )

    command_strings = [" ".join(command) for command, _cwd in commands]
    assert "php artisan test --compact" in command_strings
    assert "vendor/bin/pint --dirty --format agent" in command_strings


def test_verifier_selects_flutter_checks_for_application_changes() -> None:
    commands = select_verification_commands(
        ["Application/lib/features/scanner/presentation/scanner_controller.dart"],
        {"needs_tests": True},
    )

    command_strings = [" ".join(command) for command, _cwd in commands]
    assert "flutter analyze" in command_strings
    assert "flutter test" in command_strings


def test_failing_verification_routes_to_diagnose() -> None:
    route = route_after_verify({"verification_results": [{"exit_code": 1}]})

    assert route == "diagnose"


def test_three_failed_repairs_stop_and_escalate() -> None:
    state = {
        "attempt_count": 2,
        "max_repair_attempts": 3,
        "verification_failures": [{"command": "php artisan test --compact", "failure_category": "phpunit"}],
    }

    result = repair_node(state)

    assert result["attempt_count"] == 3
    assert result["human_action_required"] is True
    assert result["status"] == "blocked"
    assert result["final_status"] == "blocked"
    assert result["human_escalations"][0]["reason"] == "Stopped after 3 failed repair attempts."


def test_failing_phpunit_result_becomes_verification_failure() -> None:
    failures = collect_verification_failures(
        [
            {
                "command": "php artisan test --compact",
                "cwd": "Web",
                "exit_code": 1,
                "stdout": "Tests failed",
                "stderr": "Expected true to be false",
            }
        ]
    )

    assert len(failures) == 1
    assert failures[0].failure_category == "phpunit"
    assert failures[0].summary.startswith("php artisan test --compact failed")


def test_diagnose_records_structured_verification_failures() -> None:
    result = diagnose_node(
        {
            "verification_results": [
                {
                    "command": "npm run build",
                    "cwd": "Web",
                    "exit_code": 1,
                    "stderr": "Build error",
                }
            ]
        }
    )

    assert result["verification_failures"][0]["failure_category"] == "frontend_build"
    assert "1 command" in result["review_findings"][0]


def test_repair_retries_until_later_verification_passes(tmp_path) -> None:
    state = {
        "repo_root": str(tmp_path),
        "execute": True,
        "changed_files": [],
        "assessment": {"needs_tests": True},
        "mock_verification_result_batches": [
            [{"command": "git diff --check", "cwd": ".", "exit_code": 1, "stderr": "Whitespace"}],
            [{"command": "git diff --check", "cwd": ".", "exit_code": 0, "stdout": "ok"}],
        ],
    }

    first_verify = verifier_node(state)
    diagnosed = diagnose_node({**state, **first_verify})
    repaired = repair_node({**state, **first_verify, **diagnosed, "max_repair_attempts": 3})
    second_verify = verifier_node({**state, **first_verify, **diagnosed, **repaired})

    assert repaired["status"] == "repair_ready"
    assert second_verify["verification_results"][0]["exit_code"] == 0
    assert second_verify["verification_failures"] == []
    assert second_verify["final_status"] == "completed"
