<?php
/**
 * Artist mailing list unsubscribe: signed links, export column, member removal (#1568).
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistSubscriberUnsubscribeTest extends EC_Artist_Platform_TestCase {
	/** @var int */
	private $owner_id;

	/** @var int */
	private $profile_id;

	protected function setUp(): void {
		parent::setUp();

		$this->owner_id   = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->profile_id = $this->create_artist_profile( 'Futurebirds' );
		$this->create_artist_membership( $this->owner_id, $this->profile_id );
	}

	/** Run a callback on the artist blog with the subscriber table installed. */
	private function on_artist_blog( callable $callback ) {
		switch_to_blog( $this->artist_blog_id() );
		extrachill_artist_create_subscribers_table();
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/** Subscribe through the real public ability handler and return the row ID. */
	private function subscribe( string $email, int $artist_id = 0 ): int {
		$artist_id = $artist_id ? $artist_id : $this->profile_id;
		return $this->on_artist_blog(
			static function () use ( $email, $artist_id ): int {
				global $wpdb;
				$result = extrachill_artist_platform_ability_artist_subscribe(
					array(
						'id'    => $artist_id,
						'email' => $email,
					)
				);
				if ( is_wp_error( $result ) ) {
					return 0;
				}
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT subscriber_id FROM {$wpdb->prefix}artist_subscribers WHERE artist_profile_id = %d AND subscriber_email = %s",
						$artist_id,
						$email
					)
				);
			}
		);
	}

	/** Count rows for an email on an artist. */
	private function row_count( string $email, int $artist_id = 0 ): int {
		$artist_id = $artist_id ? $artist_id : $this->profile_id;
		return $this->on_artist_blog(
			static function () use ( $email, $artist_id ): int {
				global $wpdb;
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}artist_subscribers WHERE artist_profile_id = %d AND subscriber_email = %s",
						$artist_id,
						$email
					)
				);
			}
		);
	}

	/** Pull the signed token out of an unsubscribe URL. */
	private function token_from_url( string $url ): string {
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return (string) ( $query['token'] ?? '' );
	}

	/** Run the public link ability on the artist blog. */
	private function open_link( string $token, bool $confirm ): array {
		return $this->on_artist_blog(
			static function () use ( $token, $confirm ): array {
				$ability = wp_get_ability( 'extrachill/artist-subscriber-unsubscribe' );
				return $ability->execute(
					array(
						'token'   => $token,
						'confirm' => $confirm,
					)
				);
			}
		);
	}

	/** Export through the real ability handler as the artist owner. */
	private function export(): array {
		wp_set_current_user( $this->owner_id );
		return $this->on_artist_blog(
			function (): array {
				return extrachill_artist_platform_ability_artist_export_subscribers(
					array(
						'id'               => $this->profile_id,
						'include_exported' => true,
					)
				);
			}
		);
	}

	public function test_subscribe_form_rows_are_labelled_as_form_signups(): void {
		$this->subscribe( 'fan@example.com' );

		$source = $this->on_artist_blog(
			static function () {
				global $wpdb;
				return $wpdb->get_var( $wpdb->prepare( "SELECT source FROM {$wpdb->prefix}artist_subscribers WHERE subscriber_email = %s", 'fan@example.com' ) );
			}
		);

		$this->assertSame( 'artist_subscribe_form', $source );
	}

	public function test_export_includes_a_personal_unsubscribe_url_per_subscriber(): void {
		$this->subscribe( 'one@example.com' );
		$this->subscribe( 'two@example.com' );

		$export = $this->export();

		$this->assertSame( 2, $export['total'] );
		$urls = array();
		foreach ( $export['subscribers'] as $row ) {
			$this->assertArrayHasKey( 'unsubscribe_url', $row );
			$this->assertStringContainsString( '/extrachill/v1/artists/subscribers/unsubscribe', $row['unsubscribe_url'] );
			$this->assertNotSame( '', $this->token_from_url( $row['unsubscribe_url'] ) );
			$this->assertStringNotContainsString( rawurlencode( $row['email'] ), $row['unsubscribe_url'], 'The link must not carry the email address.' );
			$urls[] = $row['unsubscribe_url'];
		}
		$this->assertCount( 2, array_unique( $urls ), 'Each subscriber gets their own link.' );
	}

	public function test_valid_link_confirms_on_get_and_unsubscribes_on_post(): void {
		$this->subscribe( 'fan@example.com' );
		$this->subscribe( 'other@example.com' );
		$url = '';
		foreach ( $this->export()['subscribers'] as $row ) {
			if ( 'fan@example.com' === $row['email'] ) {
				$url = $row['unsubscribe_url'];
			}
		}
		$token = $this->token_from_url( $url );

		$page = $this->open_link( $token, false );
		$this->assertSame( 'confirm', $page['status'] );
		$this->assertStringContainsString( 'Futurebirds', $page['title'] );
		$this->assertStringContainsString( '<form method="post"', $page['html'] );
		$this->assertSame( 1, $this->row_count( 'fan@example.com' ), 'GET must never change the subscription.' );

		$page = $this->open_link( $token, true );
		$this->assertSame( 'unsubscribed', $page['status'] );
		$this->assertSame( "You've unsubscribed from Futurebirds", $page['title'] );
		$this->assertSame( 0, $this->row_count( 'fan@example.com' ) );
		$this->assertSame( 1, $this->row_count( 'other@example.com' ), 'Only the linked subscriber is removed.' );

		// Opening the link again stays friendly and idempotent.
		$this->assertSame( 'unsubscribed', $this->open_link( $token, true )['status'] );
	}

	public function test_link_still_works_after_the_fan_resubscribes(): void {
		$this->subscribe( 'fan@example.com' );
		$url = $this->export()['subscribers'][0]['unsubscribe_url'];

		$this->assertSame( 'unsubscribed', $this->open_link( $this->token_from_url( $url ), true )['status'] );
		$this->subscribe( 'fan@example.com' );
		$this->assertSame( 1, $this->row_count( 'fan@example.com' ) );

		$this->assertSame( 'unsubscribed', $this->open_link( $this->token_from_url( $url ), true )['status'] );
		$this->assertSame( 0, $this->row_count( 'fan@example.com' ) );
	}

	public function test_tampered_or_foreign_tokens_are_rejected(): void {
		$this->subscribe( 'fan@example.com' );
		$token = $this->token_from_url( $this->export()['subscribers'][0]['unsubscribe_url'] );

		// Flip one character of the signature.
		$last     = substr( $token, -1 );
		$tampered = substr( $token, 0, -1 ) . ( 'a' === $last ? 'b' : 'a' );
		$page     = $this->open_link( $tampered, true );
		$this->assertSame( 'invalid', $page['status'] );

		// Re-encode the claims to point at another artist while keeping the signature.
		list( $payload, $signature ) = explode( '.', $token );
		$claims                      = json_decode( base64_decode( strtr( $payload, '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test reads the signed transport.
		$claims['c']['a']            = $claims['c']['a'] + 1;
		$forged                      = rtrim( strtr( base64_encode( wp_json_encode( $claims ) ), '+/', '-_' ), '=' ) . '.' . $signature; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test forges the signed transport.
		$this->assertSame( 'invalid', $this->open_link( $forged, true )['status'] );

		// A token signed for a different purpose is not accepted.
		$other_purpose = extrachill_sign( 'events.digest_unsubscribe', $claims['c'], 0 );
		$this->assertSame( 'invalid', $this->open_link( $other_purpose, true )['status'] );

		$this->assertSame( 'invalid', $this->open_link( '', true )['status'] );
		$this->assertSame( 1, $this->row_count( 'fan@example.com' ), 'Rejected links must not remove anyone.' );
	}

	public function test_member_lists_own_artist_subscriptions_by_account_email(): void {
		$member_id   = (int) self::factory()->user->create( array( 'user_email' => 'member@example.com' ) );
		$second_id   = $this->create_artist_profile( 'Kid Lake' );
		$member_row  = $this->subscribe( 'member@example.com' );
		$member_row2 = $this->subscribe( 'member@example.com', $second_id );
		$this->subscribe( 'stranger@example.com' );

		wp_set_current_user( $member_id );
		$result = $this->on_artist_blog(
			static function () {
				return wp_get_ability( 'extrachill/list-my-artist-subscriptions' )->execute( array() );
			}
		);

		$this->assertIsArray( $result );
		$ids = array_column( $result['subscriptions'], 'subscriber_id' );
		sort( $ids );
		$expected = array( $member_row, $member_row2 );
		sort( $expected );
		$this->assertSame( $expected, $ids );
		$this->assertContains( 'Futurebirds', array_column( $result['subscriptions'], 'artist_name' ) );
		$this->assertContains( 'Kid Lake', array_column( $result['subscriptions'], 'artist_name' ) );
	}

	public function test_member_can_remove_only_their_own_subscription(): void {
		$member_id    = (int) self::factory()->user->create( array( 'user_email' => 'member@example.com' ) );
		$member_row   = $this->subscribe( 'member@example.com' );
		$stranger_row = $this->subscribe( 'stranger@example.com' );

		wp_set_current_user( $member_id );
		$denied = $this->on_artist_blog(
			static function () use ( $stranger_row ) {
				return wp_get_ability( 'extrachill/remove-my-artist-subscription' )->execute( array( 'subscriber_id' => $stranger_row ) );
			}
		);
		$this->assertWPError( $denied );
		$this->assertSame( 'artist_subscription_not_found', $denied->get_error_code() );
		$this->assertSame( 1, $this->row_count( 'stranger@example.com' ) );

		$removed = $this->on_artist_blog(
			static function () use ( $member_row ) {
				return wp_get_ability( 'extrachill/remove-my-artist-subscription' )->execute( array( 'subscriber_id' => $member_row ) );
			}
		);
		$this->assertSame(
			array(
				'removed'       => true,
				'subscriber_id' => $member_row,
			),
			$removed
		);
		$this->assertSame( 0, $this->row_count( 'member@example.com' ) );
	}

	public function test_member_abilities_require_sign_in(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( wp_get_ability( 'extrachill/list-my-artist-subscriptions' )->check_permissions( array() ) );
		$this->assertFalse( wp_get_ability( 'extrachill/remove-my-artist-subscription' )->check_permissions( array( 'subscriber_id' => 1 ) ) );
		$this->assertTrue( wp_get_ability( 'extrachill/artist-subscriber-unsubscribe' )->check_permissions( array( 'token' => 'x' ) ), 'The signed link is the authorization.' );
	}
}
