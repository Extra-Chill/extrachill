<?php
/**
 * Tests for buyer Terms consent at checkout and Seller Agreement acceptance
 * before Stripe onboarding.
 *
 * @package ExtraChillShop
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
// Stripe doubles: the onboarding ability's account + link creation.
if ( ! function_exists( 'extrachill_shop_create_seller_stripe_account' ) ) {
	function extrachill_shop_create_seller_stripe_account( $seller ) {
		$GLOBALS['test_stripe_calls'][] = array( 'account', $seller );
		return array(
			'success'    => true,
			'account_id' => 'acct_legal',
		);
	}
}
if ( ! function_exists( 'extrachill_shop_create_account_link' ) ) {
	function extrachill_shop_create_account_link( $account_id, $type = 'account_onboarding', $seller = null ) {
		$GLOBALS['test_stripe_calls'][] = array( 'link', $account_id, $type, $seller );
		return array(
			'success' => true,
			'url'     => 'https://connect.stripe.com/setup/e/acct_legal',
		);
	}
}

require_once dirname( __DIR__ ) . '/inc/core/legal-documents.php';
require_once dirname( __DIR__ ) . '/inc/core/checkout-terms.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-stripe-onboarding-link.php';

final class LegalConsentTest extends TestCase {
	private const VENUE = array(
		'type' => 'venue',
		'id'   => 1524,
	);

	protected function setUp(): void {
		$GLOBALS['test_options']      = array();
		$GLOBALS['test_stripe_calls'] = array();
		$GLOBALS['test_user_id']      = 42;
		unset( $GLOBALS['test_filters']['extrachill_shop_legal_documents'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['test_filters']['extrachill_shop_legal_documents'] );
	}

	private function onboard( array $extra = array() ) {
		return extrachill_shop_ability_stripe_onboarding_link( array_merge( array( 'seller' => self::VENUE ), $extra ) );
	}

	public function test_legal_documents_are_one_filterable_source(): void {
		$documents = extrachill_shop_legal_documents();
		$this->assertSame( 'https://extrachill.com/terms-of-service/', $documents['terms']['url'] );
		$this->assertSame( '2026-11-14', $documents['terms']['version'] );
		$this->assertSame( 'https://extrachill.com/seller-agreement/', $documents['seller_agreement']['url'] );
		$this->assertSame( '2026-11-14', $documents['seller_agreement']['version'] );
		$this->assertSame( 'https://extrachill.com/privacy-policy/', $documents['privacy']['url'] );

		add_filter(
			'extrachill_shop_legal_documents',
			static function ( $docs ) {
				$docs['seller_agreement']['version'] = '2027-01-01';
				return $docs;
			}
		);
		$this->assertSame( '2027-01-01', extrachill_shop_legal_document( 'seller_agreement', 'version' ) );
		$this->assertSame( 'https://extrachill.com/seller-agreement/', extrachill_shop_legal_document( 'seller_agreement', 'url' ) );
	}

	public function test_onboarding_is_blocked_without_seller_agreement_acceptance(): void {
		$result = $this->onboard();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'seller_agreement_required', $result->get_error_code() );
		$this->assertSame( 'https://extrachill.com/seller-agreement/', $result->get_error_data()['seller_agreement_url'] );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'https://extrachill.com/seller-agreement/', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['test_stripe_calls'], 'No Stripe account or link before acceptance.' );

		$explicit_no = $this->onboard( array( 'accept_seller_agreement' => false ) );
		$this->assertSame( 'seller_agreement_required', $explicit_no->get_error_code() );
		$this->assertSame( array(), $GLOBALS['test_options'] );
	}

	public function test_accepting_records_acceptance_and_allows_onboarding(): void {
		$result = $this->onboard( array( 'accept_seller_agreement' => true ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://connect.stripe.com/setup/e/acct_legal', $result['url'] );
		$this->assertSame( '2026-11-14', $result['seller_agreement_version'] );
		$this->assertSame( array( 'link', 'acct_legal', 'account_onboarding', self::VENUE ), $GLOBALS['test_stripe_calls'][1] );

		$record = $GLOBALS['test_options']['extrachill_shop_seller_agreement_venue:1524'][42];
		$this->assertSame( 42, $record['user_id'] );
		$this->assertSame( self::VENUE, $record['seller'] );
		$this->assertSame( '2026-11-14', $record['version'] );
		$this->assertNotFalse( strtotime( $record['accepted_at'] ) );

		// Acceptance persists: later links don't need the flag again.
		$this->assertTrue( $this->onboard()['success'] );
	}

	public function test_acceptance_is_per_user_per_seller_and_per_version(): void {
		$this->onboard( array( 'accept_seller_agreement' => true ) );

		// Another manager of the same venue has not accepted.
		$GLOBALS['test_user_id'] = 77;
		$this->assertSame( 'seller_agreement_required', $this->onboard()->get_error_code() );

		// The same user has not accepted for a different seller.
		$GLOBALS['test_user_id'] = 42;
		$other                   = extrachill_shop_ability_stripe_onboarding_link( array( 'artist_id' => 9 ) );
		$this->assertSame( 'seller_agreement_required', $other->get_error_code() );

		// A new Seller Agreement version requires fresh acceptance.
		add_filter(
			'extrachill_shop_legal_documents',
			static function ( $docs ) {
				$docs['seller_agreement']['version'] = '2027-01-01';
				return $docs;
			}
		);
		$this->assertFalse( extrachill_shop_has_accepted_seller_agreement( self::VENUE, 42 ) );
		$this->assertSame( 'seller_agreement_required', $this->onboard()->get_error_code() );
		$this->assertTrue( $this->onboard( array( 'accept_seller_agreement' => true ) )['success'] );
		$this->assertSame( '2027-01-01', extrachill_shop_get_seller_agreement_acceptance( self::VENUE, 42 )['version'] );
	}

	public function test_onboarding_ability_input_schema_is_closed(): void {
		extrachill_shop_register_stripe_onboarding_link_ability();
		$schema = $GLOBALS['test_abilities']['extrachill/shop-stripe-onboarding-link']['input_schema'];

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 'boolean', $schema['properties']['accept_seller_agreement']['type'] );
	}

	public function test_store_api_checkout_records_terms_acceptance_meta(): void {
		$order = new PriorityBoostTestOrder( 501, array() );

		extrachill_shop_record_checkout_terms_acceptance( $order );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $order->meta['_extrachill_terms_accepted_at'] );
		$this->assertEqualsWithDelta( time(), strtotime( $order->meta['_extrachill_terms_accepted_at'] ), 5 );
		$this->assertSame( '2026-11-14', $order->meta['_extrachill_terms_version'] );
		$this->assertSame( 1, $order->save_count );

		// Non-order payloads are ignored.
		extrachill_shop_record_checkout_terms_acceptance( null );
	}

	public function test_render_forces_required_checkbox_on_terms_block_only(): void {
		$block = extrachill_shop_require_checkout_terms_block(
			array(
				'blockName' => 'woocommerce/checkout-terms-block',
				'attrs'     => array(
					'checkbox'      => false,
					'showSeparator' => false,
				),
			)
		);
		$this->assertTrue( $block['attrs']['checkbox'] );
		$this->assertFalse( $block['attrs']['showSeparator'] );
		$this->assertStringContainsString( 'href="https://extrachill.com/terms-of-service/"', $block['attrs']['text'] );
		$this->assertStringContainsString( 'href="https://extrachill.com/privacy-policy/"', $block['attrs']['text'] );

		$other = array(
			'blockName' => 'woocommerce/checkout-actions-block',
			'attrs'     => array(),
		);
		$this->assertSame( $other, extrachill_shop_require_checkout_terms_block( $other ) );
	}

	public function test_provisioning_updates_existing_terms_block(): void {
		$content = "<!-- wp:woocommerce/checkout -->\n<div><!-- wp:woocommerce/checkout-fields-block -->\n<div>"
			. "<!-- wp:woocommerce/checkout-terms-block {\"showSeparator\":false} -->\n<div class=\"wp-block-woocommerce-checkout-terms-block\"></div>\n<!-- /wp:woocommerce/checkout-terms-block -->\n\n"
			. "<!-- wp:woocommerce/checkout-actions-block -->\n<div></div>\n<!-- /wp:woocommerce/checkout-actions-block --></div>\n<!-- /wp:woocommerce/checkout-fields-block --></div>\n<!-- /wp:woocommerce/checkout -->";

		$updated = extrachill_shop_ensure_checkout_terms_markup( $content );
		$attrs   = $this->terms_attrs( $updated );

		$this->assertTrue( $attrs['checkbox'] );
		$this->assertFalse( $attrs['showSeparator'] );
		$this->assertSame( extrachill_shop_checkout_terms_text(), $attrs['text'] );
		$this->assertSame( 1, substr_count( $updated, '<!-- wp:woocommerce/checkout-terms-block' ) );
		$this->assertStringNotContainsString( '<a href', substr( $updated, 0, strpos( $updated, '-->', strpos( $updated, 'checkout-terms-block' ) ) ), 'Delimiter JSON escapes HTML.' );
		$this->assertSame( $updated, extrachill_shop_ensure_checkout_terms_markup( $updated ), 'Provisioning is idempotent.' );
	}

	public function test_provisioning_inserts_missing_terms_block_before_actions(): void {
		$content = "<!-- wp:woocommerce/checkout -->\n<div><!-- wp:woocommerce/checkout-fields-block -->\n<div>"
			. "<!-- wp:woocommerce/checkout-actions-block -->\n<div></div>\n<!-- /wp:woocommerce/checkout-actions-block --></div>\n<!-- /wp:woocommerce/checkout-fields-block --></div>\n<!-- /wp:woocommerce/checkout -->";

		$updated = extrachill_shop_ensure_checkout_terms_markup( $content );

		$this->assertTrue( $this->terms_attrs( $updated )['checkbox'] );
		$this->assertLessThan(
			strpos( $updated, '<!-- wp:woocommerce/checkout-actions-block' ),
			strpos( $updated, '<!-- wp:woocommerce/checkout-terms-block' )
		);
		$this->assertStringContainsString( '<!-- /wp:woocommerce/checkout-terms-block -->', $updated );

		$shortcode = '[woocommerce_checkout]';
		$this->assertSame( $shortcode, extrachill_shop_ensure_checkout_terms_markup( $shortcode ) );
	}

	private function terms_attrs( string $content ): array {
		$this->assertSame( 1, preg_match( '#<!-- wp:woocommerce/checkout-terms-block (\{.*?\}) -->#s', $content, $matches ) );
		return json_decode( $matches[1], true );
	}
}
