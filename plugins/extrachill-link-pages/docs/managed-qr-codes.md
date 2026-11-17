# Managed QR codes

The public QR generator block at `/qr-code-generator` includes a team-grouped “My QR codes” panel. `?view=mine` opens it directly; anonymous visitors receive join/login links with a return URL. Active team members can create, rename, retarget, copy, download and delete codes. The static generator remains available to everyone. Scan counts are presented as fan reach over the last 90 days, not lifetime analytics.

## Storage and authorization

`ec_qr_code` posts exist only on `ec_get_blog_id( 'link_pages' )`. Public access and the admin post editor are disabled. Post author is the creator, not the owner. `team_type`, `team_id`, and `destination` post meta store ownership and the HTTP(S) destination; the post title stores the label. The random 12-character slug is immutable through the abilities.

Every operation checks current active membership through `TeamMembershipRepository`, or permits a network admin. Listing returns codes across all active memberships, with team fields for grouping. Network admins can operate on any known code; listing still enumerates their active memberships. Creates are serialized with the existing advisory-lock helper and limited to 10 published codes per team (`EC_MANAGED_QR_FREE_QUOTA`). A team with any active member who is an Extra Chill supporter (`ec_is_supporter()`) is unlimited. Override with the `ec_managed_qr_team_quota( $quota, $team_type, $team_id )` filter, where 0 means unlimited. The quota only limits creation: codes over the cap stay editable. Trashing frees quota. `list` returns per-team `quota` / `used` / `unlimited`.

## Transport

Authenticated core abilities, deliberately `show_in_rest: true`:

- `extrachill/create-managed-qr-code`: `team_type`, `team_id`, `destination`, optional `label`.
- `extrachill/list-managed-qr-code`: empty input; returns `items`.
- `extrachill/update-managed-qr-code`: `id`, optional `destination` and/or `label`.
- `extrachill/delete-managed-qr-code`: `id`; trashes the post.
- `extrachill/get-stats-managed-qr-code`: `id`; returns the authorized record including `scans`.

Use core `/wp-abilities/v1/abilities/<name>/run` with the normal authenticated REST nonce. No feature-owned REST namespace or main-site storage is introduced. Returned records include `short_url`; pass it to existing `extrachill/generate-qr-code` to obtain a PNG.

## Resolution and analytics

The existing public runtime's special-route filter resolves `/q/<code>` to a non-cacheable 302. Missing, trashed, or invalid destinations fall back to the link-pages home URL. No management UI is served on that host.

Scans use Analytics' existing link-page click writer and daily table, keyed by QR post ID and immutable short URL. No new table or post-meta counter. `scans` sums retained daily rows (currently 90 days), not lifetime scans. QR IDs occupy the same storage-site posts namespace as link-page IDs and cannot collide with a link-page post. Counts are stable through destination and label changes. The later team analytics UI can group QR records using their team fields. Analytics must be active for recording/reporting; redirects remain available if it is not.

## Tests

UI tests: run `node --test tests/qr-code-generator.test.js` from `plugins/extrachill-content-blocks`. They cover the static generator, onboarding states, team grouping, core ability mutations, confirmation and errors.

Run `php tests/managed-qr-codes-smoke.php`, or `composer test` from `tools/`. The standalone smoke exits nonzero on failure and covers the issue's backend gate plus cross-blog isolation, list visibility, anonymous access, trash fallback and core ability registration. It stubs WordPress, membership persistence and the analytics writer; real WordPress/database integration is not claimed.
