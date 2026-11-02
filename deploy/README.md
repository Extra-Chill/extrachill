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

The `repository_dispatch: component-released` trigger is still wired for the rare case where one component should ship immediately rather than within the half hour (set `dispatch-repo: Extra-Chill/extrachill` on that repo's `release.yml@v2` caller — homeboy-action#476). It is off by default and nothing depends on it.

## Safety: `DEPLOY_AUTOMATION`

Unattended runs (schedule and `repository_dispatch`) only mutate the server when the repository variable `DEPLOY_AUTOMATION` is exactly `enabled`. Anything else — unset, empty, `paused` — and they plan and report without touching anything.

This means merging this workflow is inert: the schedule starts running immediately and shows you, every 30 minutes, exactly what it *would* deploy. Review a few cycles, then set the variable.

```
gh variable set DEPLOY_AUTOMATION --body enabled  --repo Extra-Chill/extrachill   # go live
gh variable set DEPLOY_AUTOMATION --body paused   --repo Extra-Chill/extrachill   # back to plan-only
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
- `wp-native-auth` lives in the `Extra-Chill/wp-native` monorepo. It *does* release (`wp-native-auth-v0.4.1`), but its `homeboy.json` is at `plugins/wp-native-auth/`, not the repository root, so checkout-less resolution 404s and the zipball fallback returns HTTP 415. Both gaps are tracked in homeboy#14813; the prefixed tag also needs `tag_prefix`.

- `intelligence` (`Automattic/intelligence`) is a **private** repository. Checkout-less resolution reads each component's release metadata with the job's `github.token`, which cannot read a private repository in another organisation, so every poll skipped it and failed the run. It is used rarely on this network and is shipped manually instead (#244).

Every other attached component resolves from its own repository's latest release. Components outside Extra-Chill (for example `Automattic/wp-codebox`) must be public for the same reason; derive `remote_url` from `git remote get-url origin`, never by guessing the org.

## Secrets (org-level, repository access restricted to `extrachill`)

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

## Monorepo deploy (#390)

`deploy-monorepo.yml` runs after every Release on `main` and on manual
dispatch. Dispatch defaults to `dry_run: true`; nothing mutates the server
unless `MONOREPO_DEPLOY_AUTOMATION=enabled`.

1. Check out `sha` (dispatch; empty means `main`).
2. **Select:** `homeboy deploy extrachill-monorepo --ref <sha> --outdated
   --dry-run`. Homeboy builds every attached component at that sha, reusing
   content-keyed packages from `HOMEBOY_BUILD_CACHE_DIR`. It then compares each
   build with the server's deploy receipt and installed tree. Selection is
   read-only and needs no record of what was last deployed.
3. **Gate:** when anything is outdated, boot the network rig once with
   `HOMEBOY_SETTINGS_JSON={"extrachill_monorepo_source":"<absolute checkout>"}`.
   A failed gate blocks the deploy. No emergency bypass is provided.
4. **Deploy:** `homeboy deploy extrachill-monorepo --ref <sha> --outdated
   --verify --confirm-dangerous` deploys what is outdated, then receipt-checks
   exactly what it deployed. Discord reporting and 30-day evidence uploads
   follow the release workflow; healthy no-ops stay quiet.

Both actions use `config-dir: deploy/homeboy`. Project attachments carry
repo-relative `local_path`s (`plugins/<id>`, `themes/<id>`), which Homeboy
resolves from the workspace root. A replaced, failed or cancelled run heals on
the next run, because selection always starts from the server's state.

### Cutover / rollback

- Pause polling with `DEPLOY_AUTOMATION=paused`, and never enable both models.
- To revert the model, pause `MONOREPO_DEPLOY_AUTOMATION`, then re-enable the
  release workflow and `DEPLOY_AUTOMATION`.
- Source rollback: dispatch the old sha with `dry_run: false`. Selection
  compares that tree with production, so it deploys exactly the components that
  differ.

Both workflows hold `deploy-main` for the whole job, gate included, with
`cancel-in-progress: false`.

Targeted local checks:

```sh
python3 tests/deploy-monorepo-test.py  # PyYAML; workflow parse + config tests
php tests/deploy-workflow-smoke.php
homeboy deploy extrachill-monorepo --ref HEAD --outdated --dry-run
```
