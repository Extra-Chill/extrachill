# Event abilities

The plugin exposes calendar discovery, event ingestion, venue operations, guest lists, ticketing and reporting in the `extrachill-events` category. Abilities are the domain layer: REST, CLI and other transports call the same callbacks.

Fields below come from registration schemas; “optional” means absent from `required`, not that every combination is valid. Outputs describe successful results; callbacks can also return `WP_Error`. Exposure lists direct transport references found in the API route docs and registered CLI adapters.

The issue’s `extrachill/`, `extrachill/list-priority-` and `extrachill/set-priority-` are dynamic registration prefixes, not executable names: their concrete ticket and venue/promoter priority abilities are listed below.

## Artist Coverage

Registration: [`ArtistCoverageAbilities.php`](../inc/Abilities/ArtistCoverageAbilities.php).

### `extrachill/add-artist-coverage`

- **Purpose:** Add an act to the Ticketmaster artist import by name (exact match) or explicit attraction ID.
- **Input:** `name` (string, required); `attraction_id` (string, optional).
- **Output:** `object {attraction_id: string, name: string}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: false`; `wp extrachill events artist-coverage` adapter.
- **Side effects:** Looks up Ticketmaster unless an attraction ID is supplied; adds a manual entry and removes its exclusion in stored configuration.

### `extrachill/configure-artist-coverage`

- **Purpose:** Set the Ticketmaster flow step that artist coverage maintains, the post threshold, and the ID cap.
- **Input:** `flow_step_id` (string, optional); `min_posts` (integer, optional); `max_ids` (integer, optional).
- **Output:** `object {flow_step_id: string, min_posts: integer, max_ids: integer, manual: object, excluded: array<string>}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: false`; `wp extrachill events artist-coverage` adapter.
- **Side effects:** Updates the supplied configuration fields and ensures the coverage sync is scheduled.

### `extrachill/exclude-artist-coverage`

- **Purpose:** Exclude an act (by name or attraction ID) from the Ticketmaster artist import, e.g. a different act sharing the name.
- **Input:** `name` (string, required).
- **Output:** `object {attraction_id: string}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: false`; `wp extrachill events artist-coverage` adapter.
- **Side effects:** Removes the known act from manual coverage and persists its exclusion.

### `extrachill/get-artist-coverage`

- **Purpose:** Show the Artists We Cover configuration and each covered artist's Ticketmaster match.
- **Input:** No fields.
- **Output:** `object {config: object, artists: array<{term_id, name, attraction_id, attraction_name, upcoming, excluded, checked}>, attraction_ids: array<string>}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill events artist-coverage` adapter.

### `extrachill/sync-artist-coverage`

- **Purpose:** Match covered artists to Ticketmaster and write the attraction list to the import flow.
- **Input:** `dry_run` (boolean, optional, default true); `limit` (integer, optional).
- **Output:** `object {dry_run: boolean, covered_artists: integer, checked: integer, still_due: integer, matched: integer, manual: integer, excluded: integer, attraction_ids: integer, written: boolean, errors: array}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: false`; `wp extrachill events artist-coverage` adapter.
- **Side effects:** Calls Ticketmaster for due artists. Unless dry-run, persists matches and writes selected attraction IDs to the configured import flow.

## City

Registration: [`CityAbilities.php`](../inc/Abilities/CityAbilities.php).

### `extrachill/add-city`

- **Purpose:** Add a city to the events calendar. Geocodes the location, creates a pipeline, and sets up Ticketmaster and Dice.fm flows.
- **Input:** `city` (string, required); `radius` (string, optional); `interval` (string, optional); `skip_dice` (boolean, optional); `force` (boolean, optional); `dry_run` (boolean, optional).
- **Output:** `object {message: string, city: string, coordinates: string, pipeline_id: integer, flows: array<any>}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill events add-city` adapter.
- **Side effects:** Geocodes and creates location/pipeline/import flows unless dry-run.

## Rsvp Pass

Registration: [`RsvpPassAbilities.php`](../inc/Abilities/RsvpPassAbilities.php).

### `extrachill/add-guest-list-name`

- **Purpose:** Host-only: put a guest on the event guest list by name, with no password. Issues an account-less pass that counts against the guest list spots and shows on the door list. Optionally emails the pass.
- **Input:** `event_id` (integer, required); `name` (string, required); `email` (string, optional).
- **Output:** `object {code: string, name: string, pass_url: string, qr_url: string, emailed: boolean, spots_left: integer | null}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Issues a host-added guest pass and optionally emails it.

### `extrachill/add-guest-list-names`

- **Purpose:** Host-only: put many guests on the event guest list at once from pasted text or a CSV/TXT file, one name per line. Blank lines are skipped, and names already on the list or repeated in the batch are added once. Each name gets an account-less pass, the same as a single add.
- **Input:** `event_id` (integer, required); `names` (string, required); `format` (string, optional, default "text").
- **Output:** `object {added: integer, already_listed: integer, not_added: integer, names: array<string>}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Bulk adds named guest passes, preserving already-listed entries.

### `extrachill/get-my-event-pass`

- **Purpose:** Get the current user's RSVP perk pass for an event, if any.
- **Input:** `event_id` (integer, required).
- **Output:** `object {issued: boolean, code: string, perk_text: string, status: string}`.
- **Permission:** Must be logged in.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/guest-rsvp-event`

- **Purpose:** RSVP a logged-out guest to a perk-enabled event by email. Resolves or creates a claimable account, marks Going, and emails the pass with a set-password link when the account is unclaimed.
- **Input:** `event_id` (integer, required); `email` (string, required); `referrer` (string, optional); `utm` (object, optional); `password` (string, optional).
- **Output:** `object {status: string}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; [API: guest-rsvp](../../extrachill-api/docs/routes/events/guest-rsvp.md).
- **Side effects:** Resolves or creates a claimable account, marks Going and emails the pass (and claim link when applicable).

### `extrachill/import-eventbrite-attendees`

- **Purpose:** Host-only: put the attendees from an Eventbrite attendee report (CSV) on the event door list by name, labeled Eventbrite. Nobody is emailed and no email addresses are stored. Re-importing a newer report only adds new tickets.
- **Input:** `event_id` (integer, required); `csv` (string, required).
- **Output:** `object {added: integer, already_listed: integer, skipped: integer}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Fetches Eventbrite attendees and adds guest-list entries, skipping existing entries.

### `extrachill/issue-guest-list-pass`

- **Purpose:** Issue a guest list pass for the guest list password alone, with no account. Optionally emails a copy. Used by the event's /guest-list page.
- **Input:** `event_id` (integer, required); `password` (string, required); `name` (string, required); `email` (string, optional).
- **Output:** `object {code: string, name: string, qr_url: string, perk_text: string, emailed: boolean, spots_left: integer | null}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; [API: guest-list-pass](../../extrachill-api/docs/routes/events/guest-list-pass.md).
- **Side effects:** Verifies the guest-list password, issues a pass without an account and optionally emails a copy.

### `extrachill/list-event-door-attendees`

- **Purpose:** List every attendee of any event the caller manages, including private ones. Host/organizer only. Includes RSVP pass redemption state when the event has a perk enabled.
- **Input:** `event_id` (integer, required).
- **Output:** `object {perk_enabled: boolean, perk_text: string, attendees: array<object {user_id: integer, display_name: string, marked_at: string, pass_status: string, redeemed_at: string}>}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/redeem-event-pass`

- **Purpose:** Atomically redeem an attendee's RSVP perk pass at the door. Safe against a double-tap or two hosts redeeming at once.
- **Input:** `event_id` (integer, required); `user_id` (integer, optional); `code` (string, optional).
- **Output:** `object {already_redeemed: boolean, redeemed_at: string, redeemed_by_user_id: integer}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Records atomic pass redemption; repeated redemption returns the existing record.

### `extrachill/remove-guest-list-name`

- **Purpose:** Host-only: take a guest off the event guest list by pass code. Frees the spot. A guest who already checked in stays checked in.
- **Input:** `event_id` (integer, required); `code` (string, required).
- **Output:** `object {removed: boolean, spots_left: integer | null}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Removes the specified guest pass and releases its capacity.

## Owner Guest List

Registration: [`OwnerGuestListAbilities.php`](../inc/Abilities/OwnerGuestListAbilities.php).

### `extrachill/add-owner-guest-list-name`

- **Purpose:** Venue team or promoter members only: put someone on the permanent guest list. They get one pass that checks in at every published show tagged with that venue or promoter, without using the show's guest list spots. Emails the pass when an email is given.
- **Input:** `owner_type` (string, required); `owner_id` (integer, required); `name` (string, required); `email` (string, optional).
- **Output:** `object {owner_type: string, owner_id: integer, owner_name: string, label: string, member_id: integer, name: string, email: string, code: string, status: string, pass_url: string, qr_url: string, added_by: string, created_at: string, emailed: boolean}`.
- **Permission:** `extrachill_events_user_can_manage_owner_list` for the current user and requested venue/promoter.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Creates a standing owner-list member/pass and optionally emails it.

### `extrachill/list-owner-guest-list`

- **Purpose:** Venue team or promoter members only: list the people on a venue's or promoter's permanent guest list, with their pass links.
- **Input:** `owner_type` (string, required); `owner_id` (integer, required).
- **Output:** `object {owner_type: string, owner_id: integer, owner_name: string, label: string, members: array<object {member_id: integer, name: string, email: string, code: string, status: string, pass_url: string, qr_url: string, added_by: string, created_at: string}>}`.
- **Permission:** `extrachill_events_user_can_manage_owner_list` for the current user and requested venue/promoter.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.

### `extrachill/remove-owner-guest-list-name`

- **Purpose:** Venue team or promoter members only: take someone off the permanent guest list. Their pass stops working at once; past check-ins stay on record.
- **Input:** `owner_type` (string, required); `owner_id` (integer, required); `member_id` (integer, required).
- **Output:** `object {removed: boolean, member_id: integer}`.
- **Permission:** `extrachill_events_user_can_manage_owner_list` for the current user and requested venue/promoter.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Removes the standing owner-list member.

## Venue Add

Registration: [`VenueAddAbilities.php`](../inc/Abilities/VenueAddAbilities.php).

### `extrachill/add-venue`

- **Purpose:** Add a venue scraper flow to an existing city pipeline. Creates the venue taxonomy term and configures the universal web scraper.
- **Input:** `name` (string, required); `url` (string, required); one of `pipeline_id` (integer) or `location` (location term ID, slug, or exact name); `venue_term_id` (integer, optional); `address` (string, optional); `city` (string, optional); `state` (string, optional); `zip` (string, optional); `website` (string, optional); `interval` (string, optional); `dry_run` (boolean, optional).
- **City resolution:** with `location` alone, the city pipeline is the one whose flows bind that location term (the canonical binding `validateCityPipeline()` reads), never a pipeline name. No match returns `no_city_pipeline`. Several matches return `ambiguous_city_pipeline` with `pipeline_ids`; pass `pipeline_id` to choose one. A `pipeline_id` bound to a different location than `location` returns `pipeline_location_mismatch`.
- **Output:** `object {message: string, flow_id: integer, venue_term_id: integer, pipeline_id: integer}`.
- **Permission:** events staff through `TeamAuthorization::current_user_is_events_staff()`: `access_events_admin` (the events team capability) or `manage_options`. `extrachill/add-city` stays administrator-only.
- **Exposure:** `show_in_rest: true`; `wp extrachill venues add` adapter (`--pipeline` or `--location`).
- **Side effects:** Creates or reuses a venue term, creates a Data Machine flow and patches steps unless dry-run.

## Guest List Party

Registration: [`GuestListPartyAbilities.php`](../inc/Abilities/GuestListPartyAbilities.php).

### `extrachill/approve-guest-list-request`

- **Purpose:** Host-only: approve a waiting request. spots: the band's allotment grows. list: the submitted names go live. party: the uninvited band becomes a normal invited party and is emailed its private link.
- **Input:** `event_id` (integer, required); `party_id` (integer, required); `kind` (string, optional); `spots` (integer, optional).
- **Output:** `object {approved: boolean, kind: string, activated: integer, invite_url: string, emailed: boolean, names_waiting: integer, party: object {party_id: integer, name: string, email: string, status: string, allotment: integer | null, used: integer, spots_left: integer | null, created_at: string, origin: string, requested_spots: integer | null, request_note: string, pending_names: integer}}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Approves the request, activates waiting names and can send an invitation/approval email.

### `extrachill/create-guest-list-party`

- **Purpose:** Host-only: invite a band or artist management to fill guest list spots for an event. Emails them a private link where they add guest names with no password or account.
- **Input:** `event_id` (integer, required); `name` (string, required); `email` (string, required); `allotment` (integer, optional).
- **Output:** `object {party_id: integer, name: string, email: string, allotment: integer | null, invite_url: string, emailed: boolean}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Creates a party/allotment and invitation token; can email its invitation.

### `extrachill/deny-guest-list-request`

- **Purpose:** Host-only: deny a waiting request and email the band. Denied names never scan at the door.
- **Input:** `event_id` (integer, required); `party_id` (integer, required); `kind` (string, optional).
- **Output:** `object {denied: boolean, kind: string, denied_names: integer, emailed: boolean}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Denies the request and pending names; can email the requester.

### `extrachill/list-guest-list-parties`

- **Purpose:** Host-only: list the bands and management invited to an event's guest list, with spots used and allotted.
- **Input:** `event_id` (integer, required).
- **Output:** `object {allotments_enabled: boolean, parties: array<object {party_id: integer, name: string, email: string, status: string, allotment: integer | null, used: integer, spots_left: integer | null, created_at: string, origin: string, requested_spots: integer | null, request_note: string, pending_names: integer}>}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.

### `extrachill/list-guest-list-requests`

- **Purpose:** Host-only: list what is waiting for the venue on an event: uninvited bands asking to be on the list, invited bands asking for more spots, and submitted lists of names.
- **Input:** `event_id` (integer, required).
- **Output:** `object {allotments_enabled: boolean, requests: array<object {party_id: integer, kind: string, name: string, email: string, origin: string, requested_spots: integer | null, note: string, names: array<string>, allotment: integer | null, used: integer, requested_at: string}>}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.

### `extrachill/party-add-guest-list-name`

- **Purpose:** An invited band or management adds a guest by name through its invite token. No account. Counts against the event's spots and, when allotments are on, the party's allotment.
- **Input:** `token` (string, required); `name` (string, required); `email` (string, optional).
- **Output:** `object {code: string, name: string, pass_url: string, qr_url: string, emailed: boolean, spots_left: integer | null, party_spots_left: integer | null}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; API/CLI: none found.
- **Side effects:** Validates the invitation token and adds a guest within the party allotment; can email the pass.

### `extrachill/party-remove-guest-list-name`

- **Purpose:** An invited band or management takes one of its own guests off the list by pass code, through its invite token. Frees the spot.
- **Input:** `token` (string, required); `code` (string, required).
- **Output:** `object {removed: boolean}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; API/CLI: none found.
- **Side effects:** Validates the invitation token and removes a party guest.

### `extrachill/party-request-more-spots`

- **Purpose:** An invited band or management asks the venue for more guest list spots, through its invite token. Only while the event has allotments on. A host approves or denies it.
- **Input:** `token` (string, required); `spots` (integer, required); `note` (string, optional).
- **Output:** `object {requested_spots: integer}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; API/CLI: none found.
- **Side effects:** Validates the invitation token and stores the requested allotment increase.

### `extrachill/party-submit-guest-list`

- **Purpose:** An invited band or management sends a list of names for the venue to approve, through its invite token. The names are held pending: they take no spot and do not scan at the door until a host approves the list.
- **Input:** `token` (string, required); `names` (array | string, required).
- **Output:** `object {submitted: integer, pending: integer}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; API/CLI: none found.
- **Side effects:** Validates the invitation token and submits names for approval.

### `extrachill/request-guest-list-party`

- **Purpose:** A band or manager asks for guest list spots on a show they were not invited to. Creates a pending request with no working link; nothing works at the door until the venue accepts it. Rate limited per IP and email.
- **Input:** `event_id` (integer, required); `name` (string, required); `email` (string, required); `spots` (integer, optional); `names` (array | string, optional); `note` (string, optional); `client_ip` (string, optional).
- **Output:** `object {requested: boolean, party_id: integer, requested_spots: integer, names: integer}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; API/CLI: none found.
- **Side effects:** Creates a pending party request with requested spots/names; domain helper applies public-request validation.

### `extrachill/revoke-guest-list-party`

- **Purpose:** Host-only: revoke a band or management invite. Their link stops working; guests they already added stay on the list and can be removed one by one.
- **Input:** `event_id` (integer, required); `party_id` (integer, required).
- **Output:** `object {revoked: boolean, kept_guests: integer}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Revokes party access while retaining existing guests.

### `extrachill/set-guest-list-party-allotment`

- **Purpose:** Host-only: set how many guest list spots an invited band or management gets. Applies while the event has allotments on.
- **Input:** `event_id` (integer, required); `party_id` (integer, required); `allotment` (integer, required).
- **Output:** `object {party_id: integer, name: string, email: string, status: string, allotment: integer | null, used: integer, spots_left: integer | null, created_at: string, origin: string, requested_spots: integer | null, request_note: string, pending_names: integer}`.
- **Permission:** Valid event post and `extrachill_events_user_can_manage_event(get_current_user_id(), event_id)`; otherwise 404/403.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Updates the party allotment.

## Event Time Audit

Registration: [`EventTimeAuditAbilities.php`](../inc/Abilities/EventTimeAuditAbilities.php).

### `extrachill/audit-event-times`

- **Purpose:** Scan events for timezone mismatches and suspicious times (e.g. UTC timezone on US venues, shows at 1-6 AM).
- **Input:** `flow_id` (integer, optional); `location` (string, optional); `venue` (string, optional); `limit` (integer, optional, default 500); `offset` (integer, optional, default 0).
- **Output:** `object {checked_count: integer, flagged_count: integer, results: array<object>}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill events` adapter.

### `extrachill/fix-event-times`

- **Purpose:** Bulk-fix event times by converting between timezones. Updates block attributes in post content.
- **Input:** `from` (string, required); `to` (string, required); `flow_id` (integer, optional); `dry_run` (boolean, optional, default true); `limit` (integer, optional, default 500).
- **Output:** `object {checked_count: integer, fixed_count: integer, dry_run: boolean, results: array<object>}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill events` adapter.
- **Side effects:** Updates flagged event times unless dry-run.

## Ticket Sales

Registration: [`TicketSalesAbilities.php`](../inc/Abilities/TicketSalesAbilities.php).

### `extrachill/cancel-show-ticket-sales`

- **Purpose:** Stop selling tickets for a show and refund every order nobody has checked in on. Requires finance authority on the show's ticket seller.
- **Input:** `event_id` (integer, required).
- **Output:** `object {refunded: integer, checked_in: integer, failed: integer}`.
- **Permission:** Logged-in user with `can_manage_finances` for the seller; cancellation first validates the event and resolves its configured seller.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Disables ticket sales before calling the shop refund ability over authenticated cross-site HTTP; sales remain disabled if refunds fail.

### `extrachill/list-seller-ticket-sales`

- **Purpose:** Ticketed shows and orders for a venue or promoter seller, with refund eligibility. Requires finance authority on the seller.
- **Input:** `type` (string, required); `id` (integer, required).
- **Output:** `object {events: array<object {event_id: integer, title: string, url: string, manage_url: string, start: string, timing: string, tickets_enabled: boolean, price_minor: integer, currency: string, quantity: integer, sold: integer, checked_in: integer, refunded: integer, ticket_types: array<object {id: string, name: string, price_minor: integer, quantity: integer, sold: integer}>, orders: array<object {order_reference: string, name: string, ticket_type: string, quantity: integer, checked_in: integer, bought_at: string, status: string, refundable: boolean}>}>}`.
- **Permission:** Logged-in user with `can_manage_finances` for the seller; cancellation first validates the event and resolves its configured seller.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Show Ticket Report

Registration: [`ShowTicketReportAbilities.php`](../inc/Abilities/ShowTicketReportAbilities.php).

### `extrachill/get-show-ticket-report`

- **Purpose:** Sales report for one show (extrachill#1746): tickets sold, gross, fees, refunds and net, a breakdown by ticket type and by code, and the check-in rate. Shown in **Host tools** on the host's `/guest-list` screen.
- **Input:** `event_id` (integer, required, minimum 1). No other properties.
- **Output:** `object {event_id: integer, tickets_enabled: boolean, currency: string, sold: integer, refunded: integer, checked_in: integer, check_in_rate: integer, money_available: boolean, gross_minor: integer, fees_minor: integer, refunds_minor: integer, net_minor: integer, ticket_types: array<object {id: string, name: string, sold: integer, quantity: integer, revenue_minor: integer}>, codes: array<object {code: string, uses: integer, max_uses: integer, discount_minor: integer}>}`.
  - `sold` counts live paid passes (active or checked in) via `RsvpPassesTable::paid_ticket_counts_by_type()`, the same count capacity uses; `refunded` counts revoked paid passes; `check_in_rate` is checked-in paid passes over `sold`, as a whole percent.
  - Money comes from Shop's `extrachill/get-event-ticket-order-report`, read as the current user. `gross_minor` is what buyers paid, `refunds_minor` what went back, `fees_minor` Extra Chill's application fee on orders that kept it, and `net_minor = gross - refunds - fees`. A type's `revenue_minor` and a code's `uses` / `discount_minor` count orders that weren't refunded.
  - A show with no ticket types reads as one `general` type at the single price. Types and codes since removed still report by ID.
  - When Shop refuses (the user can't manage the seller) or is unreachable, `money_available` is false and the money fields are 0.
- **Permission:** Logged-in user who manages the event (`extrachill_events_user_can_manage_event()`), the same rule as the door list; the event must be a `data_machine_events` post.
- **Exposure:** `show_in_rest: true`, read-only and idempotent. Rendered by `templates/show-ticket-report.php` through `extrachill_events_door_ticket_report()`; API/CLI: none.
- **Side effects:** None.

The same file holds the door list CSV export helpers. `admin-post.php?action=ec_door_list_csv&event_id=N` (nonce `ec_door_list_csv_{N}`, see `inc/core/door-list-export.php`) downloads everyone on the door list with Name, Email, Source, Ticket type, Code, Order reference and Checked in, using the door list's authorization. Ticket buyer emails and codes fill in from the Shop order report when the user can manage the seller. Cells that start like a spreadsheet formula get a leading `'`. The host screen's **Buy tickets** QR (`action=ec_buy_tickets_qr`) links to the event page and renders only while `extrachill/get-event-ticket-offer` reports `sale_open`.

## Venue Discovery

Registration: [`VenueDiscoveryAbilities.php`](../inc/Abilities/VenueDiscoveryAbilities.php).

### `extrachill/discover-venues`

- **Purpose:** Discover music venues in a city using Google Places API. Returns venues not already in the calendar, with website URLs for qualification.
- **Input:** `city` (string, required); `query` (string, optional); `include_known` (boolean, optional).
- **Output:** `object {city: string, total_found: integer, new_venues: integer, known_venues: integer, venues: array<any>}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill venues` adapter.
- **Side effects:** Calls Google Places using a Google access token.

## Event Source Ramp

Registration: [`EventSourceRampAbilities.php`](../inc/Abilities/EventSourceRampAbilities.php).

### `extrachill/evaluate-event-source-ramp`

- **Purpose:** Evaluate timestamped source-class ramp evidence and return a reversible, non-executed plan.
- **Input:** `source_class` (string, required); `current_max_items` (integer, required); `phase` (string, required); `scope` (string, required); `scope_id` (integer, required); `evidence` (object {schema_version: string, observed_at: string, window_start: string, window_end: string, provenance: object, metrics: object {queue_depth: number, oldest_queue_age_seconds: number, jobs_without_scheduler_path: number, failed_rate: number, deferred_rate: number, ai_defer_budget_used_rate: number, ai_defer_exhausted: number, action_scheduler_pending_growth: number, source_rejection_rate: number, eligible_events: number, duplicate_rate: number, event_yield: number}, cadence_status: object | null}, required).
- **Output:** `object {schema_version: string, generated_at: string, phase: string, source_class: string, profile: object, scope: object, current_stage: integer, proposed_stage: integer | null, decision: string, can_advance: boolean, failed_gates: array<string>, gates: array<object>, evidence: object, apply_plan: object}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Event Roundup

Registration: [`EventRoundupAbilities.php`](../inc/Abilities/EventRoundupAbilities.php).

### `extrachill/event-roundup-build`

- **Purpose:** One-shot: query events for a date range and location, then render the carousel slides. Returns image paths plus event metadata. See [existing guide](roundup-sharing.md).
- **Input:** `background` (string | integer | array, optional); `date_start` (string, optional); `date_end` (string, optional); `scope` (string, optional); `location` (string, optional); `tax_filters` (object, optional); `venue_tier` (string | array, optional); `title` (string, optional); `cta_text` (string, optional); `cover` (boolean, optional); `closing` (boolean, optional); `closing_text` (string, optional); `layout` (string, optional); `partner_logo_path` (string, optional).
- **Output:** `object {success: boolean, image_paths: array<string>, slide_count: integer, slides: array<object>, date_start: string, date_end: string, location_name: string, total_events: integer, event_summary: string, message: string}`.
- **Permission:** `current_user_can('upload_files')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill events` adapter.
- **Side effects:** Render/build generates image files and may upload slides when requested.

### `extrachill/event-roundup-query`

- **Purpose:** Query events for a roundup by date range and location. Accepts arbitrary date ranges or weekday-name shortcuts. See [existing guide](roundup-sharing.md).
- **Input:** `date_start` (string, optional); `date_end` (string, optional); `week_start_day` (string, optional); `week_end_day` (string, optional); `scope` (string, optional); `location` (string, optional); `location_term_id` (integer, optional); `tax_filters` (object, optional); `venue_tier` (string | array, optional).
- **Output:** `object {date_start: string, date_end: string, location_name: string, total_events: integer, day_groups: object, event_summary: string}`.
- **Permission:** `current_user_can('upload_files')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/event-roundup-render`

- **Purpose:** Render Instagram carousel images from day-grouped event data via the event_roundup template. See [existing guide](roundup-sharing.md).
- **Input:** `background` (string | integer | array, optional); `location` (string, optional); `day_groups` (object, required); `title` (string, optional); `cta_text` (string, optional); `cover` (boolean, optional); `closing` (boolean, optional); `closing_text` (string, optional); `layout` (string, optional); `partner_logo_path` (string, optional); `storage_context` (object, optional).
- **Output:** `object {success: boolean, image_paths: array<string>, slide_count: integer, slides: array<object>, message: string}`.
- **Permission:** `current_user_can('upload_files')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Render/build generates image files and may upload slides when requested.

## Artist Url Import

Registration: [`ArtistUrlImportAbilities.php`](../inc/Abilities/ArtistUrlImportAbilities.php).

### `extrachill-events/approve-event-source-submission`

- **Purpose:** Approve a moderated artist, venue, or regional listings source through its existing owner flow primitive.
- **Input:** `submission_id` (integer, required); `source_kind` (string, optional); `entity_term_id` (integer, optional); `entity_name` (string, optional); `artist_term_id` (integer, optional); `artist_name` (string, optional); `venue_term_id` (integer, optional); `venue_name` (string, optional); `pipeline_id` (integer, optional); `schedule_interval` (string, optional).
- **Output:** `object {success: boolean, pipeline_id: integer, flow_id: integer, source_kind: string, artist_term_id: integer, events_imported_immediately: integer | null} or object {success: boolean, pipeline_id: integer, flow_id: integer, source_kind: string, venue_term_id: integer} or object {success: boolean, pipeline_id: integer, flow_id: integer, source_kind: string, linked_existing_flow: boolean}`.
- **Permission:** WP-CLI execution or `manage_options`.
- **Exposure:** `show_in_rest: true`; [API: event-source](../../extrachill-api/docs/routes/events/event-source.md).
- **Side effects:** Creates import infrastructure for the approved source and may import events immediately.

### `extrachill-events/preview-event-source`

- **Purpose:** Preview a qualified recurring event source.
- **Input:** `url` (string, required).
- **Output:** `object {success: boolean, qualified: boolean, canonical_events_url: string, source_identity_url: string, verdict: string, events_found: integer, events_preview: array<object {title: string, startDate: string, startTime: string, venue: string, ticketUrl: string}>, extraction_method: string, source_kind: string, classification_confidence: string, entity_candidates: array<object {taxonomy: string, term_id: integer | null, name: string}>, existing_coverage: object {covered: boolean, type: string, flow_id: integer, flow_name: string}, warnings: array<string>, recommended_route: string, recommended_binding: object {taxonomy: string, term_id: integer | null, name: string}, recurring_eligible: boolean, scope_evidence: object {bounded: boolean, type: string, host: string, official_host: string, event_page_shape: string}, detected_format: string, source_metadata: object, suggested_artist_name: string, suggested_artist_term_id: integer | null}`.
- **Permission:** WP-CLI execution or a logged-in user.
- **Exposure:** `show_in_rest: true`; [API: event-source](../../extrachill-api/docs/routes/events/event-source.md).
- **Side effects:** Fetches remote pages and runs source qualification/extraction for preview.

### `extrachill-events/reject-event-source-submission`

- **Purpose:** Reject a moderated event source.
- **Input:** `submission_id` (integer, required); `reason` (string, optional).
- **Output:** `object {success: boolean}`.
- **Permission:** WP-CLI execution or `manage_options`.
- **Exposure:** `show_in_rest: true`; [API: event-source](../../extrachill-api/docs/routes/events/event-source.md).
- **Side effects:** Updates the submission to rejected.

### `extrachill-events/submit-event-source`

- **Purpose:** Server-side requalify and persist an event source. Events staff submissions are approved immediately; others go to moderation.
- **Input:** `url` (string, required); `contact_email` (string, optional); `contact_name` (string, optional).
- **Output:** `object {success: boolean, submission_id: integer, status: string, message: string, events_found: integer, source_kind: string, auto_approved: boolean, flow_id: integer}`.
- **Permission:** WP-CLI execution or a logged-in user.
- **Exposure:** `show_in_rest: true`; [API: event-source](../../extrachill-api/docs/routes/events/event-source.md).
- **Side effects:** Persists a source submission and may auto-approve eligible submissions.

### `extrachill/qualify-event-source`

- **Purpose:** Discover and test a canonical recurring event source, classify its bounded domain identity, and recommend moderation routing.
- **Input:** `url` (string, required).
- **Output:** `object {success: boolean, qualified: boolean, canonical_events_url: string, source_identity_url: string, verdict: string, events_found: integer, events_preview: array<object {title: string, startDate: string, startTime: string, venue: string, ticketUrl: string}>, extraction_method: string, source_kind: string, classification_confidence: string, entity_candidates: array<object {taxonomy: string, term_id: integer | null, name: string}>, existing_coverage: object {covered: boolean, type: string, flow_id: integer, flow_name: string}, warnings: array<string>, recommended_route: string, recommended_binding: object {taxonomy: string, term_id: integer | null, name: string}, recurring_eligible: boolean, scope_evidence: object {bounded: boolean, type: string, host: string, official_host: string, event_page_shape: string}, detected_format: string, source_metadata: object, suggested_artist_name: string, suggested_artist_term_id: integer | null}`.
- **Permission:** WP-CLI execution or a logged-in user.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Fetches remote pages and runs source qualification/extraction.

## Calendar

Registration: [`events-calendar.php`](../inc/abilities/events-calendar.php).

### `extrachill/events-calendar`

- **Purpose:** Returns paginated date-grouped calendar events with optional taxonomy, geo, scope, and search filtering.
- **Input:** `page` (integer, optional, default 1); `month` (string, optional); `venue` (string, optional); `venue_id` (integer, optional); `promoter` (string, optional); `location` (string, optional); `scope` (string, optional); `lat` (number, optional); `lng` (number, optional); `radius` (integer, optional, default 25); `search` (string, optional); `past` (boolean, optional, default false).
- **Output:** `object {dates: array<object {date: string, label: string, events: array<any>}>, total: integer, page: integer, has_more: boolean}`.
- **Permission:** Public unless `venue_id` is supplied; then `TeamCapabilities::authorize('ec_access_venue', venue_id)`.
- **Exposure:** `show_in_rest: true`; [API: calendar-discovery](../../extrachill-api/docs/routes/events/calendar-discovery.md).

## Check Venue Duplicate

Registration: [`events-check-venue-duplicate.php`](../inc/abilities/events-check-venue-duplicate.php).

### `extrachill/events-check-venue-duplicate`

- **Purpose:** Checks for existing venues matching a given name. Returns matching venue records for duplicate detection.
- **Input:** `name` (string, required); `city` (string, optional); `address` (string, optional); `state` (string, optional); `country` (string, optional).
- **Output:** `array<object {id: integer, name: string, slug: string, address: string, city: string, state: string, country: string, zip: string, latitude: number, longitude: number, coordinates: string, timezone: string, website: string}>`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; [API: calendar-discovery](../../extrachill-api/docs/routes/events/calendar-discovery.md).

## By Artist

Registration: [`events-by-artist.php`](../inc/abilities/events-by-artist.php).

### `extrachill-events/events-by-artist`

- **Purpose:** Resolve a canonical Extra Chill artist to its Events-site artist term and return the artist events.
- **Input:** `artist_term_id` (integer, required); `scope` (string, optional); `limit` (integer, optional).
- **Output:** `object {taxonomy: string, term_id: integer, term_slug: string, found: boolean, upcoming: array<object>, past: array<object>}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Filters

Registration: [`events-filters.php`](../inc/abilities/events-filters.php).

### `extrachill/events-filters`

- **Purpose:** Returns available filter options (venues, promoters, locations) for the events calendar.
- **Input:** No fields.
- **Output:** `object {venues: array<any>, promoters: array<any>, locations: array<any>}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; [API: calendar-discovery](../../extrachill-api/docs/routes/events/calendar-discovery.md).

## Geocode

Registration: [`events-geocode.php`](../inc/abilities/events-geocode.php).

### `extrachill/events-geocode`

- **Purpose:** Geocode a search query to lat/lon coordinates via Nominatim. Results scoped to US.
- **Input:** `q` (string, required).
- **Output:** `array<object {lat: number, lon: number, display_name: string}>`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; [API: calendar-discovery](../../extrachill-api/docs/routes/events/calendar-discovery.md).

## Get Venue

Registration: [`events-get-venue.php`](../inc/abilities/events-get-venue.php).

### `extrachill/events-get-venue`

- **Purpose:** Returns detailed information for a single venue by term ID.
- **Input:** `id` (integer, required).
- **Output:** `object {id: integer, name: string, slug: string, address: string, city: string, state: string, country: string, zip: string, latitude: number, longitude: number, coordinates: string, timezone: string, website: string, url: string}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; [API: calendar-discovery](../../extrachill-api/docs/routes/events/calendar-discovery.md).

## List Venues

Registration: [`events-list-venues.php`](../inc/abilities/events-list-venues.php).

### `extrachill/events-list-venues`

- **Purpose:** Returns venues with optional geo-proximity, viewport-bounds, and location-taxonomy filtering.
- **Input:** `location` (string, optional); `sw_lat` (number, optional); `sw_lng` (number, optional); `ne_lat` (number, optional); `ne_lng` (number, optional); `lat` (number, optional); `lng` (number, optional); `radius` (integer, optional, default 25).
- **Output:** `array<object {id: integer, name: string, slug: string, address: string, formatted_address: string, city: string, state: string, zip: string, country: string, latitude: number, longitude: number, coordinates: string, timezone: string, website: string, url: string, event_count: integer}>`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; [API: calendar-discovery](../../extrachill-api/docs/routes/events/calendar-discovery.md).

## Locations

Registration: [`events-locations.php`](../inc/abilities/events-locations.php).

### `extrachill/events-locations`

- **Purpose:** Search or resolve selectable locations from the canonical Events taxonomy.
- **Input:** `mode` (string, required); `search` (string, optional); `slug` (string, optional); `limit` (integer, optional, default 10).
- **Output:** `object {locations: array<object {term_id: integer, name: string, slug: string, archive_url: string, coordinates: object {lat: number, lon: number}, hierarchy: object {region: string, state: string, label: string}}>, location: object {term_id: integer, name: string, slug: string, archive_url: string, coordinates: object {lat: number, lon: number}, hierarchy: object {region: string, state: string, label: string}}}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Public Entity Projections

Registration: [`events-public-entity-projections.php`](../inc/abilities/events-public-entity-projections.php).

### `extrachill/events-public-entity-projections`

- **Purpose:** Batch-resolves public names and canonical URLs for Events-owned subscription identities.
- **Input:** `schema_version` (string, required); `items` (array<object {entity_type: string, slug: string}>, required).
- **Output:** `object {schema_version: string, items: array<object {entity_type: string, slug: string, status: string, name: string, url: string}>}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Upcoming Counts

Registration: [`events-upcoming-counts.php`](../inc/abilities/events-upcoming-counts.php).

### `extrachill/events-upcoming-counts`

- **Purpose:** Returns upcoming-event counts per taxonomy term. Supports venue, location, artist, and festival taxonomies.
- **Input:** `taxonomy` (string, required); `slug` (string | null, optional); `location_slug` (string | null, optional); `limit` (integer, optional, default 0); `rollup` (boolean, optional, default false).
- **Output:** `array<object {term_id: integer, name: string, slug: string, count: integer, url: string}>`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Venue Expansion

Registration: [`VenueExpansionAbilities.php`](../inc/Abilities/VenueExpansionAbilities.php).

### `extrachill/expand-venues`

- **Purpose:** Plan or schedule bounded, resumable venue expansion for one or more cities.
- **Input:** `city` (string, optional); `cities` (array<string>, optional); `country` (string, optional); `max_cities` (integer, optional); `max_venues_per_city` (integer, optional); `discovery_budget` (integer, optional); `qualification_budget` (integer, optional); `skip_existing` (boolean, optional); `radius` (integer, optional); `interval` (string, optional); `request_delay_ms` (integer, optional); `max_verdict_age_days` (integer, optional); `city_delay_seconds` (integer, optional); `agent_id` (integer, optional); `agent_slug` (string, optional); `apply` (boolean, optional, default false).
- **Output:** `object {mode: string, plan: object, batch?: object}; batch is returned only in apply mode`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill venues expand` adapter.
- **Side effects:** Apply queues a Data Machine expansion batch; plan-only does not write.

### `extrachill/venue-expansion-report`

- **Purpose:** Read per-city and aggregate results for venue expansion jobs.
- **Input:** `batch_job_id` (integer, optional); `job_ids` (array<integer>, optional).
- **Output:** `object {schema: string, status: string, cities_scheduled: integer, cities_reported: integer, city_statuses: object, counts: object, rejection_reasons: object, cities: array<object>}`; batch reports may also include `batch_status` and `unstarted_cities`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill venues expand-report` adapter.

## Commerce Seller

Registration: [`CommerceSellerAbilities.php`](../inc/Abilities/CommerceSellerAbilities.php).

### `extrachill/get-commerce-seller`

- **Purpose:** Resolve a venue or promoter as a shop seller and report whether the current user may manage its finances. Public identity resolves for anyone; finance authority is false for anonymous visitors.
- **Input:** `type` (string, required); `id` (integer, optional); `slug` (string, optional).
- **Output:** `object {type: string, id: integer, name: string, slug: string, url: string, manage_url: string, can_manage_finances: boolean}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; [API: shipping-labels](../../extrachill-api/docs/routes/shop/shipping-labels.md); [API: orders](../../extrachill-api/docs/routes/shop/orders.md); [API: products](../../extrachill-api/docs/routes/shop/products.md); [API: shipping-address](../../extrachill-api/docs/routes/shop/shipping-address.md); [API: stripe-connect](../../extrachill-api/docs/routes/shop/stripe-connect.md).

### `extrachill/list-commerce-sellers`

- **Purpose:** List venues and promoters whose finances the current user may manage.
- **Input:** No fields.
- **Output:** `object {sellers: array<object {type: string, id: integer, name: string, slug: string, url: string, manage_url: string, can_manage_finances: boolean}>}`.
- **Permission:** Current user ID must be positive.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Event Perk

Registration: [`EventPerkAbilities.php`](../inc/Abilities/EventPerkAbilities.php).

### `extrachill/get-event-perk`

- **Purpose:** Get an event's RSVP perk configuration.
- **Input:** `event` (string, required).
- **Output:** Readers who can't manage the event get `ticket_codes` empty and no `hidden` types. `object {tickets_enabled: boolean, ticket_price_minor: integer, ticket_currency: string, ticket_quantity: integer, ticket_seller: object {type: string, id: integer}, ticket_types: array<object {id: string, name: string, price_minor: integer, quantity: integer, hidden: boolean}>, ticket_codes: array<object {code: string, percent_off: integer, amount_off_minor: integer, max_uses: integer, ticket_types: array<string>}>, post_id: integer, enabled: boolean, text: string, password_required: boolean, mode: string, capacity: integer, claimed: integer, checked_in: integer, spots_left: integer | null, full: boolean, allotments_enabled: boolean, image_id: integer, image_url: string, pass_image_url: string, pass_image_source: string}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.

### `extrachill/set-event-perk`

- **Purpose:** Enable or disable an RSVP perk on an event, set its attendee-facing description, and choose how guests get on the list (mode). Empty or omitted fields keep their current value.
- **Input:** `tickets_enabled` (boolean, optional); `ticket_price_minor` (integer, optional); `ticket_currency` (string, optional); `ticket_quantity` (integer, optional); `ticket_seller` (object {type: string, id: integer}, optional); `ticket_types` (array, optional: up to 10 types; an omitted `id` is made from the name); `ticket_codes` (array, optional: up to 50 codes, 3–32 letters, digits or dashes); `event` (string, required); `enabled` (boolean, optional); `text` (string, optional); `clear_text` (boolean, optional, default false); `mode` (string, optional); `password` (string, optional); `clear_password` (boolean, optional, default false); `capacity` (integer, optional); `image_id` (integer, optional); `allotments_enabled` (boolean, optional).
- **Output:** `object {success: boolean, event: object {tickets_enabled: boolean, ticket_price_minor: integer, ticket_currency: string, ticket_quantity: integer, ticket_seller: object {type: string, id: integer}, ticket_types: array<object {id: string, name: string, price_minor: integer, quantity: integer, hidden: boolean}>, ticket_codes: array<object {code: string, percent_off: integer, amount_off_minor: integer, max_uses: integer, ticket_types: array<string>}>, post_id: integer, title: string, enabled: boolean, text: string, password_required: boolean, mode: string, capacity: integer, allotments_enabled: boolean, image_id: integer, image_url: string, pass_image_url: string, pass_image_source: string}}`.
- **Permission:** Resolves the event, then checks `extrachill_events_user_can_manage_event` for the current user. Returns 404 for a missing event, 503 if the authority helper is unavailable, or 403 on denial.
- **Exposure:** `show_in_rest: true`; `wp extrachill events guest-list` adapter.
- **Side effects:** Writes event perk, guest-list and ticket settings; hashes passwords and may update artwork/capacity.

## Location Event

Registration: [`LocationEventAbilities.php`](../inc/Abilities/LocationEventAbilities.php).

### `extrachill/get-location-events`

- **Purpose:** Query events by Extra Chill location taxonomy.
- **Input:** `location` (string, required); `date_start` (string, optional); `date_end` (string, optional); `limit` (integer, optional, default 50).
- **Output:** `object {location: object {term_id: integer, name: string, slug: string}, events: array<object {id: integer, title: string, date: string, start_time: string, venue: string, permalink: string}>, returned_count: integer, message: string}`.
- **Permission:** `current_user_can('read')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Managed Venue Voices

Registration: [`ManagedVenueVoicesAbilities.php`](../inc/Abilities/ManagedVenueVoicesAbilities.php).

### `extrachill/get-managed-venue-voices`

- **Purpose:** Returns public venue identities represented by the current authenticated user.
- **Input:** No fields.
- **Output:** `object {voices: array<object {reference: string, term_id: integer, name: string, slug: string, url: string, description: string}>}`.
- **Permission:** Authenticated current user; rejects any input identity claims.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Venue Profile

Registration: [`VenueProfileAbilities.php`](../inc/Abilities/VenueProfileAbilities.php).

### `extrachill/get-venue-profile`

- **Purpose:** Read the canonical member-facing profile for an authorized venue.
- **Input:** `venue_term_id` (integer, required).
- **Output:** `object {term_id: integer, name: string, description: string, address: string, city: string, state: string, zip: string, country: string, phone: string, website: string, ticketing_url: string, capacity: string, logo_attachment_id: integer, logo: object {attachment_id: integer, site_id: integer, url: string, alt: string, mime_type: string, width: integer, height: integer}, revision: string}`.
- **Permission:** `TeamCapabilities::authorize('ec_access_venue', venue_term_id)`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/update-venue-profile`

- **Purpose:** Update member-editable venue fields at an expected canonical revision.
- **Input:** `venue_term_id` (integer, required); `expected_revision` (string, required); `profile` (object {name: string, description: string, address: string, city: string, state: string, zip: string, country: string, phone: string, website: string, capacity: string}, required).
- **Output:** `object {success: boolean, term_id: integer, updated_fields: array<string>, revision: string, profile: object {term_id: integer, name: string, description: string, address: string, city: string, state: string, zip: string, country: string, phone: string, website: string, ticketing_url: string, capacity: string, logo_attachment_id: integer, logo: object {attachment_id: integer, site_id: integer, url: string, alt: string, mime_type: string, width: integer, height: integer}, revision: string}}`.
- **Permission:** `TeamCapabilities::authorize('ec_access_venue', venue_term_id)`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Updates venue term/profile metadata with optimistic revision checking.

## Door Access

Registration: [`DoorAccessAbilities.php`](../inc/Abilities/DoorAccessAbilities.php).

### `extrachill/grant-door-access`

- **Purpose:** Give a person check-in access: resolve or create their account from an email (or attach an existing username) and add them to the event's venue team, or its promoter team, as a non-owner member. Never grants ownership; an existing member is left unchanged. Returns the account login and email to sanity-check, a claim link for new or unclaimed accounts, and near-match accounts when the email has no exact account.
- **Input:** `email` (string, required); `event_id` (integer, optional); `venue_term_id` (integer, optional); `promoter_term_id` (integer, optional); `team_type` (string, optional); `user_login` (string, optional).
- **Output:** `object {user_id: integer, user_login: string, email: string, created_account: boolean, membership: object {status: string, is_owner: boolean, created: boolean, version: integer, created_by_user_id: integer}, claim_url: string | null, claim_throttled: boolean, team_type: string, team_term_id: integer, near_matches: array<object {user_id: integer, user_login: string, email: string, registered: string, distance: integer}>}`.
- **Permission:** DoorAccessService resolves the team, requires an existing authenticated actor, then site admin, staff access capability, or active team membership; execution-principal scope must permit access.
- **Exposure:** `show_in_rest: true`; `wp extrachill events door-access` adapter.
- **Side effects:** Resolves/creates an account, grants non-owner team membership, and may send a claim/access email.

## Priority Event

Registration: [`PriorityEventAbilities.php`](../inc/Abilities/PriorityEventAbilities.php).

### `extrachill/grant-event-priority-boost`

- **Purpose:** Idempotently grant paid priority to a canonical event.
- **Input:** `event` (string, required); `external_reference` (string, required); `idempotency_key` (string, required).
- **Output:** `object {success: boolean, replayed: boolean, existing_priority_preserved: boolean, event: object {post_id: integer, title: string, slug: string, priority: boolean}, receipt: object {operation_id: string, actor_id: integer, granted_at: string}}`.
- **Permission:** Trusted commerce request, or authenticated execution actor with administrator authority.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Persists an idempotency receipt, marks the event priority and clears the priority cache.

### `extrachill/list-priority-events`

- **Purpose:** List all events marked as priority for calendar sorting.
- **Input:** No fields.
- **Output:** `object {events: array<object {post_id: integer, title: string, slug: string, date: string}>, count: integer}`.
- **Permission:** `current_user_can('edit_posts')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/set-priority-event`

- **Purpose:** Mark or unmark an event as priority for calendar sorting.
- **Input:** `event` (string, required); `priority` (boolean, optional, default true).
- **Output:** `object {success: boolean, event: object {post_id: integer, title: string, priority: boolean}, message: string}`.
- **Permission:** `current_user_can('edit_posts')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Updates event priority metadata and clears the priority event cache.

## Local Scene Digest Guest

Registration: [`LocalSceneDigestGuestAbilities.php`](../inc/Abilities/LocalSceneDigestGuestAbilities.php).

### `extrachill/guest-subscribe-local-scene-digest`

- **Purpose:** Ask for a city's weekly show email with only an email address. Resolves or creates a claimable account and emails a signed confirm link; the subscription is recorded only when the inbox owner confirms. See [existing guide](local-scene-weekly-digest.md).
- **Input:** `location_slug` (string, required); `email` (string, required); `referrer` (string, optional); `utm` (object, optional).
- **Output:** `object {status: string}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; [API: guest-digest-and-refunds](../../extrachill-api/docs/routes/events/guest-digest-and-refunds.md).
- **Side effects:** Resolves or creates a claimable account and stores local-scene subscription preferences.

### `extrachill/unsubscribe-local-scene-digest`

- **Purpose:** Resolve a signed Local Scene digest unsubscribe link. Without confirm it returns a scanner-safe confirmation page and never changes consent; with confirm it removes only that location's digest subscription. See [existing guide](local-scene-weekly-digest.md).
- **Input:** `confirm` (boolean, optional); `token` (string, optional); `user` (integer, optional); `location` (string, optional); `expires` (integer, optional); `signature` (string, optional).
- **Output:** `object {status: string, title: string, message: string, action_html: string, html: string}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; [API: local-scene-digest-unsubscribe](../../extrachill-api/docs/routes/events/local-scene-digest-unsubscribe.md).
- **Side effects:** Verifies the signed token; confirmation updates subscription state.

## Team Term Link Page

Registration: [`TeamTermLinkPageAbilities.php`](../inc/Abilities/TeamTermLinkPageAbilities.php).

Venue and promoter Link Pages share one management family per owner kind. Each name below exists twice, once with `venue` and once with `promoter`. The owner key is `venue_term_id` or `promoter_term_id`. Shared rules for the 16 management abilities:

- **Permission:** `authorize()` delegates to the owner class: venues need `TeamCapabilities::authorize('ec_access_venue')`, promoters need `ec_access_promoter` (an active verified promoter). The Link Pages engine reauthorizes inside each operation.
- **Exposure:** `show_in_rest: true`. The venue-settings managed workspace calls `get-*`, `provision-*` and `patch-*`; no other API or CLI callers were found.
- **Document output:** `object {created?: boolean, venue|promoter: {term_id, owner_reference, title, management_url, snapshot}, link_page: {link_page_id, css_vars, links, link_sections, bio, settings, background_image_id, background_image_url, public_url, revision, …}}`. The venue snapshot carries `location`; the promoter snapshot carries `entity_type: Organization`.
- **Concurrency:** Writes require `expected_revision`, the 64-character hex `link_page.revision` from the last read. A stale revision conflicts.
- **Bounds:** Links are at most 10 sections of 25 links each, each `{id?, link_text, link_url, expires_at?}`. `css_vars` is a closed map of Link Page CSS variables. `settings` is a closed map (`link_expiration_enabled`, `redirect_enabled`, `redirect_target_url`, `youtube_embed_enabled`, `meta_pixel_id`, `google_tag_id`, `google_tag_manager_id`, `social_icons_position`, `profile_image_shape`, `background_image_id`).

### `extrachill/provision-venue-link-page`

- **Purpose:** Create the venue's Link Page from its canonical profile, or return the existing one.
- **Input:** `venue_term_id` (integer, required).
- **Output:** Document output.
- **Side effects:** Under the owner authority lock, creates the Link Page and its snapshot on the Link Pages storage blog if missing. Idempotent.

### `extrachill/provision-promoter-link-page`

- **Purpose:** Promoter counterpart of `extrachill/provision-venue-link-page`.
- **Input:** `promoter_term_id` (integer, required).
- **Output:** Document output.
- **Side effects:** As for venues.

### `extrachill/get-venue-link-page`

- **Purpose:** Read the venue's composed Link Page document.
- **Input:** `venue_term_id` (integer, required).
- **Output:** Document output, via `ec_read_link_page()`.

### `extrachill/get-promoter-link-page`

- **Purpose:** Read the promoter's composed Link Page document.
- **Input:** `promoter_term_id` (integer, required).
- **Output:** Document output.

### `extrachill/save-venue-link-page-links`

- **Purpose:** Replace the venue Link Page's links.
- **Input:** `venue_term_id`, `links` (sections), `expected_revision` (all required).
- **Output:** Document output.
- **Side effects:** Rechecks the link bounds even on direct execution (`invalid_venue_link_page_links`), then saves through `ec_save_link_page()`.

### `extrachill/save-promoter-link-page-links`

- **Purpose:** Promoter counterpart of `extrachill/save-venue-link-page-links`.
- **Input:** `promoter_term_id`, `links`, `expected_revision` (all required).
- **Output:** Document output.
- **Side effects:** As for venues.

### `extrachill/save-venue-link-page-styles`

- **Purpose:** Save the venue Link Page's style variables.
- **Input:** `venue_term_id`, `css_vars` (at least one key), `expected_revision` (all required).
- **Output:** Document output.
- **Side effects:** Saves the styles through `ec_save_link_page()`.

### `extrachill/save-promoter-link-page-styles`

- **Purpose:** Promoter counterpart of `extrachill/save-venue-link-page-styles`.
- **Input:** `promoter_term_id`, `css_vars`, `expected_revision` (all required).
- **Output:** Document output.
- **Side effects:** As for venues.

### `extrachill/save-venue-link-page-settings`

- **Purpose:** Save the venue Link Page's settings.
- **Input:** `venue_term_id`, `settings` (at least one key), `expected_revision` (all required).
- **Output:** Document output.
- **Side effects:** Saves the settings through `ec_save_link_page()`.

### `extrachill/save-promoter-link-page-settings`

- **Purpose:** Promoter counterpart of `extrachill/save-venue-link-page-settings`.
- **Input:** `promoter_term_id`, `settings`, `expected_revision` (all required).
- **Output:** Document output.
- **Side effects:** As for venues.

### `extrachill/patch-venue-link-page`

- **Purpose:** Atomically save only the changed areas of the venue Link Page.
- **Input:** `venue_term_id`, `expected_revision` (required), plus at least one of `links`, `css_vars`, `settings`, `background_image_id` (integer ≥0).
- **Output:** Document output.
- **Side effects:** One save under one canonical lock; links are bound-checked first. Not idempotent.

### `extrachill/patch-promoter-link-page`

- **Purpose:** Promoter counterpart of `extrachill/patch-venue-link-page`.
- **Input:** `promoter_term_id`, `expected_revision` (required), plus at least one area.
- **Output:** Document output.
- **Side effects:** As for venues.

### `extrachill/refresh-venue-link-page-snapshot`

- **Purpose:** Rebuild the Link Page's stored snapshot from the venue's canonical profile.
- **Input:** `venue_term_id` (integer, required).
- **Output:** Document output.
- **Side effects:** Rewrites the snapshot under the owner authority lock. Idempotent.

### `extrachill/refresh-promoter-link-page-snapshot`

- **Purpose:** Promoter counterpart of `extrachill/refresh-venue-link-page-snapshot`.
- **Input:** `promoter_term_id` (integer, required).
- **Output:** Document output.
- **Side effects:** As for venues.

### `extrachill/get-venue-link-page-analytics`

- **Purpose:** Read views and clicks for the venue Link Page.
- **Input:** `venue_term_id` (integer, required); `date_range` (integer 1–90, default 30), `start_date`, `end_date` (`YYYY-MM-DD`, optional).
- **Output:** `object {start_date, end_date, days, summary: {total_views, total_clicks}, chart_data: {labels, datasets}, top_links: array<{text, identifier, clicks}>}`. Fails with `analytics_unavailable` (503) when no provider answers the `extrachill_get_link_page_analytics` filter.

### `extrachill/get-promoter-link-page-analytics`

- **Purpose:** Promoter counterpart of `extrachill/get-venue-link-page-analytics`.
- **Input:** `promoter_term_id` (integer, required); `date_range`, `start_date`, `end_date` (optional).
- **Output:** As for venues.

### `extrachill/list-approved-promoters`

- **Purpose:** List the bounded public projection of active verified Events promoters.
- **Input:** No fields.
- **Output:** `object {promoters: array<object {name: string, slug: string, description: string, website: string, profile_url: string, link_page_url: string, promoter_term_id: integer}>, count: integer}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Market Report

Registration: [`MarketReportAbilities.php`](../inc/Abilities/MarketReportAbilities.php).

### `extrachill/market-report`

- **Purpose:** Generate a market overview for event calendar locations with traffic, events, venues, and flow data.
- **Input:** `location` (string, optional); `days` (integer, optional); `limit` (integer, optional); `sort` (string, optional).
- **Output:** `object {success: boolean, locations: array<object {name: string, slug: string, term_id: integer, events: integer, upcoming_events: integer, venues: integer, flows: object {venue_scrapers: integer, ticketmaster: integer, dice: integer, total: integer}, ga: object {sessions: integer, pageviews: integer}, gsc: object {clicks: integer, impressions: integer, position: number}, opportunity_score: number}>, summary: object {total_locations: integer, total_events: integer, total_venues: integer, total_flows: integer}}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill events` adapter.

Market demand includes both `/location/` archives and `/events/<slug>/` pages (up to 25,000 rows per source/provider). Event slugs from both providers are resolved together in one database query to published `data_machine_events` posts and their `location` terms. Child terms roll up to reported ancestor markets, deduplicated per event/market; unmatched URLs are ignored. A location filter still includes demand assigned to its descendants.

Both `ga` and `gsc` include `archive` and `events` objects with the same metric fields as their parent. Existing parent fields remain the combined totals (GA sessions are summed page-level sessions, not deduplicated users/sessions across pages). GSC position is impression-weighted across both sources. Empty sources have zero-valued metrics. CLI `event_impr` and `event_clicks` expose event-page search demand alongside combined `gsc_impr` and `gsc_clicks`.

Opportunity formula (unchanged except for combined demand): `(5 * ga.sessions + 0.5 * gsc.impressions + 0.1 * events) * (10 / max(1, venue_scrapers + 1))`, rounded to one decimal. Venues and clicks do not affect the score.

Targeted tests: `vendor/bin/phpunit -c phpunit-market-report-unit.xml.dist`.

## Pending Event Submissions

Registration: [`PendingEventSubmissionsAbility.php`](../inc/Abilities/PendingEventSubmissionsAbility.php).

### `extrachill/pending-event-submissions`

- **Purpose:** List public event submissions awaiting review.
- **Input:** No fields.
- **Output:** `array of pending submission review rows, sorted by event proximity then waiting time`.
- **Permission:** `current_user_can('edit_others_posts')`.
- **Exposure:** `show_in_rest: false`; API/CLI: none found.

## Qualify Digest

Registration: [`QualifyDigestAbilities.php`](../inc/Abilities/QualifyDigestAbilities.php).

### `extrachill/qualify-digest`

- **Purpose:** Render and (optionally) send the weekly qualify v2 activity digest email.
- **Input:** `since` (string, optional, default "1 week ago"); `recipient` (string, optional, default ""); `format` (string, optional, default "html"); `dry_run` (boolean, optional, default false).
- **Output:** `object {window_start: string, window_end: string, recipient: string, sent: boolean, dry_run: boolean, counts: object, body: string}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; API/CLI: none found.
- **Side effects:** Unless dry-run, queues a digest email through `datamachine/send-email-queued` and logs the outcome.

## Venue Qualification

Registration: [`VenueQualificationAbilities.php`](../inc/Abilities/VenueQualificationAbilities.php).

### `extrachill/qualify-venue`

- **Purpose:** Test if a venue website has scrapable events by running the actual universal web scraper against it.
- **Input:** `url` (string, optional); `name` (string, optional); `flow_id` (integer, optional); `persist_verdict` (boolean, optional, default true).
- **Output:** `object {qualified: boolean, verdict: string, events_url: string, method: string, event_count: integer, fingerprint: object, improvement_hint: string, agent_guidance: string, warnings: array<any>, production_context: object, repair_proposal: object | null}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill venues` adapter; `wp extrachill venues requalify-pending` adapter; `wp extrachill venues requalify-flow` adapter.
- **Side effects:** Fetches remote pages and runs extraction/qualification; may persist verdicts and use vision fallback.

## Event Location Alignment

Registration: [`EventLocationAlignmentAbilities.php`](../inc/Abilities/EventLocationAlignmentAbilities.php).

### `extrachill/reconcile-event-locations`

- **Purpose:** Audit or repair event location taxonomy assignments so they align with the venue city.
- **Input:** `apply` (boolean, optional, default false); `post_ids` (array<integer>, optional); `limit` (integer, optional, default 500); `offset` (integer, optional, default 0); `include_matches` (boolean, optional, default false).
- **Output:** `object {checked_count: integer, mismatch_count: integer, fixed_count: integer, unresolved_count: integer, flow_fallback_count: integer, results: array<object {post_id: integer, title: string, venue: string, venue_city: string, assigned_location: string, expected_location: string, flow_id: integer, flow_config_location: string, status: string, reason: string}>, message: string}`.
- **Permission:** `current_user_can('manage_options')`.
- **Exposure:** `show_in_rest: true`; `wp extrachill events` adapter; `wp extrachill events flows repair-locations` adapter.
- **Side effects:** With apply enabled, changes event location assignments; otherwise reports mismatches.

## Event Submission

Registration: [`EventSubmissionAbilities.php`](../inc/Abilities/EventSubmissionAbilities.php).

### `extrachill/submit-event`

- **Purpose:** Process an event submission from the public form. Validates input, stores flyers, and executes an ephemeral Data Machine workflow. See [existing guide](event-submission-review.md).
- **Input:** `event_title` (string, optional); `event_date` (string, optional); `event_time` (string, optional); `venue_name` (string, optional); `venue_term_id` (integer, optional); `event_city` (string, optional); `event_lineup` (string, optional); `event_link` (string, optional); `notes` (string, optional); `contact_name` (string, optional); `contact_email` (string, optional); `flyer` (object, optional).
- **Output:** `object {message: string, job_id: integer}`.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: false`; API/CLI: none found.
- **Side effects:** May create a claimable account, runs a submission workflow, handles flyer media and sends submission notifications.

## Venue Graphics

Registration: [`VenueGraphicsAbilities.php`](../inc/Abilities/VenueGraphicsAbilities.php).

### `extrachill/venue-graphics-build`

- **Purpose:** Render Instagram-ready carousel slides of one authorized venue's upcoming shows for a date range. Returns the slides as PNG data URIs.
- **Input:** `venue_term_id` (integer, required); `date_start` (string, required); `date_end` (string, required); `layout` (string, optional); `title` (string, optional).
- **Output:** `object {success: boolean, venue_term_id: integer, venue_name: string, date_start: string, date_end: string, layout: string, title: string, total_events: integer, slide_count: integer, slides: array<object {filename: string, data_uri: string}>}`.
- **Permission:** `TeamCapabilities::authorize('ec_access_venue', venue_term_id)`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Priority Term

Registration: [`PriorityTermAbilities.php`](../inc/Abilities/PriorityTermAbilities.php).

### `extrachill/list-priority-venues`

- **Purpose:** List all venues marked as priority for calendar sorting.
- **Input:** No fields.
- **Output:** `object {venues: array<object {term_id: integer, name: string, slug: string}>, count: integer}`.
- **Permission:** `current_user_can('manage_categories')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/set-priority-venue`

- **Purpose:** Mark or unmark a venue as priority for calendar sorting.
- **Input:** `venue` (string, required); `priority` (boolean, optional, default true).
- **Output:** `object {success: boolean, venue: object {term_id: integer, name: string, priority: boolean}, message: string}`.
- **Permission:** `current_user_can('manage_categories')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Updates venue priority and invalidates its priority cache.

### `extrachill/list-priority-promoters`

- **Purpose:** List all promoters whose events are automatically marked as priority.
- **Input:** No fields.
- **Output:** `object {promoters: array<object {term_id: integer, name: string, slug: string}>, count: integer}`.
- **Permission:** `current_user_can('manage_categories')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/set-priority-promoter`

- **Purpose:** Mark or unmark a promoter as priority. Events from priority promoters are automatically marked as priority events.
- **Input:** `promoter` (string, required); `priority` (boolean, optional, default true).
- **Output:** `object {success: boolean, promoter: object {term_id: integer, name: string, priority: boolean}, events_flagged: integer, message: string}`.
- **Permission:** `current_user_can('manage_categories')`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Updates promoter priority/cache; enabling flags upcoming published events, while disabling does not clear existing event flags.

## Ticket

Registration: [`TicketAbilities.php`](../inc/Abilities/TicketAbilities.php).

### `extrachill/get-event-ticket-offer`

- **Purpose:** get-event-ticket-offer See [existing guide](paid-tickets.md).
- **Input:** `event` (string | integer, required); `code` (string, optional, case-insensitive).
- **Output:** `object {event_id: integer, title: string, url: string, enabled: boolean, price_minor: integer, currency: string, remaining: integer | null, sale_open: boolean, seller: object {type: string, id: integer}, ticket_types: array<object {id: string, name: string, price_minor: integer, unit_price_minor: integer, remaining: integer | null, code_applies: boolean}>, code: object {code: string, percent_off: integer, amount_off_minor: integer, max_uses: integer, ticket_types: array<string>} | null, code_invalid: boolean}`. `ticket_types` lists buyer-visible types (hidden types only when `code` unlocks them) with `unit_price_minor` after the code. A legacy single-price offer reads as one `general` type. `price_minor` is the cheapest public type. `sale_open` also needs a buyable type. Codes themselves are never listed.
- **Permission:** Always allowed by the callback (no authentication check).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/issue-ticket-passes`

- **Purpose:** issue-ticket-passes See [existing guide](paid-tickets.md).
- **Input:** `event` (string | integer, required); `quantity` (integer, required); `name` (string, required); `email` (string, required); `idempotency_key` (string, required); `order_reference` (string, required); `payment_reference` (string, required); `ticket_type` (string, optional, default `general`). The type's own quantity is enforced with show capacity; a type removed after payment still issues.
- **Output:** `object {status: string, passes: array<object {code: string, url: string}>, emails_failed: integer}`.
- **Permission:** Verified ticket service authority (`extrachill_events_ticket_has_service_authority`).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Issues idempotent ticket pass rows and emails newly issued passes; `emails_failed` counts emails that could not be queued.

### `extrachill/get-ticket-order-status`

- **Purpose:** get-ticket-order-status See [existing guide](paid-tickets.md).
- **Input:** `order_reference` (string, required).
- **Output:** `object {event_id: integer, passes: array<object {code: string, status: string, url: string}>, any_redeemed: boolean, refundable: boolean}`.
- **Permission:** Verified ticket service authority (`extrachill_events_ticket_has_service_authority`).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/revoke-ticket-passes`

- **Purpose:** revoke-ticket-passes See [existing guide](paid-tickets.md).
- **Input:** `order_reference` (string, required); `reason` (string, required).
- **Output:** `object {revoked: integer}`.
- **Permission:** Verified ticket service authority (`extrachill_events_ticket_has_service_authority`).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Revokes order passes; dispute handling notifies seller owners and preserves redeemed check-ins.

### `extrachill/restore-ticket-passes`

- **Purpose:** restore-ticket-passes See [existing guide](paid-tickets.md).
- **Input:** `order_reference` (string, required); `reason` (string, required); `email` (string, optional).
- **Output:** `object {restored: integer}`.
- **Permission:** Verified ticket service authority (`extrachill_events_ticket_has_service_authority`).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Restores order passes and optionally emails restored passes.

### `extrachill/notify-commerce-seller`

- **Purpose:** notify-commerce-seller See [existing guide](paid-tickets.md).
- **Input:** `seller` (object {type: string, id: integer}, required); `subject` (string, required); `preheader` (string, optional); `body_html` (string, required); `cta_url` (string, optional); `cta_label` (string, optional).
- **Output:** `object {sent: integer}`.
- **Permission:** Verified ticket service authority (`extrachill_events_ticket_has_service_authority`).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Emails seller owners the supplied commerce notification.

### `extrachill/resend-ticket-pass-emails`

- **Purpose:** resend-ticket-pass-emails See [existing guide](paid-tickets.md).
- **Input:** `order_reference` (string, required); `email` (string, required).
- **Output:** `object {emailed: integer, failed: integer}`.
- **Permission:** Verified ticket service authority (`extrachill_events_ticket_has_service_authority`).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Emails the order's active passes to the buyer again (not idempotent).

## Managed Event

Registration: [`ManagedEventAbilities.php`](../inc/Abilities/ManagedEventAbilities.php). The names are the `LIST_ABILITY` and `UPDATE_ABILITY` class constants. Scope rules are described in [promoter authority](promoter-authority.md#managed-events).

### `extrachill/list-managed-events`

- **Purpose:** List published events at venues where the user is an active venue-team member, or tagged with a promoter where they are an active promoter member.
- **Input:** All optional: `when` (`upcoming` default, `past`, `any`), `date_from`, `date_to` (`YYYY-MM-DD`), `venue_term_id`, `promoter_term_id`, `scope` (`mine` default, `all`), `limit` (1–100, default 20), `page` (default 1). Filters narrow the user's scope; they never widen it.
- **Output:** `object {events: array<{id, title, start, end, venue, promoters, permalink, guest_list: {enabled, mode, password_required}}>, managed: {all, venues, promoters}, total, page, limit}`.
- **Permission:** A signed-in user whose agent principal ceiling admits `read`. `scope: all` also requires `edit_others_posts`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found (intended for agents through the Extra Chill MCP).

### `extrachill/update-managed-event`

- **Purpose:** Edit the details of an event the user manages.
- **Input:** `event` (integer, required); optional `startDate`, `startTime`, `endDate`, `endTime`, `description`, `price`, `ticketUrl`, `eventStatus` (`EventScheduled`, `EventPostponed`, `EventCancelled`, `EventRescheduled`), `eventType` (event-type vocabulary). Send at least one field. Venue and promoter are rejected.
- **Output:** `object {success: boolean, event_id: integer, status: updated|no_change, updated_fields: array<string>, warnings: array}`.
- **Permission:** The managed scope must cover the event, and the event must be published unless the user has `edit_others_posts`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Applies the update through Data Machine Events' `EventUpdateAbilities::executeUpdateEvent()`. When something changed, the event is marked human-owned (`EventOwnership::markHumanOwned()`), so later imports only fill empty fields.

## Promoter Workspace

Registration: [`PromoterWorkspaceAbilities.php`](../inc/Abilities/PromoterWorkspaceAbilities.php). Both abilities are read-only and need only an effective signed-in user (`TeamAuthorization::effective_user_id()`); results cover only identities that user holds.

### `extrachill/list-managed-workspace-identities`

- **Purpose:** List the venue and promoter identities the user can manage.
- **Input:** No fields.
- **Output:** `object {actor: {id, name}, identities: array<{reference: "venue:N"|"promoter:N", type, id, name, is_owner, permissions}>}`. Permissions are drawn from `access_venue`, `manage_members`, `manage_finances`, `access_promoter`.
- **Permission:** Signed-in user.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/resolve-managed-workspace-context`

- **Purpose:** Resolve the selected workspace identity and its context.
- **Input:** `selected_reference` (string, required; `""`, `venue:N` or `promoter:N`).
- **Output:** `object {actor, identities, selection: {reference, state: active|empty|stale|denied, type?, id?, reason?}, promoter: identity + link_page {status: available|not_provisioned|unavailable, management_url} | null, venue: identity|null, granted_venues: [], promoter_relationships: []}`.
- **Permission:** Signed-in user.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

## Venue Calendar Feed

Registration: [`VenueCalendarFeedAbilities.php`](../inc/Abilities/VenueCalendarFeedAbilities.php). All four take `venue_term_id` and return the feed state `object {venue_term_id, bound, feed_url, status: active|paused|error|"", last_synced, last_error, event_count?, created?, updated?, unchanged?, cancelled?, skipped?, excluded?}`. Permission is `current_user_can('ec_access_venue', venue_term_id)`. Exposure is `show_in_rest: true`; the venue-settings Calendar feed tab calls them.

### `extrachill/get-venue-calendar-feed`

- **Purpose:** Read the venue's feed binding and sync state.
- **Input:** `venue_term_id` (integer, required).
- **Output:** Feed state.
- **Permission:** `ec_access_venue`.
- **Exposure:** `show_in_rest: true`; Calendar feed tab.

### `extrachill/set-venue-calendar-feed`

- **Purpose:** Bind a public ICS feed to the venue.
- **Input:** `venue_term_id` (integer), `feed_url` (string; `webcal://` is normalized to `https://`), both required.
- **Output:** Feed state plus `event_count`, the number of importable entries found.
- **Permission:** `ec_access_venue`.
- **Exposure:** `show_in_rest: true`; Calendar feed tab.
- **Side effects:** Fetches the URL (15 s timeout) and rejects unreachable, non-2xx, oversized or non-calendar responses. It then stores the URL in term meta with status `active` and clears the last error.

### `extrachill/sync-venue-calendar-feed`

- **Purpose:** Import the bound feed now.
- **Input:** `venue_term_id` (integer, required).
- **Output:** Feed state merged with sync counts.
- **Permission:** `ec_access_venue`.
- **Exposure:** `show_in_rest: true`; Calendar feed tab.
- **Side effects:** Fails with `venue_calendar_feed_not_bound` when no feed is bound. Reactivates a paused or errored binding, then runs `VenueCalendarFeedSync::sync_venue()`. That sync upserts only events whose source is this venue's feed.

### `extrachill/remove-venue-calendar-feed`

- **Purpose:** Remove the venue's feed binding.
- **Input:** `venue_term_id` (integer, required).
- **Output:** The cleared feed state.
- **Permission:** `ec_access_venue`.
- **Exposure:** `show_in_rest: true`; Calendar feed tab.
- **Side effects:** Deletes the binding term meta (URL, status, last synced, last error). Imported events are kept.

## Promoter Authority

Registration: [`PromoterAuthorityAbilities.php`](../inc/Abilities/PromoterAuthorityAbilities.php). The verification and membership model is described in [promoter authority](promoter-authority.md). Every operation is `show_in_rest: true` with no API/CLI caller found. Execution reauthorizes with the same capability as the permission callback, using `TeamAuthorization::effective_user_id()`.

- **Organization object:** `{id, promoter_term_id, status: active|revoked, version, verified_by_user_id, verified_at, updated_at, revoked_by_user_id, revoked_at}`.
- **Membership object:** `{id, promoter_term_id, user_id, is_owner, status, version, created_by_user_id, created_at, updated_at, …}`.
- **Invitation object:** `{id, public_id, team_term_id, team_type, user_id, is_owner, status, version, invited_by_user_id, created_at, updated_at, expires_at, resolved_at, delivery_status, delivery_attempts, delivered_at, display_name?, email?, delivery_queued?, account_created?}`.

### `extrachill/verify-promoter-organization`

- **Purpose:** Verify a promoter term, optionally bootstrapping its first owner.
- **Input:** `promoter_term_id` (integer, required); `owner_user_id` (integer, optional).
- **Output:** `object {organization, membership: membership|null}`.
- **Permission:** `ec_verify_promoter` (site administrator).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Writes `_ec_promoter_status` and `_ec_promoter_verification` term meta; creates the owner membership when `owner_user_id` is given.

### `extrachill/revoke-promoter-organization`

- **Purpose:** Revoke a verified promoter while preserving its history.
- **Input:** `promoter_term_id`, `expected_version` (integers, required).
- **Output:** Organization object.
- **Permission:** `ec_revoke_promoter` (site administrator).
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Marks the organization revoked. Revoked organizations accept no membership changes.

### `extrachill/create-promoter-membership`

- **Purpose:** Add an existing network user to a verified promoter.
- **Input:** `promoter_term_id`, `user_id` (integers), `is_owner` (boolean), all required.
- **Output:** Membership object.
- **Permission:** `ec_manage_promoter_members`: a site administrator, or an active owner of that promoter.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Inserts a `promoter` row in `ec_team_members`. Fails if more than 100 rows are stored.

### `extrachill/update-promoter-membership`

- **Purpose:** Set or clear a member's owner flag.
- **Input:** `promoter_term_id`, `user_id`, `is_owner`, `expected_version` (all required).
- **Output:** Membership object.
- **Permission:** `ec_manage_promoter_members`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Versioned update. An owner cannot demote the final active owner; administrators can.

### `extrachill/revoke-promoter-membership`

- **Purpose:** Revoke a promoter membership.
- **Input:** `promoter_term_id`, `user_id`, `expected_version` (all required).
- **Output:** Membership object.
- **Permission:** `ec_manage_promoter_members`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Versioned revocation; the row is preserved. The same final-owner rule applies.

### `extrachill/list-promoter-memberships`

- **Purpose:** List the preserved membership records of one promoter.
- **Input:** `promoter_term_id` (integer, required).
- **Output:** Array of membership objects, at most 100.
- **Permission:** `ec_manage_promoter_members`.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/invite-promoter-member`

- **Purpose:** Invite someone to a promoter team by email.
- **Input:** `promoter_term_id` (integer), `email` (string), `is_owner` (boolean), all required.
- **Output:** Invitation object with `delivery_queued` and `account_created`.
- **Permission:** Any signed-in user at the callback. `TeamOnboardingService::invite()` then requires member-management authority on the promoter.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Creates an unclaimed network account (`extrachill/create-user`) if the email has none. Persists the invitation and queues email delivery through Action Scheduler. A newly created account is rolled back on failure.

### `extrachill/resend-promoter-invitation`

- **Purpose:** Rotate the token and resend a pending invitation.
- **Input:** `invitation_id`, `expected_version` (integers, required).
- **Output:** Invitation object.
- **Permission:** Signed-in user; the service requires member-management authority.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Rotates the token, bumps the version and queues another delivery.

### `extrachill/cancel-promoter-invitation`

- **Purpose:** Cancel a pending invitation.
- **Input:** `invitation_id`, `expected_version` (integers, required).
- **Output:** Invitation object.
- **Permission:** Signed-in user; the service requires member-management authority.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Cancels the invitation and deletes the unclaimed account it created, if still unused.

### `extrachill/list-promoter-invitations`

- **Purpose:** List invitations for a promoter team.
- **Input:** `promoter_term_id` (integer, required).
- **Output:** Array of invitation objects with `display_name` and `email`.
- **Permission:** Signed-in user; the service requires member-management authority.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.

### `extrachill/respond-promoter-invitation`

- **Purpose:** Accept or reject an invitation as the invited user.
- **Input:** `public_id`, `token` (strings), `promoter_term_id` (integer), `is_owner` (boolean), `expected_version` (integer), `decision` (`accepted` or `rejected`), all required.
- **Output:** Invitation object.
- **Permission:** Signed-in user. The repository binds the invitation to that user and checks the token and every echoed field.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Acceptance activates the membership. Rejection, or expiry (410), removes an unused invitation-created account.

## Venue Membership

Registration: [`VenueMembershipAbilities.php`](../inc/Abilities/VenueMembershipAbilities.php). Every operation is permitted by `TeamCapabilities::authorize('ec_manage_venue_members', venue_term_id)` and reauthorized inside execution. Every operation returns the membership object `{id, venue_term_id, user_id, display_name, email, is_owner, status: active|invited|revoked, version, created_by_user_id, created_at, updated_at, revoked_at}` and is `show_in_rest: true`.

### `extrachill/create-venue-membership`

- **Purpose:** Add an existing network user to a venue as an active member.
- **Input:** `venue_term_id`, `user_id` (integers), `is_owner` (boolean), all required.
- **Output:** Membership object.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`; used by the network rig journey seeds.
- **Side effects:** Inserts an active venue row through `VenueMembershipRepository::create()`.

### `extrachill/update-venue-membership`

- **Purpose:** Change a member's owner flag.
- **Input:** `venue_term_id`, `user_id`, `is_owner`, `expected_version` (all required).
- **Output:** Membership object.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`; venue-settings Team tab.
- **Side effects:** Versioned change through `TeamMembershipRepository::change_member()`.

### `extrachill/revoke-venue-membership`

- **Purpose:** Revoke a venue membership.
- **Input:** `venue_term_id`, `user_id`, `expected_version` (all required).
- **Output:** Membership object.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`; venue-settings Team tab.
- **Side effects:** Versioned revocation through `TeamMembershipRepository::change_member()`; the row is preserved.

### `extrachill/list-venue-memberships`

- **Purpose:** List a venue's members.
- **Input:** `venue_term_id` (integer, required); optional `is_owner`, `status`, `limit` (1–100, default 50), `offset` (default 0).
- **Output:** Array of membership objects.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`; venue-settings block.

### `extrachill/venue-export-show-updates-subscribers`

- **Purpose:** Export the fans who opted in to one venue's weekly show updates (extrachill#1724), so the venue keeps its email audience.
- **Input:** `venue_term_id` (integer, required).
- **Output:** `subscribers[]` (`email`, `display_name`, `unsubscribe_url`), `venue_name`, `export_date`, `total`.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`. Registered in [`VenueShowUpdatesAbilities.php`](../inc/Abilities/VenueShowUpdatesAbilities.php).
- **Side effects:** None. Only subscribers who still allow notification email are returned.

## Venue Onboarding

Registration: [`VenueOnboardingAbilities.php`](../inc/Abilities/VenueOnboardingAbilities.php). Claims and invitations run through the shared `TeamOnboardingService` from Extra Chill Users, with the venue `VenueOnboardingAdapter`. All are `show_in_rest: true`; the output schema is a loose object or array holding the public claim or invitation record.

### `extrachill/submit-venue-claim`

- **Purpose:** Ask to become the owner of an unmanaged venue.
- **Input:** `venue_term_id` (integer, required).
- **Output:** Claim record.
- **Permission:** Signed-in user.
- **Exposure:** `show_in_rest: true`; venue-settings Claims tab.
- **Side effects:** Inserts a pending claim. Fails if the user already has an invitation or membership for the venue.

### `extrachill/review-venue-claim`

- **Purpose:** Approve or reject a pending claim.
- **Input:** `claim_id`, `expected_version` (integers), `decision` (`approved` or `rejected`), all required.
- **Output:** Claim record.
- **Permission:** `manage_options`.
- **Exposure:** `show_in_rest: true`; venue-settings Claims tab.
- **Side effects:** Versioned decision. Approval grants the claimant an owner membership under the membership lock.

### `extrachill/cancel-venue-claim`

- **Purpose:** Withdraw a pending claim.
- **Input:** `claim_id`, `expected_version` (integers, required).
- **Output:** Claim record.
- **Permission:** Signed-in user; the repository checks the claim's actor.
- **Exposure:** `show_in_rest: true`; venue-settings block.
- **Side effects:** Marks the claim `cancelled`.

### `extrachill/list-venue-claims`

- **Purpose:** List claims for review.
- **Input:** `status` (`pending`, `approved`, `rejected`, `cancelled`; optional).
- **Output:** Array of claim records.
- **Permission:** `manage_options`.
- **Exposure:** `show_in_rest: true`; venue-settings block.

### `extrachill/create-venue-invitation`

- **Purpose:** Invite someone to a venue team by email.
- **Input:** `venue_term_id` (integer), `email` (email), `is_owner` (boolean), all required.
- **Output:** Invitation record with `delivery_queued` and `account_created`.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`; venue-settings Team tab.
- **Side effects:** Like `extrachill/invite-promoter-member`: may create an unclaimed account, persists the invitation and queues email delivery.

### `extrachill/resend-venue-invitation`

- **Purpose:** Rotate the token and resend a pending invitation.
- **Input:** `venue_term_id`, `invitation_id`, `expected_version` (integers, required).
- **Output:** Invitation record.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`; venue-settings Team tab.
- **Side effects:** Rotates the token and queues another delivery.

### `extrachill/cancel-venue-invitation`

- **Purpose:** Cancel a pending invitation.
- **Input:** `venue_term_id`, `invitation_id`, `expected_version` (integers, required).
- **Output:** Invitation record.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`; venue-settings Team tab.
- **Side effects:** Cancels the invitation and deletes the unused account it created.

### `extrachill/list-venue-invitations`

- **Purpose:** List a venue's invitations.
- **Input:** `venue_term_id` (integer, required).
- **Output:** Array of invitation records with `display_name` and `email`.
- **Permission:** `ec_manage_venue_members`.
- **Exposure:** `show_in_rest: true`; venue-settings block.

### `extrachill/respond-to-venue-invitation`

- **Purpose:** Accept or reject a venue invitation as the invited user.
- **Input:** `invitation_id` (UUID public ID), `token` (64 characters), `venue_term_id` (integer), `is_owner` (boolean), `expected_version` (integer), `decision` (`accepted` or `rejected`), all required.
- **Output:** Invitation record.
- **Permission:** Signed-in user; the repository binds the invitation to that user and token.
- **Exposure:** `show_in_rest: true`; API/CLI: none found.
- **Side effects:** Acceptance activates the membership. Rejection or expiry removes an unused invitation-created account.
