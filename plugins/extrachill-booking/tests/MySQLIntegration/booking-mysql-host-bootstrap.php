<?php
/**
 * Bootstrap the native-process booking-mysql host proof.
 *
 * Chains the same WordPress-core-plus-DME boot `host-wordpress-bootstrap.php`
 * already provides (extrachill-events#882) with the multisite blog-7 booking
 * and promoter-authority schema provisioning `booking-schema-multisite-bootstrap.php`
 * provides — this suite's `BookingSchemaMultisiteTest` and
 * `PromoterAuthoritySchemaMultisiteTest` both assert against tables already
 * installed on blog 7 before their own test bodies run, the same precondition
 * the managed sandbox's `preload_files` already gives them there
 * (extrachill-events#870/#884).
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

require_once __DIR__ . '/host-wordpress-bootstrap.php';
require_once __DIR__ . '/booking-schema-multisite-bootstrap.php';

// BookingEventSyncMySQLIntegrationTest::test_public_service_keeps_combined_update_invisible_and_multisite_scoped
// exercises DME's real public data-machine-events/upsert-event ability.
// host-wordpress-bootstrap.php cherry-picks only the DME classes
// InternalBookingHoldConcurrencyMySQLProof needs, after WordPress's own
// wp_abilities_api_init/wp_abilities_api_categories_init lazy-init hooks have
// already fired once — too late for EventUpsertAbilities, which registers
// itself on wp_abilities_api_init from its constructor, and its ability
// category is registered by a *separate*, earlier, also-lazy hook this
// bootstrap does not re-fire. Manually re-firing both hooks in the right
// order to backfill a single ability, without also re-triggering every
// already-registered ability's own registration a second time, needs more
// than a two-line patch here. That test method is excluded from this host
// job only, via --exclude-group in booking-mysql-transaction-proofs-host.yml;
// it is unaffected in the managed sandbox, which activates the full
// data-machine-events plugin naturally and registers categories/abilities in
// the correct order on its own. Tracked as its own follow-up in
// extrachill-events#888.
