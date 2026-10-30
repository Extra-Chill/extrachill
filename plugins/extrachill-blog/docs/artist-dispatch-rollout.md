# Artist Dispatch Rollout

Deploying the plugin provisions editable native Page defaults at `/submit/`, `/submit/guidelines/`, and `/submit/write/` only when those paths do not already exist. Before enabling the pilot, an operator should review the page copy, configure and enable the Artist Dispatch policy in Extra Chill Users, and confirm Blocks Everywhere 3.6.0 or newer is active.

Do not have plugin code rewrite the existing `/contribute/` page. During the content rollout, replace only its Writers paragraph with:

> **Writers:** Active artists and music participants can build trust in the Extra Chill Community, then request access to submit a transparent first-person Artist Dispatch for editorial review. [Learn about the Artist Dispatch pathway](/submit/).

The Developers, Software Testers, and Community Builders sections remain unchanged.

## Editor Recovery Dependency

Blocks Everywhere 3.6 mounts WordPress core's `LocalAutosaveMonitor`, but this integration has not proven a visible local-recovery notice or restore control in the embedded shell. This PR therefore promises native server autosave and real frontend preview only. Visible local recovery remains an upstream Blocks Everywhere requirement and must not be listed as launch acceptance until its UI is verified in an integrated browser test.

## Integration Test

`composer test:integration` drives the actual core posts and autosaves controllers in a disposable WordPress nightly sandbox. The script installs deterministic doubles for the public owner contracts, creates temporary users/posts, exercises the security boundaries, and cleans up. It must never run against production.
