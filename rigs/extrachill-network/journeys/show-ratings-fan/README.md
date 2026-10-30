# show-ratings-fan

A non-technical fan rates the shows they went to, on a phone (390px). Ratings are personal: only the fan sees them, on My Shows, and nothing about ratings is public.

## What it walks

1. **Logged-out visitor** opens a past Goose show at the Charleston Pour House and sees no ratings, stars, or "rate this show" prompts.
2. **Fan (user 711)** opens the same show and taps **I Was There**. The show page still offers no rating or review form.
3. **My Shows → Past**: both shows offer a quiet **Rate** button instead of empty stars, plus one line saying ratings are private. The fan rates the recent show 5, then rates the older show (already in their history, as an import leaves it) 4 and changes it to 3. Then they reload.
4. The fan opens a show from My Shows: once by tapping the empty part of the row, once by tapping the show name.
5. **Logged-out visitor** browses the Goose artist page (`?past=1`) and the rated show page and still sees no ratings.

## Seed

`seed.php` creates two past Goose shows at the Charleston Pour House (30 and 90 days ago), a My Shows page, and the fan at user 711. The fan is marked "I Was There" on the older show only. Sites are resolved by domain. `sandbox-fixture.php` stops mail and hosts the observation sink (`/?ec_show_ratings_observe=1`) that browser steps post their readings to.

## Grade

`grade.php` combines browser observations with server truth: the fan's private ratings from `ec_users_get_show_ratings()` and the event's comment count. It grades against the nontechnical oracles of the Gardner persona: obvious state, reload persistence, and a calm UI. There is no persona file. The fan is a single-scenario fixture user.

| id | checks |
| --- | --- |
| visitor-sees-no-ratings | no rating UI, stars, or prompts logged out |
| show-page-has-no-rating-form | none after I Was There either |
| my-shows-no-empty-stars | unrated rows show "Rate", not empty stars |
| my-shows-no-nag | one privacy note, no "need your rating" line |
| my-shows-rates-privately | server holds 5 and 3 on the fan's records |
| my-shows-star-stays-put | rating does not navigate away |
| reload-persistence | filled stars 5 and 3 after reload |
| my-shows-row-opens-show | row tap opens the show |
| my-shows-name-opens-show | name tap opens the show, not the artist |
| listings-show-no-ratings | artist page past list has no badges |
| rated-show-stays-private | the rated show page shows nothing logged out |
| no-review-comments | rating writes no comments |

Select it with `"extrachill_journeys": ["show-ratings-fan"]`. It is in the nightly default set.
