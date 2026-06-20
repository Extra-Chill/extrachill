#!/usr/bin/env bash
#
# Deploy step for the events homepage router (PR #187).
#
# Removes the data-machine-events/calendar block from the events.extrachill.com
# homepage (page_on_front, post ID 5) and updates the intro copy. The router
# badges + "Browse all cities" link render ABOVE this content via the
# extrachill_events_home_before_calendar hook, so once the calendar block is
# gone the homepage becomes a clean router. The full calendar lives at /all.
#
# Idempotent: re-running just re-sets the same content. Run AFTER PR #187 is
# merged and deployed (so /all and /location/ routes exist), then flush rewrites.
#
# Usage:  bash scripts/deploy-homepage-router.sh
#
set -euo pipefail

WP="wp --path=/var/www/extrachill.com --url=events.extrachill.com"
HOME_ID="$($WP option get page_on_front)"

if [ "$HOME_ID" != "5" ]; then
	echo "WARNING: page_on_front is $HOME_ID, expected 5. Verify before proceeding." >&2
fi

# New homepage content: intro paragraph only. No calendar block — the router
# badges render via hook, and the firehose lives at /all.
read -r -d '' NEW_CONTENT <<'EOF' || true
<!-- wp:paragraph -->
<p>Find live music near you. Pick a city below, <a href="/location/">browse all locations</a>, or <a href="/all/">see every event</a>.</p>
<!-- /wp:paragraph -->
EOF

# Back up current content first.
BACKUP="/tmp/events-homepage-content.$(date +%Y%m%d%H%M%S).html"
$WP post get "$HOME_ID" --field=post_content > "$BACKUP"
echo "Backed up current homepage content to $BACKUP"

# Apply new content.
$WP post update "$HOME_ID" --post_content="$NEW_CONTENT"
echo "Homepage content updated (calendar block removed)."

# Flush rewrite rules so /all and /location/ resolve.
$WP rewrite flush
echo "Rewrite rules flushed."

echo
echo "Done. Verify:"
echo "  https://events.extrachill.com/        (router: badges + browse link, no firehose)"
echo "  https://events.extrachill.com/location/ (region-grouped directory)"
echo "  https://events.extrachill.com/all/    (full calendar)"
