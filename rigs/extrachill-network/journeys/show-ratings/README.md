# Show ratings

Review requested by [network #375](https://github.com/Extra-Chill/extrachill-network/issues/375), following the Gardner RSVP and near-me/My Shows journeys. Runs after the full 11-site baseline, not against a mocked page.

## Fixture and persona

A synthetic regular show-goer, not a pinned persona: this review has functional acceptance criteria and a card-navigation research question, not a canonical persona's private history or oracle. Seed owns two subscriber fixtures (751 owner, 752 viewer), past event `ratings-past`, already-attended/unrated past event `ratings-inline`, and future event `ratings-future`. Dates are relative to boot time. Each event has artist, venue, and city terms. Pages and users are reused on reseed; use a fresh disposable boot to reset ratings/attendance. No product behavior is stubbed. Site identity resolves by domain.

## Walk and grading

- Anonymous event on phone (390×844) and desktop (1440×1000): capture rating/login copy and CTA hrefs.
- Owner: record gate, mark I Was There, assert form appears and a window sentinel survives (no reload), rate 4, navigate again and verify stars persisted.
- Own past My Shows, both sizes: record each card link/control's text, label, raw/resolved href, and CSS-pixel bounding box. Includes the hint even when it is not a link. No assertion that the entire card or artist *should* navigate to any particular destination.
- Rate the seeded unrated show inline at 3: assert prompt/count changes, pressed state, and persistence after navigation.
- Viewer: load owner's public past history; require a rendered card before asserting there are no rating controls.
- Anonymous ability REST write: require 401/403, not a missing-route 404.
- Grade first checks browser-created ratings without writing them. Then independently checks non-attendee rejection, future rejection even after attendance, sequential double submission (one review), changing the rating, and anonymous primitive rejection. Sequential submissions do not claim concurrent race coverage.

Browser assertions grade unlock, count changes and public controls; PHP checks persistence and write invariants. Browser steps allow continuation for evidence collection; inspect their failures independently from the final PHP result. Missing APIs/fixtures fail loudly; no skipped step becomes a pass.

## Run and evidence

```sh
git clone https://github.com/Extra-Chill/extrachill /tmp/show-ratings-theme
homeboy rig install "$PWD"
HOMEBOY_SETTINGS_JSON='{"extrachill_theme_source":"/tmp/show-ratings-theme","extrachill_journeys":["show-ratings"]}' homeboy rig up extrachill-network
node --test rigs/extrachill-network/tests
```

Captures include screenshots, HTML, DOM snapshots, network, console, errors, and evaluate results. Screenshot names are in `journey.json`; link measurements appear in `card-targets-phone` and `card-targets-desktop` evaluate output. Curate those outputs and screenshots under `evidence/` after a successful boot, then update FINDINGS with observed destinations, target sizes, and duplicate-searched owning-repository issue links. Current evidence records a pre-browser provisioning failure; there are no screenshots or measured card targets from that attempt.
