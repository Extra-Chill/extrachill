# Venue settings extensions

The venue-settings shell uses `@wordpress/hooks`; it does not import booking UI.
Register extensions before the shell's `view.js` executes. The booking provider
prepends the separate `booking-settings.js` entry to this block's `viewScript`
metadata, so it is only enqueued with the block while booking is loaded.

- `extrachill.venueSettings.extensions` filters an array of `{ id, component }`.
  A provider component receives `context`, authorized `venues`, `profiles`,
  `onDirtyChange(id, boolean)`, and a render-function `children`. Call children
  with the feature-owned state/render helpers consumed by its tabs. Provider
  state remains mounted across tab switches. Report dirty state to preserve
  the shell's unload and identity-switch guards.
- `extrachill.venueSettings.tabs` filters the base tabs, with
  `{ context, accessibleVenues, manageableVenues }` as its context argument.
  Additional tabs provide `id`, `label`, `component`, optional `extension`
  (provider id), and optional `isVisible(context)` predicate. The component
  receives `context`, `venue`, `tab`, `idPrefix`, and `extension` by default.
  Set `scope: 'workspace'` to render once with `context`, `venues`, and
  `extension`, rather than once per accessible venue.

Visibility is presentation only; canonical Abilities still authorize every
read and write. With no extension registered, the core venue, calendar feed,
graphics, Link Page, and team tabs continue to work without booking calls.

The archive exposes `extrachill_events_venue_archive_actions($term)` in its
venue header and `extrachill_events_venue_archive_after_calendar($term)` after
the calendar. Booking registers its CTA and inquiry block renderer on these
hooks from `inc/core/booking-ui.php`, which also owns inquiry block registration.
No persisted identifiers or public booking contracts are renamed.
