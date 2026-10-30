#!/usr/bin/env python3
"""Build an isolated local-checkout registry and serial deploy commands.

Input is Homeboy's raw changed matrix. Only existing production attachments ship;
packages/non-attached components still participate in detection and rig validation.
Monorepo members live in projects/extrachill-monorepo; the release poller
owns projects/extrachill-site (release-based components only).
"""
import argparse
import json
from pathlib import Path
import re
import shutil


def prepare(checkout, destination, matrix, sha, dry_run):
    checkout = Path(checkout).resolve()
    destination = Path(destination).resolve()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("sha must be a full commit SHA")
    source = checkout / "deploy/homeboy"
    if destination == source or source in destination.parents or destination in checkout.parents or destination == checkout:
        raise ValueError("config destination must be outside the source config and checkout ancestors")
    shutil.copytree(source, destination, dirs_exist_ok=True)
    project_path = destination / "projects/extrachill-monorepo/extrachill-monorepo.json"
    project = json.loads(project_path.read_text())
    attachments = {item["id"]: item for item in project["components"]}
    selected = []
    ignored = []
    seen = set()
    for entry in matrix["include"]:
        component = entry["component"]
        if not re.fullmatch(r"[a-z0-9][a-z0-9-]*", component) or component in seen:
            raise ValueError("invalid or duplicate component id")
        seen.add(component)
        path = (checkout / entry["path"]).resolve()
        if not path.is_relative_to(checkout):
            raise ValueError("component path escapes checkout")
        manifest = path / "homeboy.json"
        if not manifest.is_file():
            raise ValueError("component path has no homeboy.json")
        portable = json.loads(manifest.read_text())
        if portable["id"] != component:
            raise ValueError("matrix and portable component ids disagree")
        if component not in attachments:
            ignored.append(component)
            continue
        attachment = attachments[component]
        attachment["local_path"] = str(path)
        config = dict(portable, local_path=str(path), remote_path=attachment["remote_path"])
        # Never resolve a monorepo SHA against the legacy per-repo remote_url.
        config.pop("remote_url", None)
        (destination / "components" / f"{component}.json").write_text(json.dumps(config, indent=2) + "\n")
        selected.append(component)
    # Verification must inspect only the planned components, not legacy remotes.
    project["components"] = [attachments[component] for component in selected]
    project_path.write_text(json.dumps(project, indent=2) + "\n")
    commands = [f"deploy {component} --project extrachill-monorepo --ref {sha} --confirm-dangerous --no-pull" +
                (" --dry-run" if dry_run else "") for component in selected]
    return {"components": selected, "ignored": ignored, "commands": commands}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", required=True)
    parser.add_argument("--destination", required=True)
    parser.add_argument("--matrix", required=True)
    parser.add_argument("--sha", required=True)
    parser.add_argument("--dry-run", choices=["true", "false"], default="true")
    args = parser.parse_args()
    print(json.dumps(prepare(args.checkout, args.destination, json.loads(Path(args.matrix).read_text()),
                             args.sha, args.dry_run == "true")))
