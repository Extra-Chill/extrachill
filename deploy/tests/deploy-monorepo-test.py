#!/usr/bin/env python3
"""Targeted tests: python3 deploy/tests/deploy-monorepo-test.py (requires PyYAML)."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("prepare", ROOT / "deploy/prepare-monorepo.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class MonorepoDeployTest(unittest.TestCase):
    def prepare(self, entries, dry=True):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        destination = Path(temporary.name) / "config"
        result = module.prepare(ROOT, destination, {"include": entries}, "a" * 40, dry)
        return result, destination

    def test_checkout_registry_and_commands(self):
        original = (ROOT / "deploy/homeboy/projects/extrachill-monorepo/extrachill-monorepo.json").read_bytes()
        result, destination = self.prepare([
            {"component": "extrachill-api", "path": "plugins/extrachill-api"},
            {"component": "extrachill-components", "path": "packages/components"},
            {"component": "extrachill-network", "path": "plugins/extrachill-network"},
        ])
        self.assertEqual(result["ignored"], ["extrachill-components"])
        self.assertEqual(len(result["commands"]), 2)
        self.assertTrue(all("--ref " + "a" * 40 in c and c.endswith("--dry-run") for c in result["commands"]))
        config = json.loads((destination / "components/extrachill-api.json").read_text())
        self.assertNotIn("remote_url", config)
        self.assertEqual(config["local_path"], str(ROOT / "plugins/extrachill-api"))
        self.assertEqual(config["remote_path"], "wp-content/plugins/extrachill-api")
        project = json.loads((destination / "projects/extrachill-monorepo/extrachill-monorepo.json").read_text())
        self.assertEqual([c["id"] for c in project["components"]], result["components"])
        self.assertTrue(all("--project extrachill-monorepo" in c for c in result["commands"]))
        self.assertEqual(original, (ROOT / "deploy/homeboy/projects/extrachill-monorepo/extrachill-monorepo.json").read_bytes())

    def test_projects_partition_members_from_the_release_poller(self):
        load = lambda p: {c["id"] for c in json.loads((ROOT / p).read_text())["components"]}
        poller = load("deploy/homeboy/projects/extrachill-site/extrachill-site.json")
        monorepo = load("deploy/homeboy/projects/extrachill-monorepo/extrachill-monorepo.json")
        members = {json.loads(m.read_text())["id"] for m in (ROOT / "plugins").glob("*/homeboy.json")}
        members |= {json.loads(m.read_text())["id"] for m in (ROOT / "themes").glob("*/homeboy.json")}
        self.assertEqual(monorepo, members)
        self.assertFalse(poller & members, "the release poller must never deploy a monorepo member")
        registry = {p.stem for p in (ROOT / "deploy/homeboy/components").glob("*.json")}
        self.assertEqual(registry, poller)

    def test_empty_and_live(self):
        self.assertEqual(self.prepare([])[0]["commands"], [])
        commands = self.prepare([{"component": "extrachill-network", "path": "plugins/extrachill-network"}], False)[0]["commands"]
        self.assertNotIn("--dry-run", commands[0])

    def test_bad_matrix_fails_closed(self):
        for entry in [
            {"component": "extrachill-api", "path": ".."},
            {"component": "extrachill-api", "path": "."},
            {"component": "bad;command", "path": "."},
        ]:
            with self.assertRaises(ValueError):
                self.prepare([entry])

    def test_deploy_builds_from_homeboy_exact_ref_tree(self):
        # Homeboy builds --ref deploys in place only when the whole checkout is
        # clean, otherwise from a fresh full tree, so gate output needs no reset.
        workflow = yaml.safe_load((ROOT / ".github/workflows/deploy-monorepo.yml").read_text())
        names = {s.get("name"): s for s in workflow["jobs"]["deploy"]["steps"]}
        self.assertNotIn("Restore pristine checkout for deploy", names)
        self.assertEqual(names["Deploy"]["with"]["commands"], "${{ steps.plan.outputs.command }}")
        self.assertEqual(names["Verify"]["with"]["commands"], "deploy extrachill-monorepo --check")

    def test_workflow_contract(self):
        workflow = yaml.safe_load((ROOT / ".github/workflows/deploy-monorepo.yml").read_text())
        legacy = yaml.safe_load((ROOT / ".github/workflows/deploy.yml").read_text())
        self.assertEqual(workflow["concurrency"], legacy["concurrency"])
        self.assertEqual(workflow["concurrency"], {"group": "deploy-main", "cancel-in-progress": False})
        steps = workflow["jobs"]["deploy"]["steps"]
        names = {s.get("name"): s for s in steps}
        for name in ["Deploy", "Verify"]:
            self.assertIn("dry_run == 'false'", names[name]["if"])
            self.assertEqual(names[name]["uses"], "Extra-Chill/homeboy-action@v2")
            self.assertIn("ssh-key", names[name]["with"])
        self.assertNotIn("ssh-key", names["Provision Homeboy and WP Codebox for the gate"]["with"])
        self.assertIn("changed_count != '0'", names["Network rig gate"]["if"])
        self.assertIn("extrachill_monorepo_source", names["Plan"]["run"])
        self.assertIn("MONOREPO_DEPLOY_AUTOMATION", names["Plan"]["env"]["AUTOMATION"])
        self.assertIn("--path . --format matrix --direct-only", names["Plan"]["run"])
        # Workflow edits change no deployed bytes, so they select no components.
        self.assertNotIn("--shared-path", names["Plan"]["run"])
        # Every Homeboy pin is the same release (detection, rig gate, deploy, verify).
        text = (ROOT / ".github/workflows/deploy-monorepo.yml").read_text()
        self.assertEqual(workflow["env"]["HOMEBOY_VERSION"], "0.440.0")
        self.assertEqual(workflow["env"]["HOMEBOY_SHA256"],
                         "2abfe4c73ee5d560b1da8f68ae15426eb2d4d786ceff1af078cdeb54dc48109e")
        actions = [s for s in steps if s.get("uses") == "Extra-Chill/homeboy-action@v2"]
        self.assertEqual(len(actions), 3)
        for step in actions:
            self.assertEqual(step["with"]["version"], "${{ env.HOMEBOY_VERSION }}")
            self.assertEqual(step["with"]["extension-ref"],
                             "${{ format('v{0}', env.HOMEBOY_VERSION) }}")
        gate = names["Prepare pinned Homeboy binary for the gate"]["run"]
        self.assertIn("releases/download/v${HOMEBOY_VERSION}/", gate)
        self.assertIn('"${HOMEBOY_SHA256}"', gate)
        self.assertIn("sha256sum --check --strict", gate)
        self.assertIn("homeboy-${HOMEBOY_VERSION}/homeboy", gate)
        extensions = names["Install deployed components' extensions"]["run"]
        self.assertIn("--source https://github.com/Extra-Chill/homeboy \\\n", extensions)
        self.assertIn('--ref "v${HOMEBOY_VERSION}"', extensions)
        self.assertNotIn("homeboy-extensions", text)
        self.assertIn('join(",")', names["Plan"]["run"])
        # #485: automatic deploys follow Release and plan from the recorded deployed sha.
        triggers = workflow[True] if True in workflow else workflow["on"]
        self.assertNotIn("push", triggers)
        self.assertEqual(triggers["workflow_run"],
                         {"workflows": ["Release"], "types": ["completed"], "branches": ["main"]})
        # #486: recovery runs must not compete at boundaries or poller minutes.
        self.assertTrue(triggers["schedule"])
        for schedule in triggers["schedule"]:
            minutes, *fields = schedule["cron"].split()
            self.assertEqual(fields, ["*", "*", "*", "*"])
            minute_values = {int(minute) for minute in minutes.split(",")}
            self.assertTrue(minute_values)
            self.assertTrue(all(0 <= minute < 60 for minute in minute_values))
            self.assertFalse(minute_values & {0, 30, 7, 37})
        checkout = steps[0]
        self.assertEqual(checkout["with"]["ref"], "${{ inputs.sha || 'main' }}")
        plan = names["Plan"]["run"]
        self.assertIn("artifacts?name=monorepo-deployed-sha", plan)
        self.assertIn('if [ "${EVENT}" != workflow_dispatch ]; then', plan)
        self.assertIn('echo "base=${base}"', plan)
        # A recovery run never deploys ahead of an unfinished Release.
        self.assertIn('if [ "${EVENT}" = schedule ]; then', plan)
        self.assertIn("workflows/release.yml/runs?branch=main", plan)
        self.assertIn('select(.status != "completed")', plan)
        # Healthy scheduled no-ops use the same event-independent quiet path.
        report = names["Report to Discord"]["run"]
        self.assertIn('if [ "${JOB_STATUS}" = "success" ] && [ "${deployed}" -eq 0 ] && [ "${skipped}" -eq 0 ]; then', report)
        self.assertIn('echo "Healthy no-op; staying quiet."\n  exit 0', report)
        self.assertNotIn("event.before", text)
        upload = names["Upload deployed sha"]
        self.assertEqual(upload["with"]["name"], "monorepo-deployed-sha")
        for name in ["Record deployed sha", "Upload deployed sha"]:
            cond = names[name]["if"]
            self.assertIn("success()", cond)
            self.assertIn("github.event_name != 'workflow_dispatch'", cond)
            self.assertIn("dry_run == 'false'", cond)
            self.assertIn("steps.plan.outputs.base != steps.plan.outputs.sha", cond)
        order = [s.get("name") for s in steps]
        self.assertLess(order.index("Verify"), order.index("Upload deployed sha"))


if __name__ == "__main__":
    unittest.main()
