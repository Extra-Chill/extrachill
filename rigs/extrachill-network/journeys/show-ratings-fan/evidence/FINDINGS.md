# show-ratings-fan findings

Run date: 2026-10-29. Uses the released stack plus overrides for extrachill-users#481, extrachill-events#1034 (stacked on #1025), and data-machine-events#1032.

## Direction change

Show ratings are personal and private now. The public, comment-backed reviews this journey used to grade were removed in data-machine-events#1032. The fan rates their own past shows on My Shows. Ratings are stored on their attendance record (extrachill-users) and are never shown publicly.

## Last run

12 checks, 12 passed. Server truth: the fan holds 5 on the recent show and 3 on the older one, and the events have no comments. `result.json` is the grade output from that run.

- `my-shows-past-unrated-390px.png` shows the quiet "Rate" buttons and the privacy line.
- `my-shows-past-rated-390px.png` shows the fan's filled stars after a reload.
- `visitor-sees-no-rating.png` shows the rated show logged out, with no rating anywhere.

## Against main

On today's released stack, the public review UI and the row/name links fail the corresponding checks. See the PR body.
