# Calendar and venue discovery

All paths below are relative to `/wp-json/extrachill/v1`. These GET routes have `permission_callback: __return_true`; route affinity runs them on Events. Ability permissions still apply. The owning plugin is `extrachill-events`, not the external Data Machine plugin mentioned in some file headers. Results are returned unchanged unless noted. Missing abilities return `ability_not_found` (500); ability errors propagate.

## GET /events/calendar

**Source:** `inc/routes/events/calendar.php`.

**Parameters:** optional `page` (integer, minimum 1, default 1); `venue`, `promoter`, `location`, `event_type` (slug strings); `scope` (`today`, `tonight`, `this-weekend`, `this-week`); `lat`, `lng` (numbers); `radius` (integer miles, default 25); `search` (string); `past` (boolean, default false). Only non-null values are forwarded.

**Ability:** `extrachill/events-calendar`.

**Response:** `{dates: [{date, label, events: [...]}], total, page, has_more}`. Each event includes `source` (`{url, name}` when provided upstream, otherwise `null`). When the hidden `data-machine-events/resolve-ticket-destination` ability identifies an affiliate event, its `ticket_url` becomes null and `ticket_ref` is set to the event ID. Clients use the ticket redirect route rather than a bulk affiliate URL. Non-affiliate URLs, and events whose resolution fails or is unavailable, remain unchanged.

## GET /events/filters

**Source:** `inc/routes/events/filters.php`. **Parameters:** none.

**Ability:** `extrachill/events-filters`. **Response:** filter-options object containing venue and promoter term lists.

## GET /events/geocode

**Source:** `inc/routes/events/geocode.php`. **Parameters:** required `q` (sanitized string).

**Ability:** `extrachill/events-geocode`. **Response:** array of geocoding search results for address autocomplete, as returned by the ability.

## GET /events/venues

**Source:** `inc/routes/events/venues.php`.

**Parameters:** optional `location` (slug string); `sw_lat`, `sw_lng`, `ne_lat`, `ne_lng` (numeric bounds); `lat`, `lng` (numeric center); `radius` (integer miles, default 25).

**Ability:** `extrachill/events-list-venues`. **Response:** array of venue map records, not a paginated REST envelope.

## GET /events/venues/(?P<id>\d+)

**Parameters:** required path `id` (integer venue term ID, sanitized with `absint`).

**Ability:** `extrachill/events-get-venue`. Despite the registration comment claiming direct term access, the callback executes this ability. **Response:** single venue detail object, unchanged from the ability.

## GET /events/venues/check-duplicate

**Parameters:** required `name` (string), optional `city` (string). Empty city is not forwarded.

**Ability:** `extrachill/events-check-venue-duplicate`. **Response:** array of matching venue details, empty when no match is found.
