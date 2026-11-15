# Production self-upgrade

`deploy.yml` polls the latest wp-coding-agents release after plugin deploy/verify.
It uses the existing deploy SSH secrets and server config, never installs a
runner binary, and invokes only the root-owned `start`/`status` wrapper. Dry-run
(including unattended plan mode) reads versions but never invokes sudo. Equal
or newer installed versions produce no summary or Discord notification.

## Bootstrap / pending #871

`SELF_UPGRADE_USERS=deploy` requires wp-coding-agents#871. Until that support is
released **and applied by an already-authorised operator**, sudo refusals are
notices, not deployment failures. The old host-config parser rejects unknown
keys, so an operator bootstrapping must use a release containing #871; do not
manually self-upgrade an older wrapper against this new host.conf.

The wrapper applies reviewed origin/main, not a caller-selected release tag.
The latest release is the trigger, not an exact pin. After a fresh successful
status, the poller verifies VERSION reached at least the observed release and
reports the actual bundled Roadie version. Already-running upgrades defer to
the next tick. Polling is bounded (60 ten-second sleeps, SSH calls limited to
30 seconds, workflow step limited to 45 minutes). A timeout does not cancel
the independent systemd upgrade unit; inspect status before retrying.

## Active sessions / restart trade-off

Roadie's current `cli/src/hrana-server.ts` `/health` returns readiness and PIDs,
not active-session counts. Its lock port also varies with the data directory.
There is no cheap supported health check for active sessions here; querying
internal databases would add credentials and couple deploy to internals.
Consequently this step permits the wrapper's Roadie restart during active
runs, relying on Roadie's interrupted-run resumption, as allowed by #1501.
Revisit when Roadie exposes a supported active-session probe. A health check
would only narrow the race, not eliminate sessions starting before restart.

Tests (from repository root):

```sh
php deploy/tests/deploy-workflow-smoke.php
python3 deploy/tests/coding-agents-upgrade-test.py
```
