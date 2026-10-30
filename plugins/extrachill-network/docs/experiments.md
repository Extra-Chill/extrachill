# Experiment Lifecycle

Extra Chill Network owns experiment definition validation, lifecycle state, allocation, and trusted assignment/exposure hooks. Consumers own rendering and eligibility. Identity and privacy remain provider filters, Analytics owns measurement persistence, and Extra Chill Users remains the authorization and rollout owner. An experiment assignment can never grant access denied by a consumer.

## Definition Contract

Consumers register code-owned definitions with `extrachill_experiment_definitions`:

```php
add_filter(
	'extrachill_experiment_definitions',
	static function ( array $definitions ): array {
		$definitions['example-experiment'] = array(
			'key'                  => 'example-experiment',
			'definition_version'   => 1,
			'assignment_policy'    => 'weighted_random',
			'default_state'        => 'inactive',
			'default_variant'      => 'control',
			'control_variant'      => 'control',
			'variants'             => array( 'control' => 50, 'treatment' => 50 ),
			'surfaces'             => array( 'example-surface' ),
			'eligibility_callback' => 'example_consumer_is_eligible',
		);

		return $definitions;
	}
);
```

The stable key must match the filter array key. Versions are positive integers up to `1,000,000`. `weighted_random` is the only policy. The default and control must be the same declared variant. Consumers must explicitly review `default_state`; use `inactive` for new experiments. The first `geo-bridge-holdout` definition is inactive by default and requires an explicit operator transition before allocation starts.

Hard registry bounds are 64 registered definitions, 64 variants per definition, and 64 surfaces per definition. An over-bound registry or definition is rejected rather than partially admitted.

The pre-lifecycle keyed shape is normalized as version `1`, policy `weighted_random`, and default state `active` to preserve the merged assignment behavior during migration. Consumers should move to the explicit contract when next edited.

## Live State

One network option, `extrachill_experiment_lifecycle`, stores only current overrides:

```php
array(
	'example-experiment' => array(
		'definition_version' => 1,
		'state'              => 'paused',
	),
)
```

Valid transitions are `inactive -> active`, `active -> paused`, `paused -> active`, `active -> completed`, and `paused -> completed`. Repeating the effective state is idempotent. A completed definition version is terminal. A higher code definition version resets to its reviewed default and may be activated as a new run.

Missing state uses the code default. Corrupt or future-version state fails closed for the affected registered experiment without disabling unrelated experiments. Stored keys whose code definitions were removed are ignored during resolution. `extrachill/list-experiments` returns an envelope containing registered items/count, up to 64 normalized orphan samples, a bounded orphan count, truncation status, and an over-bound option flag. Its item output is capped at 128. The next authorized state write prunes every orphan record and repairs the targeted version while preserving unrelated registered state. Option keys cannot be supplied by callers; lifecycle writes resolve only registered code definitions.

Lifecycle option writes acquire a network-scoped MySQL advisory lock with `GET_LOCK(..., 0)` before reading the option. After acquisition, Network reads the exact `site_id` and `meta_key` row directly from `wpdb->sitemeta`, unserializes it, and normalizes that durable snapshot without consulting option filters or caches. Before calling `update_site_option()`, Network aligns Core's cache with the durable snapshot so Core cannot short-circuit against stale state. After the API write, Network verifies the durable row exactly equals the intended option, then restores and verifies Core's exact `network_id:extrachill_experiment_lifecycle` value and absence of the option from `network_id:notoptions` in the `site-options` group. Any database, cache, lock, or verification failure returns an error and suppresses the audit action; `RELEASE_LOCK()` is guaranteed in `finally`.

## Public Helpers

- `extrachill_experiment_is_active( $key, $surface, $context )` checks current lifecycle, registered surface, and consumer eligibility before rendering or enqueueing.
- `extrachill_experiment_attributes( $key, $surface, $context )` returns cache-neutral control attributes and enqueues the assignment client only for an active, consumer-eligible experiment.
- `extrachill_resolve_experiment_assignment( $key, $surface, $context )` returns an empty variant for lifecycle no-ops. Once active, consumer eligibility, subject, or privacy failure returns cache-neutral control unmeasured.

Inactive, paused, completed, unregistered, and invalid experiments are true no-ops: no assigned variant, experiment attributes, client enqueue, assignment request, token, assignment/exposure hook, DOM event, or downstream Analytics event. The consumer's normal feature behavior continues unchanged; Network does not force control or treatment while lifecycle is non-active. Active but consumer-ineligible or privacy-excluded requests retain cache-neutral control delivery without measurement.

## Abilities And Hooks

Public browser abilities:

- `extrachill/resolve-experiment-assignment`
- `extrachill/record-experiment-exposure`

Private lifecycle abilities require `manage_network_options` for REST and web callers. Trusted local `WP_CLI` execution is also allowed because shell automation commonly runs as WordPress user `0`. Cookie-authenticated REST requests pass WordPress REST nonce validation before the Ability permission callback:

- `extrachill/list-experiments`
- `extrachill/transition-experiment-state`

Assignment responses, exposure requests, signed proofs, DOM events, and trusted `extrachill_experiment_assignment` / `extrachill_experiment_exposure` metadata include `definition_version` and `assignment_policy`. State changes emit `extrachill_experiment_state_changed` with only the experiment key, definition version, previous state, and new state for optional audit integration; Network adds no audit storage.

The coordinated dependency fixtures in `tests/blog-experiment-contract.fixture.json` and `tests/analytics-experiment-contract.fixture.json` lock Blog #73's exact inactive geo definition/no-op boundary and Analytics PR #212's exact trusted hook metadata/version boundary.
