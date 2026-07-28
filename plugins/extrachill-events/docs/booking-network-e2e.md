# Booking Network E2E

The booking network E2E is the repository-owned backend gate for the venue-booking vertical slice. It composes Homeboy's installed `wordpress-multisite-e2e` rig and WP Codebox's managed Docker MySQL service. WP Codebox owns the disposable WordPress runtime, database lifecycle, runtime policy, and teardown evidence; this repository owns only the Extra Chill topology, scenario, and assertions.

## Primitive Investigation

The existing repository already used Homeboy's WordPress extension, WP Codebox's `wordpress.phpunit` adapter, MySQL service recipes, and the `wordpress-multisite-e2e` rig. No separate network runner was needed. The permanent gate uses the rig's exported recipe builder and adds its supported `inputs.services` contract because the generic rig does not expose database service settings directly. It does not create another multisite, browser, database, or evidence abstraction.

The scenario executes public REST and registered Ability contracts where those are the product boundary. Direct SQL is limited to the two independent `mysqli` contenders that prove one-winner optimistic compare-and-swap behavior against the real booking table.

## Run From A Checkout

Place the dependency checkouts beside this repository, or set the explicit path variables below. Data Machine must have its Composer dependencies installed. Homeboy's `wordpress-multisite-e2e` rig, WP Codebox, Node.js, Git, and Docker must be available.

```bash
composer run test:booking-network-e2e
```

Explicit paths are useful when the repositories are not siblings:

```bash
BOOKING_E2E_DATA_MACHINE=/path/to/data-machine \
BOOKING_E2E_DATA_MACHINE_EVENTS=/path/to/data-machine-events \
BOOKING_E2E_EXTRACHILL_API=/path/to/extrachill-api \
BOOKING_E2E_EXTRACHILL_NETWORK=/path/to/extrachill-network \
BOOKING_E2E_EXTRACHILL_USERS=/path/to/extrachill-users \
BOOKING_E2E_EXTRACHILL_THEME=/path/to/extrachill \
BOOKING_E2E_ARTIFACT_ROOT="$PWD/artifacts/booking-network-e2e" \
composer run test:booking-network-e2e
```

Append `_REV` to any component path variable to require an exact commit. CI uses this to pin every dependency. `BOOKING_E2E_SEED` selects one of the deterministic timezone/date profiles. `BOOKING_E2E_RIG_RUNNER` may identify the canonical rig's `run.mjs` when Homeboy's rig registry is unavailable, as in a fresh CI checkout.

CI and the default command use WP Codebox's managed Docker MySQL provider. A host with the MariaDB server tools but no Docker can select WP Codebox's managed native provider with `BOOKING_E2E_DATABASE_PROVIDER=native`; it retains the same disposable database and automatic teardown contract.

## Isolated Topology

The recipe provisions a fresh path-based WordPress multisite at `localhost`, a managed MySQL 8 container, and read-only mounts for the Events plugin, Data Machine, Data Machine Events, Extra Chill API, Extra Chill Network, Extra Chill Users, and the Extra Chill theme. It creates the network's expected site IDs, network-activates shared plugins, and activates Events-owned plugins only on Blog ID 7.

No production URL, database credential, plugin directory, upload, or content export is used. WP Codebox releases the WordPress runtime and removes its managed database container in `finally` cleanup. `runtime-result.json` records `managedRuntimeServices[].lifecycle` and `managedRuntimeServices[].teardown`; CI requires `released` and `completed`.

## Scenario And Evidence

The gate independently asserts protected inquiry admission, exact and changed retries, identity injection rejection, venue-scoped idempotency, private authorization, assignment, stale versions, valid and invalid transitions, successful and conflicting message retries, performance/deal selection, holds, confirmation, canonical conversion and retry, reschedule, linked cancellation, timezone alignment, source uniqueness, configuration revision conflicts, cross-site rendering/context restoration, and a real two-connection one-winner compare-and-swap race.

Every run writes the following under `artifacts/booking-network-e2e` by default:

- `result-envelope.json` classifies `passed`, `invariant_findings`, or `runtime_failure`.
- `campaign-result.json` contains all product cases and findings when the scenario ran.
- `case-log.jsonl` contains one replayable record per assertion.
- `coverage-summary.json` records target and operation coverage.
- `provenance.json` records component revisions, runtime versions, database image, rig runner, and WP Codebox version.
- `replay.json` contains the deterministic replay command.
- `runtime-result.json` and `wp-codebox/` preserve WP Codebox runtime, recipe, command, service, and teardown evidence.
- `wp-codebox.stderr.log` preserves exact infrastructure diagnostics.

A missing campaign marker is a harness/runtime failure, never a product finding and never a passing skip. Product invariant failures are emitted before the assertion process exits, so the result envelope can distinguish them from provisioning, activation, timeout, or runtime failures.
