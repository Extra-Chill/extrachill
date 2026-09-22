<?php
/**
 * Subscribe ability email validation coverage (issue #46).
 *
 * sanitize_email() cannot itself detect hostile input: a 2025-08-01
 * automated scanner probe demonstrated that an SQLi payload survives
 * sanitize_email() as a structurally valid, is_email()-passing address,
 * and the current code stored it as a subscriber. This is a
 * data-integrity fix, not a security patch -- sanitize_email() had
 * already neutralized the payload before subscribe() ever ran; no
 * injection occurred.
 *
 * This suite proves the fix rejects that exact payload while every
 * legitimate address shape from the issue still succeeds, across all
 * three real integration surfaces the scanner hit: navigation and
 * homepage (context-resolved list IDs) and a direct list_id (how a
 * standalone widget -- such as the historical "Popup Subscribe" form --
 * calls this ability without a registered context slug).
 *
 * @package ExtraChillNewsletter\Tests\Integration
 */

final class SubscribeEmailValidationTest extends WP_UnitTestCase {

	/**
	 * Real payload from
	 * https://github.com/Extra-Chill/extrachill-newsletter/issues/46,
	 * reproduced byte-for-byte from the Sendy subscriber rows the scanner
	 * created across all three surfaces on 2025-08-01.
	 */
	private const SCANNER_PAYLOAD = "testing@example.com'||DBMS_PIPE.RECEIVE_MESSAGE(CHR(98)||CHR(98)||CHR(98),15)||'";

	private int $subscribe_calls = 0;
	private bool $registered_fake_ability = false;
	private bool $registered_fake_analytics_ability = false;

	public function set_up(): void {
		parent::set_up();

		update_site_option(
			'extrachill_newsletter_settings',
			array(
				'navigation_list_id' => 'list-navigation',
				'homepage_list_id'   => 'list-homepage',
			)
		);

		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'datamachine/sendy-subscribe' ) ) {
			$this->markTestSkipped( 'The integration environment already provides the Sendy transport ability.' );
		}

		WP_Abilities_Registry::get_instance()->register(
			'datamachine/sendy-subscribe',
			array(
				'label'               => 'Fake Sendy subscribe transport',
				'description'         => 'Integration-only provider success.',
				'category'            => 'extrachill-newsletter',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => function ( $input ) {
					++$this->subscribe_calls;
					return array(
						'success' => true,
						'status'  => 'subscribed',
						'email'   => $input['email'] ?? '',
					);
				},
			)
		);
		$this->registered_fake_ability = true;

		// extrachill-analytics is not loaded in this integration runtime.
		// Stand in for its ability so subscribe()'s optional analytics call
		// doesn't trip a "Ability not found" doing_it_wrong notice -- that
		// plugin's real presence/absence is outside this issue's scope.
		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'extrachill/track-analytics-event' ) ) ) {
			WP_Abilities_Registry::get_instance()->register(
				'extrachill/track-analytics-event',
				array(
					'label'               => 'Fake analytics event transport',
					'description'         => 'Integration-only no-op.',
					'category'            => 'extrachill-newsletter',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => static function () {
						return array( 'success' => true );
					},
				)
			);
			$this->registered_fake_analytics_ability = true;
		}
	}

	public function tear_down(): void {
		if ( $this->registered_fake_ability ) {
			WP_Abilities_Registry::get_instance()->unregister( 'datamachine/sendy-subscribe' );
		}
		if ( $this->registered_fake_analytics_ability ) {
			WP_Abilities_Registry::get_instance()->unregister( 'extrachill/track-analytics-event' );
		}
		delete_site_option( 'extrachill_newsletter_settings' );
		parent::tear_down();
	}

	/**
	 * @dataProvider context_provider
	 */
	public function test_scanner_payload_is_rejected( array $context_args ): void {
		$result = wp_get_ability( 'extrachill/subscribe' )->execute(
			array_merge( array( 'email' => self::SCANNER_PAYLOAD ), $context_args )
		);

		$this->assertIsArray( $result, 'A rejected address must return a clean array response, not a WP_Error or fatal.' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid', $result['status'] );
		$this->assertSame(
			0,
			$this->subscribe_calls,
			'The Sendy transport must never be reached once the address is rejected -- no scanner residue should reach the list.'
		);
	}

	/**
	 * @dataProvider legitimate_address_and_context_provider
	 */
	public function test_legitimate_address_still_succeeds( string $email, array $context_args ): void {
		$result = wp_get_ability( 'extrachill/subscribe' )->execute(
			array_merge( array( 'email' => $email ), $context_args )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'], 'Expected ' . $email . ' to be accepted: ' . wp_json_encode( $result ) );
		$this->assertSame( 'subscribed', $result['status'] );
	}

	/**
	 * example.com/test.com are structurally valid and pass sanitize_email()
	 * untouched, so the sanitization-drift check alone does not catch them.
	 * They are reserved documentation/placeholder domains (RFC 2606) that
	 * no real subscriber can have a mailbox on.
	 */
	public function test_reserved_placeholder_domain_is_rejected(): void {
		$result = wp_get_ability( 'extrachill/subscribe' )->execute(
			array(
				'email'   => 'someone@example.com',
				'list_id' => 'list-direct',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid', $result['status'] );
		$this->assertSame( 0, $this->subscribe_calls );
	}

	/**
	 * Incidental whitespace from copy/paste is common and benign; the
	 * validator must trim before comparing so it doesn't false-positive
	 * on real signups the way it correctly rejects the scanner payload.
	 */
	public function test_incidental_whitespace_does_not_false_positive(): void {
		$result = wp_get_ability( 'extrachill/subscribe' )->execute(
			array(
				'email'   => " user@domain.com \n",
				'list_id' => 'list-direct',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( 'subscribed', $result['status'] );
	}

	/**
	 * The three real integration surfaces the 2025-08-01 scanner hit.
	 * navigation and homepage resolve list_id via a registered context;
	 * a direct list_id (no context) is how a standalone widget -- such as
	 * the historical "Popup Subscribe" form, which has no registered
	 * context slug in this codebase -- calls this ability.
	 */
	public static function context_provider(): array {
		return array(
			'navigation context'     => array( array( 'context' => 'navigation' ) ),
			'homepage context'       => array( array( 'context' => 'homepage' ) ),
			'direct list_id (popup)' => array( array( 'list_id' => 'list-popup' ) ),
		);
	}

	public static function legitimate_address_and_context_provider(): array {
		$addresses = array(
			'plus-tagged local part'    => 'user+tag@gmail.com',
			'dotted local part'         => 'first.last@domain.com',
			'apostrophe in local part'  => "o'brien@domain.com",
			'hyphenated subdomain'      => 'user-name@sub.domain.co.uk',
		);

		$cases = array();
		foreach ( $addresses as $address_label => $email ) {
			foreach ( self::context_provider() as $context_label => $context_args ) {
				$cases[ "{$address_label} via {$context_label}" ] = array( $email, $context_args[0] );
			}
		}

		return $cases;
	}
}
