<?php
/**
 * Regression tests for the performer-inclusive /join intent choices (#382).
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

/**
 * Join intent labels and their stable onboarding contract.
 *
 * @group onboarding
 */
class OnboardingJoinIntentsTest extends WP_UnitTestCase {

	/**
	 * Widening the performer label must not change intent order or roles.
	 */
	public function test_intent_ids_order_and_roles_are_unchanged(): void {
		$intents = ec_network_onboarding_join_intents( array() );

		$this->assertSame( array( 'artist', 'venue', 'promoter' ), array_column( $intents, 'id' ) );
		$this->assertSame(
			array( array( 'artist' ), array( 'professional' ), array( 'professional' ) ),
			array_column( $intents, 'roles' )
		);
		$this->assertSame( __( 'A venue I run or book', 'extrachill-network' ), $intents[1]['label'] );
	}

	/**
	 * Non-musical performers belong on the self-serve artist path, not promoter.
	 */
	public function test_labels_distinguish_performers_from_promoters_of_other_acts(): void {
		$intents = ec_network_onboarding_join_intents( array() );

		$this->assertSame(
			__( 'Me or my act (music, comedy, any performer)', 'extrachill-network' ),
			$intents[0]['label']
		);
		$this->assertStringContainsString( 'comedy', strtolower( $intents[0]['label'] ) );
		$this->assertStringContainsString( 'performer', strtolower( $intents[0]['label'] ) );
		$this->assertSame(
			__( 'Shows or a series I promote for other acts', 'extrachill-network' ),
			$intents[2]['label']
		);
	}
}
