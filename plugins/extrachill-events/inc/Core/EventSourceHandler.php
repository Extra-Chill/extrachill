<?php
/** Source handler resolution shared by event-source admission and flow builders. */
namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

class EventSourceHandler {
	public const FALLBACK_HANDLER = 'universal_web_scraper';
	/** Resolve via the substrate, retaining compatibility with older installations. */
	public static function resolve( string $url ) {
		$gateway = new DataMachineFlowGateway();
		if ( $gateway->is_available( 'datamachine/resolve-source-url' ) ) {
			$result = DataMachineFlowGateway::execute( 'datamachine/resolve-source-url', array( 'url' => $url, 'step_type' => 'event_import' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['handler_slug'] ) ) {
				return array( 'handler_slug' => $result['handler_slug'], 'handler_config' => (array) ( $result['handler_config'] ?? array() ) );
			}
		}
		return array( 'handler_slug' => self::FALLBACK_HANDLER, 'handler_config' => array( 'source_url' => $url, 'search' => '', 'exclude_keywords' => '' ) );
	}

	public static function is_web( array $source ): bool {
		return self::FALLBACK_HANDLER === $source['handler_slug'];
	}

	public static function is_social( array $source ): bool {
		return 'instagram_account' === $source['handler_slug'];
	}

	/** Readability, not event extraction: a readable account need not have two recent shows. */
	public static function preflight( array $source ) {
		if ( ! self::is_social( $source ) ) {
			return new \WP_Error( 'source_preflight_unavailable', 'This source handler has no events admission pre-flight.' );
		}
		$result = DataMachineFlowGateway::execute( 'datamachine-socials/fetch-instagram-account', array_merge( $source['handler_config'], array( 'max_items' => 1 ) ) );
		if ( is_wp_error( $result ) && 'instagram_account_unavailable' === $result->get_error_code() ) {
			return new \WP_Error( 'instagram_account_unavailable', 'Only public Business/Creator accounts can be read.' );
		}
		return $result;
	}

	/** Canonical domain policy; item-shape guidance belongs to the producing handler. */
	public const EVENT_POLICY = 'Create an event only for a specific upcoming public event with live music, a DJ, or comedy and a determinable date. Create each distinct dated show on a calendar. Use the venue named in the source; for a venue account use that venue unless stated otherwise. Title with the headliner or event name, not a caption opening or hashtags. Write 1-3 plain description sentences, including ticket or RSVP links when given, otherwise the source permalink. Call reject_source for recaps, photo dumps, merch, menus, hiring posts, giveaways without a show, memes, undated posts and past events.';
}
