#!/usr/bin/env python3
"""Targeted tests: python3 tests/deploy-monorepo-test.py (requires PyYAML)."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[1]
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
        original = (ROOT / "deploy/homeboy/components/extrachill-api.json").read_bytes()
        result, destination = self.prepare([
            {"component": "extrachill-api", "path": "plugins/extrachill-api"},
            {"component": "extrachill-components", "path": "packages/components"},
            {"component": "extrachill-network", "path": "."},
        ])
        self.assertEqual(result["ignored"], ["extrachill-components"])
        self.assertEqual(len(result["commands"]), 2)
        self.assertTrue(all("--ref " + "a" * 40 in c and c.endswith("--dry-run") for c in result["commands"]))
        config = json.loads((destination / "components/extrachill-api.json").read_text())
        self.assertNotIn("remote_url", config)
        self.assertEqual(config["local_path"], str(ROOT / "plugins/extrachill-api"))
        self.assertEqual(config["remote_path"], "wp-content/plugins/extrachill-api")
        project = json.loads((destination / "projects/extrachill-site/extrachill-site.json").read_text())
        self.assertEqual([c["id"] for c in project["components"]], result["components"])
        self.assertEqual(original, (ROOT / "deploy/homeboy/components/extrachill-api.json").read_bytes())

    def test_empty_and_live(self):
        self.assertEqual(self.prepare([])[0]["commands"], [])
        commands = self.prepare([{"component": "extrachill-network", "path": "."}], False)[0]["commands"]
        self.assertNotIn("--dry-run", commands[0])

    def test_bad_matrix_fails_closed(self):
        for entry in [
            {"component": "extrachill-api", "path": ".."},
            {"component": "extrachill-api", "path": "."},
            {"component": "bad;command", "path": "."},
        ]:
            with self.assertRaises(ValueError):
                self.prepare([entry])

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
        self.assertIn("--shared-path '.github/**' --format matrix", names["Plan"]["run"])
        self.assertIn('join(",")', names["Plan"]["run"])


if __name__ == "__main__":
    unittest.main()
