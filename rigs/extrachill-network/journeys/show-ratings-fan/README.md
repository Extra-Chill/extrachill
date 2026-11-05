# show-ratings-fan

A fan privately rates attended past shows on My Shows at 390×844.

## Walk and seed

`seed.php` creates two past Goose shows (30 and 90 days ago), a My Shows page,
and subscriber accounts 711 (owner) and 712 (non-owner). Both shows are attended;
the recent show is unrated and the older show is seeded with 5 stars through
`extrachill/rate-show`. Seeding resets these ratings on repeated runs. The fan's
history is explicitly public so privacy checks exercise real, populated history,
not an access-denied page.

The owner opens **My Shows → Past**, taps **Rate**, picks 4, changes to 3,
reloads, taps the current star to clear, and reloads again. The seeded show must
already display five filled stars, without a Rate prompt (#1055).

An anonymous visitor and the second subscriber separately visit the fan's
`/my-shows/?tab=past&user_id=711`, the seeded event, and the artist past list.
Neither should receive rating UI or values. The seeded rating remains in place
throughout these privacy walks.

## Grade

The sandbox-only observation sink records browser state and, at every checkpoint,
reads the fan's real private store through `extrachill/get-my-show-ratings`.
Server truth is retained only in test evidence, never returned to the browser.
Missing observations are findings, never passes or skips. Mail is suppressed.

| id | checks |
| --- | --- |
| quiet-rate-button | unrated attended show has Rate, no empty stars |
| private-ratings-note | “Only you see your ratings…” is present |
| seeded-rating-renders | seeded 5 renders as five filled stars, not Rate |
| rate-reveals-stars | Rate reveals five stars without changing storage |
| owner-four | browser and private store both show 4 |
| owner-three | changing to 3 updates browser and private store |
| owner-reloaded-three | reload still shows three filled stars |
| owner-cleared | tapping current star clears storage and restores Rate |
| owner-reloaded-clear | cleared state survives reload |
| visitor-history-private | anonymous populated fan history has no rating nodes |
| visitor-event-private | anonymous event has no rating nodes |
| visitor-artist-private | anonymous artist past list has no rating nodes |
| other-history-private | second subscriber's populated fan history has no rating nodes |
| other-event-private | second subscriber's event view has no rating nodes |
| other-artist-private | second subscriber's artist past list has no rating nodes |

Server validation rules are covered by
`plugins/extrachill-users/tests/unit/ShowRatingsTest.php`, not duplicated here.

## Generate and validate

```sh
python3 rigs/extrachill-network/journeys/show-ratings-fan/build-journey.py
python3 rigs/extrachill-network/journeys/show-ratings-fan/test-journey.py
```

`build-journey.py` is the source for `journey.json` and works from any directory.
Select with `"extrachill_journeys": ["show-ratings-fan"]`; the id remains in the
nightly default set. Historical evidence under `evidence/` predates this rewrite
and is not evidence that the current journey has been run.
