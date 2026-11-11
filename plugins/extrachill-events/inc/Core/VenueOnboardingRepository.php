<?php
/** Compatibility facade for existing Events onboarding consumers. */
namespace ExtraChillEvents\Core;

require_once __DIR__ . '/../../../extrachill-users/inc/teams/TeamInvitationToken.php';
require_once __DIR__ . '/../../../extrachill-users/inc/teams/TeamOnboardingAdapter.php';
require_once __DIR__ . '/../../../extrachill-users/inc/teams/TeamOnboardingRepository.php';
require_once __DIR__ . '/VenueOnboardingAdapter.php';

class VenueOnboardingRepository extends \ExtraChill\Users\Teams\TeamOnboardingRepository {
	public function __construct( ?VenueInvitationToken $tokens = null, ?TeamAuthorization $authorization = null, string $team_type = 'venue' ) {
		parent::__construct( new VenueOnboardingAdapter( $team_type, $authorization ), $tokens );
	}
}
