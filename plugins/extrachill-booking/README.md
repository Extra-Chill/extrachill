# Extra Chill Booking

Venue booking operations, extracted from Events in #548. PHP namespaces, ability names, tables, options and hooks retain their historical `ExtraChillEvents` / `extrachill_events` names. No migration is performed.

## Loading

The active plugin registers at `plugins_loaded` priority 5, before Events' schema (20) and abilities (25). It requires Events' venue layer and does nothing when Events is inactive or an older Events already owns `BookingProvider`.

Booking depends on Events and owns the inquiry block and its configuration/policy classes. The block is registered only on events.extrachill.com; other sites use the unchanged booking embed iframe.

Deploy Booking before Events. After deployment, network-deactivate the retired Events inquiry adapter. Booking tolerates the old adapter's already-loaded classes during the deploy window.

## Development

Build `packages/tokens`, `packages/api-client`, and `packages/components` first, then:

```sh
npm ci
npm run build
npm run test:js -- --runInBand
composer install
composer test
php tests/bootstrap-boundary.php
php tests/booking-ability-order.php
php tests/booking-schema-lifecycle.php
php tests/fixtures/venue-settings-p1d-runtime.php
```

MySQL proof suites require the WordPress multisite test installation and the external database configured by the host-proof workflows. Homeboy validates with Events as a dependency.
