#!/usr/bin/env bash
#
# Deploy step for the events homepage.
#
# Empties the events.extrachill.com homepage (page_on_front, post ID 5) post
# content. The homepage is fully template-driven now: intro copy, city badges,
# and the My Shows / Submit feature cards all render via the homepage template
# + the extrachill_events_home_before_calendar hook. The full calendar lives at
# /all. Any leftover blocks in the post content (old intro paragraph, calendar
# block) would render as orphaned content below the cards, so we clear it.
#
# Idempotent: re-running just re-clears the content. Run AFTER the matching
# plugin version is deployed, then flush rewrites so /all resolves.
#
# Usage:  bash scripts/deploy-homepage-router.sh
#
set -euo pipefail

WP="wp --path=/var/www/extrachill.com --url=events.extrachill.com"
HOME_ID="$($WP option get page_on_front)"

if [ "$HOME_ID" != "5" ]; then
	echo "WARNING: page_on_front is $HOME_ID, expected 5. Verify before proceeding." >&2
fi

# Back up current content first.
BACKUP="/tmp/events-homepage-content.$(date +%Y%m%d%H%M%S).html"
$WP post get "$HOME_ID" --field=post_content > "$BACKUP"
echo "Backed up current homepage content to $BACKUP"

# Clear the post content — the homepage is template-driven.
$WP post update "$HOME_ID" --post_content=""
echo "Homepage post content cleared (template-driven now)."

# Flush rewrite rules so /all resolves.
$WP rewrite flush
echo "Rewrite rules flushed."

echo
echo "Done. Verify:"
echo "  https://events.extrachill.com/     (intro + stats + city badges + feature cards)"
echo "  https://events.extrachill.com/all/ (full calendar)"
