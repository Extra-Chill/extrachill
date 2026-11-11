<?php
/**
 * Events compatibility facade over the network onboarding service.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use ExtraChill\Users\Teams\TeamInvitationToken;
use ExtraChill\Users\Teams\TeamOnboardingService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Venue or promoter onboarding service with the historical constructor. */
class VenueOnboardingService extends TeamOnboardingService {
	public function __construct( ?VenueOnboardingRepository $repository = null, ?TeamInvitationToken $tokens = null, ?TeamAuthorization $authorization = null, string $team_type = 'venue' ) {
		$tokens = $tokens ? $tokens : new TeamInvitationToken();
		parent::__construct(
			new VenueOnboardingAdapter( $team_type, $authorization ),
			$repository ? $repository : new VenueOnboardingRepository( $tokens, $authorization, $team_type ),
			$tokens
		);
	}
}
