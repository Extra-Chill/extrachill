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
            # Every configured component has bootstrapped docs and runs
            # nightly maintenance that keeps the existing articles.
            self.assertEqual(settings["run_kind"], "maintenance")
            self.assertEqual(settings["bootstrap_contract"], {})
            self.assertTrue(settings["source_delta"])
            for item in settings["source_delta"]:
                self.assertIs(item["requires_documentation_change"], False)
            self.assertIn("existing", settings["prompt"])
            self.assertIn("keep", settings["prompt"].lower())

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
        self.assertIn("max_turns: ${{ matrix.config.max_turns }}", workflow)
        for bad in (0, 121, "80", True):
            platforms = yaml.safe_load(MAP.read_text())
            platforms["plugins"]["plugins/extrachill-shop"]["runner"]["max_turns"] = bad
            with tempfile.NamedTemporaryFile("w", suffix=".yml") as handle:
                yaml.safe_dump(platforms, handle)
                handle.flush()
                with self.assertRaises(ValueError):
                    config.resolve("extrachill-shop", handle.name)

    def test_pr_title_scope_and_pin(self):
        workflow = (ROOT / ".github/workflows/docs-agent.yml").read_text()
        # Docs PRs are titled docs(<component>): user docs <run_kind>.
        self.assertIn("docs_scope: ${{ matrix.component }}", workflow)
        pins = [line for line in workflow.splitlines() if "maintain-docs.yml@" in line]
        self.assertEqual(len(pins), 1)
        self.assertRegex(pins[0], r"maintain-docs\.yml@[0-9a-f]{40}$")
        self.assertTrue((ROOT / "plugins/extrachill-docs/docs/docs-agent-runner.md").is_file())

    def test_schedule_workflow_fans_out_with_bounded_matrix(self):
        workflow = yaml.safe_load((ROOT / ".github/workflows/docs-agent.yml").read_text())
        triggers = workflow[True]
        self.assertEqual(len(triggers["schedule"]), 1)
        self.assertTrue(triggers["schedule"][0]["cron"])
        config_job = workflow["jobs"]["config"]
        self.assertEqual(config_job["steps"][0]["with"]["fetch-depth"], 0)
        self.assertIn("has_components", config_job["outputs"])
        job = workflow["jobs"]["docs-agent"]
        self.assertEqual(job["if"], "needs.config.outputs.has_components == 'true'")
        self.assertIs(job["strategy"]["fail-fast"], False)
        self.assertEqual(job["strategy"]["max-parallel"], 2)
        self.assertEqual(job["strategy"]["matrix"], "${{ fromJSON(needs.config.outputs.matrix) }}")
        self.assertEqual(job["concurrency"]["group"], "docs-agent-${{ matrix.component }}")
        self.assertIs(job["concurrency"]["cancel-in-progress"], False)
        # Scheduled runs are live; manual dispatch keeps its dry_run input.
        self.assertEqual(job["with"]["dry_run"], "${{ github.event_name != 'schedule' && inputs.dry_run }}")

    def test_dispatch_matrix_has_single_component(self):
        matrix = config.build_matrix("workflow_dispatch", MAP, "extrachill-shop")
        self.assertEqual([item["component"] for item in matrix["include"]], ["extrachill-shop"])
        self.assertEqual(matrix["include"][0]["config"], config.resolve("extrachill-shop", MAP))
        with self.assertRaises(ValueError):
            config.build_matrix("workflow_dispatch", MAP)
        with self.assertRaises(ValueError):
            config.build_matrix("push", MAP, "extrachill-shop")

    def test_schedule_matrix_derives_from_runner_blocks(self):
        everything = [f"plugins/{name}/inc/file.php" for name in config.configured_components(MAP)]
        matrix = config.build_matrix("schedule", MAP, changed_paths=everything)
        platforms = yaml.safe_load(MAP.read_text())["plugins"]
        expected = [key.split("/")[1] for key, value in platforms.items() if "runner" in value]
        self.assertEqual([item["component"] for item in matrix["include"]], expected)
        for item in matrix["include"]:
            self.assertEqual(item["config"]["run_kind"], "maintenance")

    def test_schedule_skips_unchanged_and_docs_only_components(self):
        changed = [
            "plugins/extrachill-shop/inc/checkout.php",
            "plugins/extrachill-events/docs/user/browsing-events.md",
            "plugins/extrachill-news-wire/inc/feed.php",
            "plugins/extrachill-users-extra/inc/file.php",
            "themes/extrachill/style.css",
        ]
        matrix = config.build_matrix("schedule", MAP, changed_paths=changed)
        self.assertEqual([item["component"] for item in matrix["include"]], ["extrachill-shop"])

    def test_source_changed_filter(self):
        self.assertTrue(config.source_changed("extrachill-blog", ["plugins/extrachill-blog/extrachill-blog.php"]))
        self.assertTrue(config.source_changed("extrachill-blog", ["plugins/extrachill-blog/docs-notes.txt"]))
        self.assertFalse(config.source_changed("extrachill-blog", ["plugins/extrachill-blog/docs/user/faqs.md"]))
        self.assertFalse(config.source_changed("extrachill-blog", ["plugins/extrachill-blogger/inc/a.php"]))
        self.assertFalse(config.source_changed("extrachill-blog", []))

    def test_schedule_with_no_changes_yields_empty_matrix(self):
        import contextlib
        import io
        import sys
        from unittest import mock

        self.assertEqual(config.build_matrix("schedule", MAP, changed_paths=[]), {"include": []})
        docs_only = ["plugins/extrachill-shop/docs/user/checkout.md"]
        self.assertEqual(config.build_matrix("schedule", MAP, changed_paths=docs_only), {"include": []})
        output = io.StringIO()
        with mock.patch.object(config, "git_changed_paths", return_value=docs_only), \
                mock.patch.object(sys, "argv", ["docs-agent-config.py", "--event", "schedule", "--map", str(MAP)]), \
                contextlib.redirect_stdout(output):
            config.main()
        self.assertEqual(output.getvalue().splitlines(), ['matrix={"include":[]}', "has_components=false"])

    def test_change_window_is_one_day(self):
        self.assertEqual(config.CHANGE_WINDOW_HOURS, 24)

    def test_rejects_unknown_or_unconfigured_component(self):
        for component in ("unknown", "extrachill-news-wire", "../extrachill-community"):
            with self.assertRaises(ValueError):
                config.resolve(component, MAP)


if __name__ == "__main__":
    unittest.main()
