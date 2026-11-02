# Photo contest night journey

Two phone-sized (390×844) personas walk a live `extrachill/photo-contest` page on extrachill.com.

1. **entrant-at-venue**: lands on the contest page and enters with email, display name, Instagram handle and a camera-roll JPEG. The upload goes through the real multipart upload and the Turnstile-guarded `extrachill/v1/content-blocks/photo-contest/submit` route. The entrant should then land on the share panel with a personal link and an in-person QR code (from `extrachill/v1/tools/qr-code`).
2. **friend-voter**: at the venue or not, opens an earlier entrant's personal share link (`?entry=<id>`), votes with an email, taps again (which must not double-count), then taps "See all entries" and votes for a second costume using the remembered email.

## Seed decisions
- The contest page is recreated by domain. Entries and voting are open now, with a backstop close three days out and finalists display on.
- Three earlier entrants are submitted through `extrachill_content_blocks_photo_contest_submit_entry()`. Only the photo store seam is substituted, with a sideload, because a run-php process has no HTTP upload. The browser entrant uses the real upload path.
- Turnstile uses Cloudflare's always-pass test keys, plus the product's `extrachill_bypass_turnstile_verification` seam, because an automated browser cannot solve a challenge.

`build-journey.py` generates `journey.json`. Edit the script, not the JSON.
