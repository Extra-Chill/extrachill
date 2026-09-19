<?php
/**
 * The 2FA hand-off must preserve a redirect destination's own query string.
 *
 * add_query_arg() does not encode values, so a redirect_to carrying its own
 * query string was spliced in raw and every '&' in it became a separator of
 * the wp-login.php URL instead. The destination truncated at the first one.
 *
 * Reproduced from production: an OAuth authorization URL survived the login
 * page intact and came back from 2FA as bare /authorize?response_type=code —
 * client_id, redirect_uri, state and the PKCE challenge all stripped —
 * producing "The authorization request is missing a client_id."
 *
 * This affects any 2FA login whose destination has query parameters, not
 * only OAuth.
 */

class Test_Two_Factor_Redirect_Encoding extends WP_UnitTestCase {

	private const AUTHORIZE_URL = 'https://auth.extrachill.com/authorize?response_type=code&client_id=8eq7hQYeJcfLqKmIN8BzhmmqhccsnUTVdLHoWqJ1HXQ&code_challenge=HQbUEjeizV3Uodu9p1aYopPi6jF2cyoS1qaOscgQdf4&code_challenge_method=S256&redirect_uri=http%3A%2F%2F127.0.0.1%3A19876%2Fmcp%2Foauth%2Fcallback&state=724a25730b8ea740044a7bd267ff445bc6eee2cd2dbd8060db4663b2eb0b3fc9&scope=account';

	/**
	 * Build the hand-off URL the same way the service does.
	 *
	 * @param string $redirect_to Destination after 2FA.
	 * @return string
	 */
	private function handoff_url( string $redirect_to ): string {
		return add_query_arg(
			urlencode_deep(
				array(
					'action'        => 'validate_2fa',
					'wp-auth-id'    => 1,
					'wp-auth-nonce' => 'deadbeef',
					'rememberme'    => 1,
					'redirect_to'   => $redirect_to,
				)
			),
			site_url( 'wp-login.php' )
		);
	}

	/**
	 * Extract one query parameter from a URL.
	 */
	private function query_param( string $url, string $key ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		return isset( $params[ $key ] ) ? (string) $params[ $key ] : '';
	}

	public function test_destination_survives_the_handoff_intact(): void {
		$recovered = $this->query_param( $this->handoff_url( self::AUTHORIZE_URL ), 'redirect_to' );

		$this->assertSame(
			self::AUTHORIZE_URL,
			$recovered,
			'the 2FA hand-off truncated the destination at its first ampersand'
		);
	}

	/**
	 * The parameters OAuth cannot complete without.
	 */
	public function test_oauth_parameters_are_still_readable_after_the_handoff(): void {
		$recovered = $this->query_param( $this->handoff_url( self::AUTHORIZE_URL ), 'redirect_to' );

		foreach ( array( 'client_id', 'redirect_uri', 'state', 'code_challenge' ) as $required ) {
			$this->assertNotSame(
				'',
				$this->query_param( $recovered, $required ),
				"{$required} did not survive the 2FA hand-off"
			);
		}
	}

	/**
	 * The destination's parameters must not leak into the wp-login.php URL,
	 * which is how they were lost: they became siblings of wp-auth-nonce.
	 */
	public function test_destination_parameters_do_not_become_login_parameters(): void {
		parse_str( (string) wp_parse_url( $this->handoff_url( self::AUTHORIZE_URL ), PHP_URL_QUERY ), $params );

		$this->assertArrayNotHasKey( 'client_id', $params );
		$this->assertArrayNotHasKey( 'code_challenge', $params );
		$this->assertSame( 'validate_2fa', $params['action'] ?? '' );
		$this->assertSame( 'deadbeef', $params['wp-auth-nonce'] ?? '' );
	}

	/**
	 * A plain destination is unchanged — the fix must not double-encode.
	 */
	public function test_simple_destination_is_unchanged(): void {
		$plain = 'https://community.extrachill.com/';

		$this->assertSame( $plain, $this->query_param( $this->handoff_url( $plain ), 'redirect_to' ) );
	}
}
