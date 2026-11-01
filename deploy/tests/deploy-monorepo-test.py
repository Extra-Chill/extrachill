#!/usr/bin/env python3
"""Targeted tests: python3 deploy/tests/deploy-monorepo-test.py (requires PyYAML)."""
import json
from pathlib import Path
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/deploy-monorepo.yml"


def components(path):
    return json.loads((ROOT / path).read_text())["components"]


class MonorepoDeployTest(unittest.TestCase):
    def test_projects_partition_members_from_the_release_poller(self):
        poller = {c["id"] for c in components("deploy/homeboy/projects/extrachill-site/extrachill-site.json")}
        attachments = components("deploy/homeboy/projects/extrachill-monorepo/extrachill-monorepo.json")
        members = {json.loads(m.read_text())["id"] for m in ROOT.glob("plugins/*/homeboy.json")}
        members |= {json.loads(m.read_text())["id"] for m in ROOT.glob("themes/*/homeboy.json")}
        self.assertEqual({a["id"] for a in attachments}, members)
        self.assertFalse(poller & members, "the release poller must never deploy a monorepo member")
        registry = {p.stem for p in (ROOT / "deploy/homeboy/components").glob("*.json")}
        self.assertEqual(registry, poller)
        # Homeboy resolves attachments from the workspace root at deploy time.
        for attachment in attachments:
            manifest = ROOT / attachment["local_path"] / "homeboy.json"
            self.assertFalse(Path(attachment["local_path"]).is_absolute())
            self.assertEqual(json.loads(manifest.read_text())["id"], attachment["id"])

    def test_workflow_contract(self):
        workflow = yaml.safe_load(WORKFLOW.read_text())
        legacy = yaml.safe_load((ROOT / ".github/workflows/deploy.yml").read_text())
        self.assertEqual(workflow["concurrency"], legacy["concurrency"])
        self.assertEqual(workflow["concurrency"], {"group": "deploy-main", "cancel-in-progress": False})
        triggers = workflow[True] if True in workflow else workflow["on"]
        self.assertEqual(set(triggers), {"workflow_run", "workflow_dispatch"})
        steps = workflow["jobs"]["deploy"]["steps"]
        names = {s.get("name"): s for s in steps}
        # One Homeboy release everywhere: the gate binary and both actions.
        actions = [s for s in steps if s.get("uses") == "Extra-Chill/homeboy-action@v2"]
        self.assertEqual([s["name"] for s in actions], ["Select outdated components", "Deploy"])
        for step in actions:
            self.assertEqual(step["with"]["version"], "${{ env.HOMEBOY_VERSION }}")
            self.assertEqual(step["with"]["config-dir"], "deploy/homeboy")
        gate = names["Prepare pinned Homeboy binary for the gate"]["run"]
        self.assertIn('"${HOMEBOY_SHA256}"', gate)
        self.assertIn("sha256sum --check --strict", gate)
        # Selection never mutates; only an enabled, non-dry-run Deploy does.
        self.assertTrue(names["Select outdated components"]["with"]["commands"].endswith("--outdated --dry-run"))
        self.assertIn("steps.plan.outputs.dry_run == 'false'", names["Deploy"]["if"])
        self.assertIn("--outdated --verify --confirm-dangerous", names["Deploy"]["with"]["commands"])
        order = [s.get("name") for s in steps]
        self.assertLess(order.index("Network rig gate"), order.index("Deploy"))


if __name__ == "__main__":
    unittest.main()
