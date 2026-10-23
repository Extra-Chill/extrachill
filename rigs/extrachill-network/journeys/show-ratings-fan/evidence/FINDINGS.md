# show-ratings-fan findings

Run date: 2026-10-23, against the released stack plus overrides that were merged the same day.

## Product findings (all fixed)

| Finding | Fixed in |
| --- | --- |
| No published comment rendered anywhere on the network: the theme required its comments template directly and skipped `comments_template()`, so show reviews were invisible to everyone | Extra-Chill/extrachill#109 → #110 |
| After "I Was There" the form unlocked about 3 phone screens down with no pointer to it | Extra-Chill/extrachill-events#1013 |
| Logged-out copy on past shows said "comment", not "rate" | extrachill#112, extrachill-users#476, extrachill-events#1013 |
| The reviews list was headed "One comment on …" | extrachill#112, extrachill-events#1013 |
| After rating, the form reverted to "Leave a Comment" | Extra-Chill/data-machine-events#977 |

## Open

- The "Attendance saved." status is oversized and squeezes the button at 390px. Tracked in Extra-Chill/extrachill-users#477; not graded here.

## Last run

14 checks. 12 passed. `form-unlocks-in-place` and `rate-it-link-finds-form` read too early: a fixed 5s wait ran before the attendance save resolved. Both passed on the follow-up observation. The journey now waits for the link selector instead. `result.json` is the grade output from that run.
