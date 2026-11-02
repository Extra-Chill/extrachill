#!/usr/bin/env python3
"""Run: python3 deploy/tests/plugin-docs-package-test.py (requires rsync, sed, PyYAML).

Exercise Homeboy's real .buildignore transform and rsync staging, not a Python
approximation of rsync matching. Temporary packages are removed after each test.
"""
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
PLUGINS = ROOT / "plugins"


def stage(component, destination, excludes):
    # Keep identical to wordpress/scripts/build/build.sh:create_rsync_excludes.
    if (component / ".buildignore").is_file():
        with excludes.open("wb") as output:
            subprocess.run(
                ["sed", "s|/$||; /^#/d; /^$/d", str(component / ".buildignore")],
                stdout=output, check=True,
            )
    else:
        # Homeboy's fallback when a component has no .buildignore.
        excludes.write_text(""".git
.gitignore
.gitattributes
README.md
CLAUDE.md
AGENTS.md
.claude
.vscode
.idea
*.swp
*.swo
*~
/dist/
*.zip
*.tar.gz
.DS_Store
._*
node_modules
*.log
*.tmp
*.temp
.env*
build.sh
.buildignore
/tests
phpunit.xml*
.github
composer.lock
package-lock.json
webpack.config.js
""")
    subprocess.run(
        ["rsync", "-a", f"--exclude-from={excludes}", f"{component}/", f"{destination}/"],
        check=True,
    )


class PluginDocsPackageTest(unittest.TestCase):
    def test_booking_network_entry_survives_package_staging(self):
        component = PLUGINS / "extrachill-booking"
        with tempfile.TemporaryDirectory() as temporary:
            temporary = Path(temporary)
            package = temporary / "package"
            stage(component, package, temporary / "excludes")
            entry = "extrachill-booking-network-blocks.php"
            self.assertEqual((component / entry).read_bytes(), (package / entry).read_bytes())
            self.assertFalse((package / "tests").exists())

    def test_mapped_docs_survive_package_staging(self):
        config = PLUGINS / "extrachill-docs/runner-configs/platform-map.yml"
        platforms = yaml.safe_load(config.read_text())["repos"]
        self.assertTrue(platforms, "platform map must not be empty")
        checked = 0
        for repo, platform in platforms.items():
            component = PLUGINS / repo.rsplit("/", 1)[-1]
            subpath = platform.get("docs_subpath", "docs/user")
            articles = sorted((component / subpath).glob("*.md"))
            if not articles:
                continue
            checked += 1
            with self.subTest(repo=repo), tempfile.TemporaryDirectory() as temporary:
                temporary = Path(temporary)
                package = temporary / "package"
                stage(component, package, temporary / "excludes")
                for article in articles:
                    relative = article.relative_to(component)
                    shipped = package / relative
                    self.assertTrue(shipped.is_file(), f"{repo}: package drops {relative}")
                    self.assertEqual(article.read_bytes(), shipped.read_bytes())
                print(f"{repo}: verified {len(articles)} packaged articles at {subpath}")

                # These two components intentionally exclude all developer Markdown.
                if component.name in ("extrachill-community", "extrachill-artist-platform"):
                    for markdown in package.rglob("*.md"):
                        self.assertEqual(markdown.parent.relative_to(package).as_posix(),
                                         "docs/user", f"developer Markdown leaked: {markdown}")
        self.assertGreater(checked, 0, "no mapped articles were tested")

    def test_developer_docs_stay_excluded_at_any_depth(self):
        for slug in ("extrachill-community", "extrachill-artist-platform"):
            with self.subTest(component=slug), tempfile.TemporaryDirectory() as temporary:
                temporary = Path(temporary)
                source = temporary / "source"
                source.mkdir()
                (source / ".buildignore").write_bytes((PLUGINS / slug / ".buildignore").read_bytes())
                keep = ["docs/user/help.md", "docs/user/README.md"]
                drop = ["README.md", "CHANGELOG.md", "MIGRATION-GUIDE.md", "next-steps.md",
                        "docs/architecture.md", "docs/internal/design.md", "inc/docs/internal.md"]
                for name in keep + drop:
                    path = source / name
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_text(f"# {name}\n")
                package = temporary / "package"
                stage(source, package, temporary / "excludes")
                for name in keep:
                    self.assertTrue((package / name).is_file(), f"help missing: {name}")
                for name in drop:
                    self.assertFalse((package / name).exists(), f"developer doc leaked: {name}")


if __name__ == "__main__":
    unittest.main()
