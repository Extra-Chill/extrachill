# Extra Chill Booking

Inert placeholder for the [booking extraction](https://github.com/Extra-Chill/extrachill/issues/512).
The bootstrap does nothing: all live booking functionality remains in `extrachill-events`
until the atomic code move. Activating this component cannot register duplicate booking
handlers, create tables or migrate data.

## Ownership after extraction

Venue booking operations: inquiries, holds, deals, correspondence, attachments,
ticket-sales reconciliation and show settlement. This includes `Booking*`,
`VenueBooking*`, `ArtistBookingInquiry*`, `TicketSettlement*`, `TicketReconciliation*`,
`ShowSettlement*`, `LocalBookingPrivateFileProvider` and `BookingHold*`, their abilities
and tests, the `extrachill/venue-booking-inquiry` block, booking embed template,
and booking tabs, console and forms within venue settings. The later network-block
activation swap and booking concurrency workflows belong to the extraction as well;
none move in this scaffold.

Events retains the calendar, event post type, venues, membership, claims, invitations,
onboarding (`ec_venue_*`), promoter authority/grants, RSVP passes, guest list/perks,
event submission, local scene digest and venue link pages. Dependencies go only
from booking to events (including VenueAuthorization and Data Machine Events),
never from events to booking.

All existing `ec_booking_*` tables, `extrachill_events_booking_*` options, hooks,
`extrachill/*-booking-*` abilities, block names and REST routes must stay byte-identical.
There is no data migration.

## Deploy and activate

Requires PHP 8.4 and `extrachill-events`. The rig activates this plugin only on
`events.extrachill.com`. CI discovers `homeboy.json` through `components: changed`;
release discovers it through `components: all`. No workflow allowlist is needed.

After merge and deploy, the operator must activate the placeholder once:

```sh
wp plugin activate extrachill-booking --url=events.extrachill.com
```

Do not network-activate it. Include this operator step in the PR body. The later
atomic code move relies on the placeholder already being active.

## Tests

```sh
composer install
composer test
```

The PHPUnit smoke suite runs standalone locally and in Homeboy's managed WordPress
sandbox for CI. It loads the inert bootstrap and checks its plugin header,
dependency and text domain. Existing events booking
PHPUnit suites and concurrency proof workflows are unchanged.
