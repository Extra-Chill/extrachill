#!/usr/bin/env python3
"""Resolve a dispatched component from the canonical platform map."""

import argparse
import json
from pathlib import Path

import yaml


def resolve(component, map_path):
    platforms = yaml.safe_load(Path(map_path).read_text())["plugins"]
    key = f"plugins/{component}"
    entry = platforms.get(key, {})
    if not entry.get("runner"):
        raise ValueError(f"Component has no Docs Agent configuration: {component}")
    config = dict(entry["runner"])
    if config["writable_paths"] != f"{key}/{entry['docs_subpath']}/**":
        raise ValueError("Writable paths must match the component's documentation path")
    for field, default in (("bootstrap_contract", {}), ("source_delta", [])):
        config.setdefault(field, default)
    return config


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("component")
    parser.add_argument("--map", default="plugins/extrachill-docs/runner-configs/platform-map.yml")
    args = parser.parse_args()
    print("config=" + json.dumps(resolve(args.component, args.map), separators=(",", ":")))


if __name__ == "__main__":
    main()
