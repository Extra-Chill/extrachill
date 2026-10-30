# Network deploy

`.github/workflows/deploy.yml` is the active release-polling workflow with SSH access to production. `.github/workflows/deploy-monorepo.yml` is its staged replacement (plan-only until the explicit cutover below). Both use the same restricted SSH secrets and `deploy-main` concurrency lock. It **polls**: every 30 minutes it asks Homeboy which components installed on the server are behind their latest GitHub Release, and deploys those from the release asset.

```
any component repo: merge to main
  └─ release.yml@v2 → tag + GitHub Release + ZIP        (that is the repo's whole obligation)

extrachill-network / deploy.yml   every 30 min
  1. homeboy deploy extrachill-site --outdated
       one GitHub API call per component, no clones, no checkouts
  2. [gate, once #223 lands] homeboy rig up extrachill-network
  3. deploy each outdated component from its release asset
  4. homeboy deploy extrachill-site --check → evidence artifact
```

Production never builds, packages, or polls. The runner never clones a component — Homeboy reads each one's `homeboy.json` and release metadata from its GitHub repository (homeboy#14782, #14796).

## Why polling

A component repository does not have to know this workflow exists. It cuts GitHub Releases; that is all. No `dispatch-repo`, no cross-repo token, no per-repo deploy wiring — and it works identically for components whose repositories live outside this org.

A dropped `repository_dispatch` is invisible and silently leaves the server behind. A missed poll self-heals on the next tick. For a site where 30 minutes of latency is irrelevant, polling is the more robust half of that trade.

The `repository_dispatch: component-released` trigger is still wired for the rare case where one component should ship immediately rather than within the half hour (set `dispatch-repo: Extra-Chill/extrachill-network` on that repo's `release.yml@v2` caller — homeboy-action#476). It is off by default and nothing depends on it.

## Safety: `DEPLOY_AUTOMATION`

Unattended runs (schedule and `repository_dispatch`) only mutate the server when the repository variable `DEPLOY_AUTOMATION` is exactly `enabled`. Anything else — unset, empty, `paused` — and they plan and report without touching anything.

This means merging this workflow is inert: the schedule starts running immediately and shows you, every 30 minutes, exactly what it *would* deploy. Review a few cycles, then set the variable.

```
gh variable set DEPLOY_AUTOMATION --body enabled  --repo Extra-Chill/extrachill-network   # go live
gh variable set DEPLOY_AUTOMATION --body paused   --repo Extra-Chill/extrachill-network   # back to plan-only
```

A manual `workflow_dispatch` is an explicit human decision and is never gated by the variable — an operator can always deploy or verify on demand.

## Checked-in config: `deploy/homeboy/`

A Homeboy config root, selected with `HOMEBOY_CONFIG_ROOT` (homeboy#14783):

| Path | Contents |
|---|---|
| `projects/extrachill-site/extrachill-site.json` | server id, base path, path roots, remote logs, and the 34 deployable attachments (`id` + `remote_path`, `local_path` empty) |
| `servers/hetzner.json` | host, user, port; `identity_file` is `null` |
| `components/<id>.json` | standalone registry: `remote_url` (GitHub) + `remote_path`. The GitHub `remote_url` is what makes checkout-less resolution apply. |

Nothing here is secret. `database.name`/`user` are empty and `api.enabled` is false.

Adding a deployable = one attachment line in the project + one registry file. `tests/deploy-workflow-smoke.php` checks they agree.

**A component is only deployable here if Homeboy can resolve a GitHub Release for it.** Two site components are deliberately absent:

- `chubes-gallery-lightbox` (`chubes4/chubes-gallery-lightbox`) has one tag and no GitHub Release. It joins once its repo gets a `release.yml`.
- `wp-native-auth` lives in the `chubes4/wp-native` monorepo. It *does* release (`wp-native-auth-v0.4.1`), but its `homeboy.json` is at `plugins/wp-native-auth/`, not the repository root, so checkout-less resolution 404s and the zipball fallback returns HTTP 415. Both gaps are tracked in homeboy#14813; the prefixed tag also needs `tag_prefix`.

- `intelligence` (`Automattic/intelligence`) is a **private** repository. Checkout-less resolution reads each component's release metadata with the job's `github.token`, which cannot read a private repository in another organisation, so every poll skipped it and failed the run. It is used rarely on this network and is shipped manually instead (#244).

Every other attached component resolves from its own repository's latest release. Components outside Extra-Chill (for example `Automattic/wp-codebox`) must be public for the same reason; derive `remote_url` from `git remote get-url origin`, never by guessing the org.

## Secrets (org-level, repository access restricted to `extrachill-network`)

| Name | Contents |
|---|---|
| `EXTRACHILL_DEPLOY_SSH_KEY` | Private key for the dedicated `deploy` user on the VPS |
| `EXTRACHILL_DEPLOY_KNOWN_HOSTS` | `known_hosts` line(s) for the server (`ssh-keyscan -H <host>`) |

Homeboy also needs a GitHub token to read `homeboy.json` and release metadata from each component repository; the runner's `GITHUB_TOKEN` covers public and org repos.

## VPS side

A dedicated `deploy` user, **not** `opencode`, with write access limited to `wp-content/plugins`, `wp-content/themes`, and `wp-content/mu-plugins` under `/var/www/extrachill.com`. No sudo. `servers/hetzner.json` `user` must match.

## Manual runs

Actions → Deploy → Run workflow:

| Inputs | Effect |
|---|---|
| `component` + `version`, `dry_run: true` (default) | plan one component at one version, no server contact |
| `component` + `version`, `dry_run: false` | deploy it |
| `component` only | deploy its latest GitHub Release |
| nothing | everything behind its latest release |

**Rollback** = deploy the previous version: `component` + the prior `version`. There is no separate rollback mode; Homeboy's `--allow-downgrade` guard applies, so a downgrade prompts for `--allow-downgrade` — add it to the plan step if you need it routinely.

Every run uploads `deploy-evidence-<run_id>` with Homeboy's structured JSON for the deploy and the post-deploy `--check`.

## Blocked: homeboy#14813

The poll cannot function yet. On Homeboy v0.378.0 only `deploy <project> <component> --version <v>` resolves a checkout-less component. `--check` skips every one of them, and `--outdated` therefore reports **"No outdated components found"** — a silent false negative, not a clean bill of health.

That is the worst failure mode for an unattended loop: it would report nothing-to-do forever while the server drifted. `DEPLOY_AUTOMATION` must stay unset until homeboy#14813 ships, and the workflow fails loudly if a poll resolves nothing (see the plan step) rather than trusting that message.

## Not yet enabled

**Network rig gate** (#223). Nothing currently catches "component A releases, component B breaks" — each repo's own tests gate its release, and that is the whole safety story until the rig exists. The placeholder step is in the workflow, commented out.

## First run

1. Create the two secrets and the `deploy` user. Leave `DEPLOY_AUTOMATION` unset.
2. Let the schedule run a few cycles and read what it plans. The first batch reflects however far the server has drifted, which may be large.
3. Run workflow: `component=extrachill-cache`, `version=<latest>`, `dry_run: true`, then `false`. Confirm `--check` in the evidence artifact and zero new fatals in `wp-content/debug.log`.
4. Rollback drill: deploy the previous version.
5. `gh variable set DEPLOY_AUTOMATION --body enabled` when the planned batches look right.

## Staged monorepo deploy (#390)

`deploy-monorepo.yml` runs on pushes to `main` and manual dispatches. It is
**plan-only by default**, including explicit `dry_run: false` dispatches, until
`MONOREPO_DEPLOY_AUTOMATION=enabled`. The existing poller's automation variable
is independent and is not changed by this implementation.

1. Checkout `sha` (dispatch; empty means the workflow commit) with full history.
   Push diffs use `event.before`; dispatch diffs use the selected commit's first
   parent. Initial commits/zero before SHAs use a synthetic empty commit.
2. Run pinned Homeboy **0.436.4** `component changed --base <before> --head <sha>
   --path . --shared-path '.github/**' --format matrix`. Evidence contains the
   complete matrix plus filtered deploy commands. An empty matrix succeeds and
   skips the gate and deploy. Non-attached packages are explicitly listed as
   `ignored` deployment targets; they still participate in dependency expansion
   and trigger the gate. External production attachments are not monorepo targets.
3. For any change, boot the network rig **once**, using
   `HOMEBOY_SETTINGS_JSON={"extrachill_monorepo_source":"<absolute checkout>"}`.
   This requires the parallel rig change #389. No emergency bypass is provided.
   Node, WP Codebox (including its production egress fence), Chromium, rig result
   summary and evidence follow the release workflow. A failed gate blocks deploy.
4. `deploy/prepare-monorepo.py` copies the existing production config into a
   temporary config root. It overlays selected attached components with their
   portable manifests and absolute checkout `local_path`, removes legacy
   per-repo `remote_url`s, and narrows project attachments to the selected set.
   The checked-in release registry is never rewritten. This is the monorepo
   config variant: **local checkout**, not a remote URL flip.
5. Live runs execute one `homeboy deploy <id> --project extrachill-site --ref
   <sha> --confirm-dangerous --no-pull` command per attached component, serially
   via `homeboy-action@v2`, then verify with `deploy extrachill-site --check`.
   Plan-only runs never pass SSH credentials to a deploy/verify action. Discord
   reporting and 30-day evidence uploads follow the release workflow; healthy
   no-ops/plans stay quiet in Discord. The Actions summary always shows the plan.

Homeboy derives the exact-ref component prefix from a local Git checkout; a
remote-only registry cannot express a source subdirectory at a ref today.
Checkout-less subpath support is requested in
[homeboy#16716](https://github.com/Extra-Chill/homeboy/issues/16716). Until that
ships, do not point the legacy per-repo URLs at this repository and expect
`--ref` to discover subdirectories.

### Cutover / rollback

- Land #389, run a manual dry run at the intended SHA, inspect the matrix,
  ignored package entries, commands and rig evidence. Add any newly deployable
  component's production attachment/remote path before expecting it to ship.
- Pause polling: set `DEPLOY_AUTOMATION=paused` (and disable the release polling
  workflow at cutover to avoid unnecessary plan runs). Never enable both models.
- Enable `MONOREPO_DEPLOY_AUTOMATION=enabled`. Pushes now deploy; dispatch still
  defaults to `dry_run: true`. Explicit `dry_run: false` deploys that selected
  commit's change set. The temporary local registry is generated automatically;
  no production registry edits or per-component release/version bumps are needed.
- To revert the model, pause `MONOREPO_DEPLOY_AUTOMATION`, then re-enable the
  release workflow/`DEPLOY_AUTOMATION`. A source rollback can instead dispatch
  the old SHA, but its first-parent diff is **not** the diff from production:
  verify that it includes every component needing restoration before proceeding.

Both workflows hold `deploy-main` for the entire job, including the gate, with
`cancel-in-progress: false`. GitHub concurrency is not a durable FIFO: pending
runs can be replaced. Push detection uses `before`, not the last deployed SHA;
operators must reconcile missed/skipped runs with a reviewed full change set
before relying on unattended cutover. It does not guarantee intermediate commits
ship. This staging workflow does not alter versions or CHANGELOGs.

Targeted local checks:

```sh
python3 tests/deploy-monorepo-test.py  # PyYAML; workflow parse + config tests
php tests/deploy-workflow-smoke.php
homeboy component changed --base HEAD^ --head HEAD --path . \
  --shared-path '.github/**' --format matrix
```

Homeboy v0.436.4 was exercised on the real staging range
`d639999d7^..d639999d7`: the expected matrix is only
`extrachill-components` at `packages/components`. The real latest staging range
`6b0633aa4^..6b0633aa4` selects `extrachill-network` plus five transitive
same-repo dependents (`extrachill-api`, `extrachill-artist-platform`,
`extrachill-contact`, `extrachill-events`, `extrachill-studio`). The workflow's
actual dispatch Plan shell was also run locally at `6b0633aa4` with
`REQUEST_DRY_RUN=true`: six expected components and six dry-run commands were
produced in the isolated registry, without SSH. Both workflows pass actionlint
v1.7.7. A full rig boot/live deploy requires CI's WP Codebox setup, the #389 rig
setting implementation, and production credentials; neither was attempted locally.
