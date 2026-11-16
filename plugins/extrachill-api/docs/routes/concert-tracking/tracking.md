# Concert tracking

Paths are relative to `/wp-json/extrachill/v1`. Source: `inc/routes/events/concert-tracking.php`. Owner: `extrachill-users`. Every callback executes the named ability and returns its payload unchanged (200); errors propagate, and missing abilities return `ability_not_found` (500). Public route admission does not override owner privacy rules.

## POST /concert-tracking/toggle

**Permission:** `is_user_logged_in`.

**Parameters:** required `event_id` integer; optional `blog_id` integer (0, owner defaults to Events). Both use `absint`. No client user ID is forwarded.

**Ability:** `extrachill/toggle-event-mark`. **Response:** `{marked, count, count_label, timing}` for the current user.

## GET /concert-tracking/event/(?P<event_id>\d+)

**Permission:** `__return_true`.

**Parameters:** path `event_id` integer (`absint`), optional `include_attendees` boolean (false), `limit` integer (default 10, range 1–100).

**Ability:** `extrachill/get-event-attendance`. **Response:** `{count, count_label, timing, user_marked, attendees: [{user_id, display_name, avatar_url, profile_url}]}`; attendee disclosure is controlled by the ability and `include_attendees`.

## GET /concert-tracking/user/(?P<user_id>\d+)/shows

**Permission:** `__return_true`.

**Parameters:** path `user_id` integer (`absint`); optional `period` (`all` [default], `upcoming`, `past`), `year` integer (0), `date_from`, `date_to` strings (empty), `page` integer (1), `per_page` integer (20, range 1–100).

**Ability:** `extrachill/get-user-shows`. **Response:** `{shows: [...], total, pages, page}`; privacy/visibility is enforced in the ability.

## GET /concert-tracking/user/(?P<user_id>\d+)/stats

**Permission:** `__return_true`.

**Parameters:** path `user_id` integer; optional `year` integer (0), `date_from`, `date_to` strings (empty). IDs/year use `absint`, dates are text-sanitized.

**Ability:** `extrachill/get-user-concert-stats`. **Response:** `{total_shows, unique_venues, unique_artists, unique_cities, top_artists, top_venues, top_cities, shows_by_year}` with the same owner-level privacy boundary.

## GET /concert-tracking/search

**Permission:** `is_user_logged_in`.

**Parameters:** optional `query` string (empty), `period` (`past` [default], `upcoming`, `all`), `page` integer (1), `per_page` integer (20). Query is text-sanitized; pagination uses `absint`. Unlike history/attendee reads, this route declares no explicit pagination bounds.

**Ability:** `extrachill/search-events-for-marking`. **Response:** `{events: [...], total, pages, page}`. Empty query returns no results; upcoming includes ongoing events that have not ended.
