#!/usr/bin/env python3
"""Dispatch routing in deploy.yml (#1092): python3 deploy/tests/deploy-dispatch-route-test.py

Runs the real `route` step script from the workflow against this checkout, so
the test exercises the same bash and the same registry files CI would.
Requires PyYAML, bash and jq.
"""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = yaml.safe_load((ROOT / ".github/workflows/deploy.yml").read_text())


def route_script():
    steps = WORKFLOW["jobs"]["route"]["steps"]
    return next(s for s in steps if s.get("id") == "route")["run"]


def run_route(event, component="", version="", repository=""):
    with tempfile.TemporaryDirectory() as tmp:
        output = Path(tmp) / "output"
        summary = Path(tmp) / "summary"
        output.touch()
        summary.touch()
        env = dict(
            os.environ,
            EVENT=event,
            D_COMPONENT=component,
            D_VERSION=version,
            D_REPOSITORY=repository,
            GITHUB_OUTPUT=str(output),
            GITHUB_STEP_SUMMARY=str(summary),
        )
        proc = subprocess.run(
            ["bash", "-c", route_script()], cwd=ROOT, env=env, capture_output=True, text=True
        )
        outputs = dict(l.split("=", 1) for l in output.read_text().splitlines() if "=" in l)
        return proc, outputs, summary.read_text()


def registered():
    return sorted(p.stem for p in (ROOT / "deploy/homeboy/components").glob("*.json"))


def monorepo_members():
    data = json.loads((ROOT / "deploy/homeboy/projects/extrachill-monorepo/extrachill-monorepo.json").read_text())
    return [c["id"] for c in data["components"]]


class DispatchRouteTest(unittest.TestCase):
    def test_deploy_job_is_gated_on_route(self):
        deploy = WORKFLOW["jobs"]["deploy"]
        self.assertEqual(deploy["needs"], "route")
        self.assertEqual(deploy["if"], "needs.route.outputs.deploy == 'true'")

    def test_registered_dispatch_deploys(self):
        for component in registered():
            proc, out, _ = run_route("repository_dispatch", component, "1.2.3", "Extra-Chill/x")
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(out.get("deploy"), "true", component)

    def test_unregistered_dispatch_is_a_logged_noop(self):
        proc, out, summary = run_route("repository_dispatch", "wp-native-shell", "0.4.0", "Extra-Chill/wp-native")
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertEqual(out.get("deploy"), "false")
        self.assertIn("::notice::Skipping dispatched wp-native-shell 0.4.0 from Extra-Chill/wp-native", proc.stdout)
        self.assertIn("not a registered deployable", proc.stdout)
        self.assertIn("Dispatch ignored", summary)

    def test_monorepo_member_dispatch_is_left_to_deploy_monorepo(self):
        members = monorepo_members()
        self.assertTrue(members)
        for component in members:
            proc, out, _ = run_route("repository_dispatch", component, "1.0.0", "Extra-Chill/extrachill")
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(out.get("deploy"), "false", component)
            self.assertIn("deployed by deploy-monorepo.yml", proc.stdout)

    def test_malformed_dispatch_reaches_plan_and_fails_there(self):
        # Missing or invalid ids are not silently dropped: Plan rejects them.
        for component in ("", "../etc", "Bad_Id"):
            proc, out, _ = run_route("repository_dispatch", component, "1.0.0")
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(out.get("deploy"), "true", repr(component))

    def test_other_events_always_deploy(self):
        # Manual runs naming an unknown component must still fail loudly in Plan.
        for event in ("schedule", "workflow_dispatch"):
            proc, out, _ = run_route(event, "wp-native-shell", "0.4.0")
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(out.get("deploy"), "true", event)

    def test_plan_still_rejects_unregistered_components(self):
        plan = next(s for s in WORKFLOW["jobs"]["deploy"]["steps"] if s.get("id") == "plan")["run"]
        self.assertIn('test -f "deploy/homeboy/components/${component}.json" || {', plan)


if __name__ == "__main__":
    unittest.main()
