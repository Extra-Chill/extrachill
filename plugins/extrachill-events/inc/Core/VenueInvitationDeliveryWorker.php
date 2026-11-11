<?php
/**
 * Events compatibility facade over the network invitation delivery worker.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use ExtraChill\Users\Teams\TeamInvitationDeliveryWorker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Venue or promoter delivery worker with the historical constructor. */
class VenueInvitationDeliveryWorker extends TeamInvitationDeliveryWorker {
	public function __construct( ?VenueOnboardingRepository $repository = null, string $team_type = 'venue' ) {
		parent::__construct( new VenueOnboardingAdapter( $team_type ), $repository ? $repository : new VenueOnboardingRepository( null, null, $team_type ) );
	}
}
