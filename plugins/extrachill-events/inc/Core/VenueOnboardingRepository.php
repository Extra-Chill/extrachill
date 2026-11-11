<?php
/**
 * Events compatibility facade over the network onboarding repository.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use ExtraChill\Users\Teams\TeamInvitationToken;
use ExtraChill\Users\Teams\TeamOnboardingRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Venue or promoter onboarding repository with the historical constructor. */
class VenueOnboardingRepository extends TeamOnboardingRepository {
	public function __construct( ?TeamInvitationToken $tokens = null, ?TeamAuthorization $authorization = null, string $team_type = 'venue' ) {
		parent::__construct( new VenueOnboardingAdapter( $team_type, $authorization ), $tokens );
	}
}
