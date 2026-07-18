# Geographic Bridge Holdout Report

`extrachill/get-geo-bridge-experiment` reports only `geo-bridge-holdout` on
`single-post-bridge`, with the declared `control` and `treatment` variants. It
is a private, read-only ability and is not a general experimentation API.

## Event Contract

- `experiment_assignment` enters an identified person into a variant denominator.
- `experiment_exposure` records actual server-validated 50% viewport exposure.
- `bridge_click` remains an independently lossy stored event. Its contract does
  not identify the rendered card or taxonomy, so it is reported only as broad
  `any_bridge_click_after_assignment` as intent-to-treat network engagement.
  `any_bridge_click_after_exposure` is descriptive and exposure-conditioned,
  not intent-to-treat. Click events are neither deduplicated nor clamped, while
  unique clickers are reported separately.
- `pageview` supplies the first identified cross-blog route transition after each
  assignment and exposure anchor.
- `newsletter_signup`, `user_registration`, `onboarding_completed`, and
  `artist_profile_first_publish` are the only authoritative lifecycle outcomes.
  Automatic registration newsletter subscriptions remain excluded.

Every experiment row must carry exactly the canonical experiment key, surface,
and one declared variant. Assignment never implies exposure, and clicks or
pageviews never synthesize either event.

## Attribution

Rows are ordered by `created_at ASC, id ASC`. The first valid assignment fixes a
person's variant; duplicate and conflicting rows are diagnostics. Exposure must
strictly follow a matching assignment. Any-bridge clicks, route transitions,
and outcomes must strictly follow the applicable anchor, so pre-assignment
outcomes never attribute. A later artist or festival card click remains in the
broad any-bridge outcome and is never presented as a geographic-card click.

Bot-stamped rows are removed before identity observation. Remaining identity
resolves from payload `user_id`, stored `user_id`, then `visitor_id`. A visitor
stitches to a user only when the bounded window observes exactly one user for
that visitor. Ambiguous visitors remain separate. Lifecycle outcomes deduplicate
once per person, event type, and assignment/exposure lens.

Same-session attribution extends while identified pageviews remain within the
configured inactivity gap. The first later cross-blog pageview is classified as
a later-session transition. The report does not claim adjacency to an
unobserved browser action.

## Bounds And Coverage

The reader scans one explicit UTC window with stable `(created_at, id)` keyset
pagination, 500 rows per page, and a configurable hard `max_events` ceiling.
One extra row is used only as a truncation sentinel. Truncated output retains
observed counts and labels them truncated.

Each signal has independent instrumentation coverage. Missing event types and a
missing assignment cohort produce `null` rather than measured zero. Unidentified
rows, bot-stamped rows, ambiguous visitor mappings, rejected contract rows, and
unattributed exposures remain explicit diagnostics. GPC/DNT requests emit no
measured assignment or exposure and may omit visitor identity, so their excluded
population is intentionally not inferable from stored rows.

`card_specific_click_instrumented` is always `false`. The current event contract
cannot isolate the geographic treatment card, and the report states that gap
next to every broad any-bridge click count.
