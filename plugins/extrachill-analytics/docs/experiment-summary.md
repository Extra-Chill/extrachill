# Generic Experiment Summary

`extrachill/get-experiment-summary` is a private, read-only Ability for one
code-registered experiment. It is not a browser ingestion endpoint, assignment
system, lifecycle store, or winner selector. The existing
`extrachill/get-geo-bridge-experiment` report remains the domain-specific source
for geographic broad-click, route, and lifecycle interpretation.

## Trusted Persistence

Only Network's trusted `extrachill_experiment_assignment` and
`extrachill_experiment_exposure` server hooks persist experiment rows. Their
payload must contain exactly these bounded fields, in order:

- `experiment_key`: lowercase identifier, 1-64 characters.
- `definition_version`: positive integer, at most 1,000,000.
- `assignment_policy`: lowercase identifier, 1-64 characters.
- `variant`: lowercase identifier, 1-64 characters.
- `surface`: lowercase identifier, 1-64 characters.

Assignment and exposure remain distinct. GPC/DNT requests emit neither. The
generic `extrachill/track-analytics-event` Ability explicitly rejects both
experiment event types, even though that Ability remains available for trusted
non-experiment server emitters.

## Inputs And Bounds

The summary requires exactly one `experiment_key`, `control_variant`, and a
declared unique `variants` list. `definition_version` is optional: when omitted,
the report discloses and aggregates observed versions while preserving each
person's first versioned assignment. `outcome_event_types` is optional and may
contain only code-declared canonical Analytics outcomes.

- `variants`: 2-8.
- `outcome_event_types`: 0-10.
- `days`: 1-90, default 28.
- `attribution_window_days`: 1-90, default 28.
- `session_gap_mins`: 1-120, default 30.
- `max_events`: 100-100,000, default 50,000.

Rows use ascending `(created_at, id)` keyset pagination in pages of 500. One
extra row is read only as a truncation sentinel. All identity, assignment,
exposure, and outcome maps derive from the bounded retained rows.

## Attribution And Output

Bot rows are removed first. Experiment rows then pass key, version, policy,
variant, and surface contract admission before identity mapping, so rejected
rows cannot create visitor/user edges. Payload `user_id`, stored `user_id`, and
then `visitor_id` resolve a person. A visitor stitches to a user only when
exactly one admitted user is observed in the bounded window; ambiguous visitors
remain unmerged.

The first valid assignment fixes a person's variant and definition version.
Exposure requires a matching event strictly after assignment and is never
manufactured. Outcomes must be strictly after their anchor and within the
attribution window. Each person/outcome/lens counts once, so duplicates do not
inflate rates and pre-assignment outcomes never attribute.

Each variant reports assignment people/events, descriptive exposure
people/events/rate, canonical outcome people/rates after assignment, and
optional descriptive exposure-conditioned outcomes. Same/later-session outcome
people use identified pageviews and the configured inactivity gap.

Intent-to-treat outcome rates use assigned people. Rate confidence intervals
are 95% Wilson score intervals. Absolute lift against control uses the
Newcombe-Wilson hybrid-score difference-of-proportions interval. Relative lift
uses a Katz log risk-ratio interval only when both arms have nonzero successes
and nonzero failures. Boundary and otherwise unsupported relative intervals are
`null` with `insufficient_data`; zero denominators remain explicit. The Ability
never declares a winner.

Coverage discloses missing instrumentation/no data, truncation, bot and
unidentified rows, ambiguous visitor IDs, invalid/other experiment rows,
duplicate/conflicting assignment and exposure rows, pre-assignment outcomes,
surfaces, assignment policies, and definition-version mixing. GPC/DNT exclusions
are intentionally not inferable from stored analytics.
