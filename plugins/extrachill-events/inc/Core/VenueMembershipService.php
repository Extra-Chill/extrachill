<?php
/**
 * Path-only compatibility shim for extrachill-booking tests.
 *
 * The venue membership service was a pass-through and has been deleted:
 * VenueMembershipAbilities reauthorizes through `ec_manage_venue_members`
 * and mutates the users team store directly. The extrachill-booking tests
 * still require this path, so it only loads the authorization facade.
 * Removed in #1425 PR 2, together with VenueAuthorization.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/VenueAuthorization.php';
