<?php
/**
 * Venue partner key CLI transport.
 *
 * @package ExtraChill\CLI\Commands\Events
 */
namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class VenuePartnerKeyCommand {
	/**
	 * Issue, rotate, revoke, or inspect a venue partner credential.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : issue, rotate, revoke, or status.
	 *
	 * <venue>
	 * : Events-site venue term ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues partner-key issue 1524 --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args ) {
		$operation = $args[0] ?? '';
		if ( ! in_array( $operation, array( 'issue', 'rotate', 'revoke', 'status' ), true ) || ! preg_match( '/^[1-9][0-9]*$/D', $args[1] ?? '' ) ) {
			WP_CLI::error( 'Usage: wp extrachill venues partner-key issue|rotate|revoke|status <venue-term-id>' );
		}
		$ability = OwnerSiteAbility::get( 'events', 'Events', 'extrachill/' . $operation . '-venue-partner-key' );
		$result  = $ability->execute( array( 'venue_term_id' => (int) $args[1] ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( isset( $result['key'] ) ) {
			WP_CLI::warning( 'Save this key securely now. It will not be shown again. Do not paste it into logs or tickets.' );
		}
		WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
