"""Local checks for the canonical map and dispatch workflow."""

import importlib.util
from pathlib import Path
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[3]
MAP = ROOT / "plugins/extrachill-docs/runner-configs/platform-map.yml"
SPEC = importlib.util.spec_from_file_location(
    "config", ROOT / "plugins/extrachill-docs/scripts/docs-agent-config.py"
)
config = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(config)


class DocsAgentConfigTest(unittest.TestCase):
    def test_dispatch_matches_configured_components(self):
        workflow = yaml.safe_load((ROOT / ".github/workflows/docs-agent.yml").read_text())
        # PyYAML's YAML 1.1 parser treats the Actions 'on' key as True.
        inputs = workflow[True]["workflow_dispatch"]["inputs"]
        self.assertIs(inputs["dry_run"]["default"], True)
        platforms = yaml.safe_load(MAP.read_text())["plugins"]
        expected = {key.split("/")[1] for key, value in platforms.items() if "runner" in value}
        self.assertEqual(set(inputs["component"]["options"]), expected)
        for component in expected:
            settings = config.resolve(component, MAP)
            self.assertEqual(settings["audience"], "user")
            self.assertIn(f"plugins/{component}/", settings["prompt"])
            self.assertIn(f"Read source only under plugins/{component}/", settings["prompt"])
            for item in settings["source_delta"]:
                for ref in item["source_refs"]:
                    self.assertTrue(ref.startswith(f"plugins/{component}/"), ref)
            self.assertIn("writing-rules.md", settings["prompt"])
            self.assertEqual(len(settings["drift_checks"]), 1)
            self.assertIsInstance(settings["max_turns"], int)
            if component in ("extrachill-events", "extrachill-newsletter", "extrachill-shop"):
                self.assertEqual(settings["run_kind"], "maintenance")
                self.assertTrue(settings["source_delta"])
            else:
                self.assertEqual(settings["run_kind"], "bootstrap")
                self.assertIn("following users", settings["bootstrap_contract"]["forbidden_phrases"])
                self.assertTrue(settings["bootstrap_contract"]["required_globs"])

    def test_rejects_source_refs_outside_the_component(self):
        import tempfile

        platforms = yaml.safe_load(MAP.read_text())
        platforms["plugins"]["plugins/extrachill-shop"]["runner"]["source_delta"][0]["source_refs"] = ["inc/**"]
        with tempfile.NamedTemporaryFile("w", suffix=".yml") as handle:
            yaml.safe_dump(platforms, handle)
            handle.flush()
            with self.assertRaises(ValueError):
                config.resolve("extrachill-shop", handle.name)

    def test_max_turns_defaults_and_bounds(self):
        import tempfile

        self.assertEqual(config.resolve("extrachill-community", MAP)["max_turns"], 40)
        self.assertEqual(config.resolve("extrachill-shop", MAP)["max_turns"], 80)
        workflow = (ROOT / ".github/workflows/docs-agent.yml").read_text()
        self.assertIn("max_turns: ${{ fromJSON(needs.config.outputs.config).max_turns }}", workflow)
        for bad in (0, 121, "80", True):
            platforms = yaml.safe_load(MAP.read_text())
            platforms["plugins"]["plugins/extrachill-shop"]["runner"]["max_turns"] = bad
            with tempfile.NamedTemporaryFile("w", suffix=".yml") as handle:
                yaml.safe_dump(platforms, handle)
                handle.flush()
                with self.assertRaises(ValueError):
                    config.resolve("extrachill-shop", handle.name)

    def test_rejects_unknown_or_unconfigured_component(self):
        for component in ("unknown", "extrachill-news-wire", "../extrachill-community"):
            with self.assertRaises(ValueError):
                config.resolve(component, MAP)


if __name__ == "__main__":
    unittest.main()
