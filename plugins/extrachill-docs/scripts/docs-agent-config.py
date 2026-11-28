#!/usr/bin/env python3
"""Resolve Docs Agent components from the canonical platform map.

A manual dispatch resolves one component. A scheduled run fans out over every
component with a runner block whose source (anything under plugins/<component>/
except its docs/ directory) changed on main within CHANGE_WINDOW_HOURS.
"""

import argparse
import json
from pathlib import Path
import subprocess

import yaml

DEFAULT_MAX_TURNS = 40
MAX_TURNS_CEILING = 120
# Nightly schedule: a component runs when its source changed in this window.
CHANGE_WINDOW_HOURS = 24
DEFAULT_MAP = "plugins/extrachill-docs/runner-configs/platform-map.yml"


def load_platforms(map_path):
    return yaml.safe_load(Path(map_path).read_text())["plugins"]


def configured_components(map_path):
    """Every component with a runner block, in map order."""
    return [key.split("/", 1)[1] for key, entry in load_platforms(map_path).items() if entry.get("runner")]


def resolve(component, map_path):
    platforms = load_platforms(map_path)
    key = f"plugins/{component}"
    entry = platforms.get(key, {})
    if not entry.get("runner"):
        raise ValueError(f"Component has no Docs Agent configuration: {component}")
    config = dict(entry["runner"])
    if config["writable_paths"] != f"{key}/{entry['docs_subpath']}/**":
        raise ValueError("Writable paths must match the component's documentation path")
    for field, default in (("bootstrap_contract", {}), ("source_delta", []), ("max_turns", DEFAULT_MAX_TURNS)):
        config.setdefault(field, default)
    turns = config["max_turns"]
    if isinstance(turns, bool) or not isinstance(turns, int) or not 1 <= turns <= MAX_TURNS_CEILING:
        raise ValueError(f"max_turns must be an integer from 1 to {MAX_TURNS_CEILING}")
    # The agent and the completion validator read these repository-relative
    # paths; an unscoped ref (e.g. "inc/**") points outside the component.
    for item in config["source_delta"]:
        for ref in item.get("source_refs", []):
            if not ref.startswith(f"{key}/"):
                raise ValueError(f"source_delta refs must stay under {key}/: {ref}")
    for glob in config["bootstrap_contract"].get("required_globs", []):
        if not glob["pattern"].startswith(f"{key}/"):
            raise ValueError(f"bootstrap_contract globs must stay under {key}/: {glob['pattern']}")
    return config


def source_changed(component, changed_paths):
    """True when a changed path is under plugins/<component>/ but outside its docs/."""
    prefix = f"plugins/{component}/"
    docs = f"{prefix}docs/"
    return any(path.startswith(prefix) and not path.startswith(docs) for path in changed_paths)


def git_changed_paths(ref="HEAD", hours=CHANGE_WINDOW_HOURS):
    """Paths under plugins/ touched by commits on ref within the last `hours`."""
    output = subprocess.run(
        ["git", "log", ref, f"--since={hours} hours ago", "--name-only", "--format=", "--", "plugins/"],
        check=True,
        capture_output=True,
        text=True,
    ).stdout
    return sorted({line.strip() for line in output.splitlines() if line.strip()})


def build_matrix(event, map_path, component=None, changed_paths=None):
    """Return the matrix ({"include": [...]}) for a dispatch or scheduled run."""
    if event == "workflow_dispatch":
        if not component:
            raise ValueError("workflow_dispatch requires a component")
        components = [component]
    elif event == "schedule":
        if changed_paths is None:
            changed_paths = git_changed_paths()
        components = [name for name in configured_components(map_path) if source_changed(name, changed_paths)]
    else:
        raise ValueError(f"Unsupported event: {event}")
    return {"include": [{"component": name, "config": resolve(name, map_path)} for name in components]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("component", nargs="?", help="Component for workflow_dispatch")
    parser.add_argument("--event", choices=("workflow_dispatch", "schedule"), default="workflow_dispatch")
    parser.add_argument("--map", default=DEFAULT_MAP)
    parser.add_argument("--ref", default="HEAD", help="Branch whose recent commits decide scheduled runs")
    args = parser.parse_args()
    changed = git_changed_paths(args.ref) if args.event == "schedule" else None
    matrix = build_matrix(args.event, args.map, args.component, changed)
    print("matrix=" + json.dumps(matrix, separators=(",", ":")))
    print("has_components=" + ("true" if matrix["include"] else "false"))


if __name__ == "__main__":
    main()
