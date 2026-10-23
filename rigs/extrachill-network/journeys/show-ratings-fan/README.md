# show-ratings-fan

A non-technical fan rates the shows they went to, on a phone (390px).

## What it walks

1. **Logged-out visitor** opens a past Goose show at the Charleston Pour House and is asked to log in to rate it.
2. **Fan (user 711)** opens the same show. The rating form is locked behind "Only people who were there…" (Extra Chill's attendee-only policy). They tap **I Was There**, wait for the "Rate this show ↓" link, follow it, pick 5 stars, write a short review and post. Then they reload.
3. **My Shows → Past**: the older show (already in their history, as an import leaves it) offers stars. The fan rates it 4, changes their mind to 3, and reloads.
4. The fan revisits the older show page.
5. **Logged-out visitor** browses the Goose artist page (upcoming, then `?past=1`) and the show page to read the review.

## Seed

`seed.php` creates two past Goose shows at the Charleston Pour House (30 and 90 days ago), a My Shows page, and the fan at user 711. The fan is marked "I Was There" on the older show only. Sites are resolved by domain. `sandbox-fixture.php` stops mail and hosts the observation sink (`/?ec_show_ratings_observe=1`) that browser steps post their readings to.

## Grade

`grade.php` combines browser observations with server truth (the fan's `event_review` comments, `_dme_rating`, and the aggregate meta). It grades against the nontechnical oracles of the Gardner persona: obvious state, reload persistence, duplicate prevention, and jargon avoidance. There is no persona file. The fan is a single-scenario fixture user.

| id | checks |
| --- | --- |
| visitor-told-how-to-rate | logged-out note on a past show |
| gate-explains-itself | locked form shows one plain sentence, no dead stars |
| form-unlocks-in-place | stars appear after I Was There without a reload |
| review-posts-immediately | approved, 5 stars, no moderation notice |
| reload-persistence | "Your rating" + summary after reload |
| duplicate-prevention | one review per fan per show |
| my-shows-offers-rating | stars on My Shows → Past |
| my-shows-change-mind | 4 → 3 leaves one 3-star review |
| listing-shows-rating | ★ badge on the artist page past list |
| visitor-sees-review | review text visible logged out |
| rate-it-link-finds-form | link under I Was There scrolls to the stars |
| logged-out-copy-says-rate | "Log in to rate this show." + CTA heading |
| reviews-heading | list heading reads "1 review" |
| post-rating-form-is-for-comments | form heading "Add a comment" after rating |

Select it with `"extrachill_journeys": ["show-ratings-fan"]`. It is in the nightly default set.
