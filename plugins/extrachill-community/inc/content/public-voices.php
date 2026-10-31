<?php
/**
 * Accountable artist and venue public voices for bbPress content.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a caller-provided canonical reference.
 *
 * @param mixed $reference Candidate reference.
 * @return string Empty string for self/no voice, or a bounded canonical reference.
 */
function extrachill_community_normalize_public_voice_reference( $reference ) {
	$reference = trim( (string) $reference );
	return strlen( $reference ) <= 40 && preg_match( '/^(artist|venue):[1-9][0-9]*$/', $reference ) ? $reference : '';
}

/**
 * Build canonical artist voices the user currently manages.
 *
 * @param int $user_id Accountable network user ID.
 * @return array<string,array<string,mixed>> Voices keyed by canonical reference.
 */
function extrachill_community_get_managed_artist_voices( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id || ! function_exists( 'ec_get_artists_for_user' ) || ! function_exists( 'ec_user_can' ) || ! function_exists( 'ec_get_blog_id' ) ) {
		return array();
	}

	$artist_ids     = array_map( 'absint', (array) ec_get_artists_for_user( $user_id ) );
	$artist_blog_id = absint( ec_get_blog_id( 'artist' ) );
	if ( ! $artist_blog_id || empty( $artist_ids ) ) {
		return array();
	}

	$voices   = array();
	$switched = get_current_blog_id() !== $artist_blog_id;
	if ( $switched ) {
		switch_to_blog( $artist_blog_id );
	}

	try {
		foreach ( $artist_ids as $artist_id ) {
			if ( ! $artist_id || ! ec_user_can(
				'manage_artist',
				array(
					'artist_id' => $artist_id,
					'user_id'   => $user_id,
				)
			) ) {
				continue;
			}

			$post = get_post( $artist_id );
			if ( ! $post instanceof WP_Post || 'artist_profile' !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}

			$reference            = 'artist:' . $artist_id;
			$voices[ $reference ] = array(
				'reference' => $reference,
				'type'      => 'artist',
				'id'        => $artist_id,
				'name'      => sanitize_text_field( $post->post_title ),
				'url'       => esc_url_raw( get_permalink( $post ) ),
			);
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $voices;
}

/**
 * Fetch the Events-owned self-scoped venue projection.
 *
 * No body or user override is sent. The Events ability binds authority to the
 * authenticated execution identity and restores its own multisite context.
 *
 * @return bool Whether to force an HTTP loopback request.
 */
function extrachill_community_venue_voices_use_http_loopback( bool $use_http, string $site_key, string $method, string $path, array $args ): bool {
	unset( $args );

	if ( $use_http || 'events' !== $site_key || 'GET' !== $method || '/wp-abilities/v1/abilities/extrachill/get-managed-venue-voices/run' !== $path ) {
		return $use_http;
	}

	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	return 0 >= $events_blog_id || ! function_exists( 'get_current_blog_id' ) || (int) get_current_blog_id() !== $events_blog_id;
}
add_filter( 'ec_cross_site_use_http_loopback', 'extrachill_community_venue_voices_use_http_loopback', 10, 5 );

function extrachill_community_get_managed_venue_voices() {
	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return new WP_Error( 'managed_venue_voices_unavailable', __( 'Managed venues are temporarily unavailable.', 'extra-chill-community' ) );
	}

	$response = ec_cross_site_rest_request(
		'events',
		'GET',
		'/wp-abilities/v1/abilities/extrachill/get-managed-venue-voices/run'
	);

	if ( is_wp_error( $response ) || ! isset( $response['voices'] ) || ! is_array( $response['voices'] ) ) {
		return is_wp_error( $response )
			? $response
			: new WP_Error( 'managed_venue_voices_invalid', __( 'Managed venues returned an invalid response.', 'extra-chill-community' ) );
	}

	$voices = array();
	foreach ( $response['voices'] as $voice ) {
		if ( ! is_array( $voice ) ) {
			return new WP_Error( 'managed_venue_voices_invalid', __( 'Managed venues returned an invalid voice.', 'extra-chill-community' ) );
		}

		$term_id   = absint( $voice['term_id'] ?? 0 );
		$reference = sanitize_text_field( (string) ( $voice['reference'] ?? '' ) );
		$name      = sanitize_text_field( (string) ( $voice['name'] ?? '' ) );
		$url       = esc_url_raw( (string) ( $voice['url'] ?? '' ) );
		if ( ! $term_id || 'venue:' . $term_id !== $reference || '' === $name || '' === $url ) {
			return new WP_Error( 'managed_venue_voices_invalid', __( 'Managed venues returned an invalid voice.', 'extra-chill-community' ) );
		}

		$voices[ $reference ] = array(
			'reference' => $reference,
			'type'      => 'venue',
			'id'        => $term_id,
			'name'      => $name,
			'url'       => $url,
		);
	}

	return $voices;
}

/**
 * Reauthorize and resolve one requested voice against canonical authority.
 *
 * @param mixed $reference Requested canonical reference.
 * @param int   $user_id   Accountable human user ID.
 * @return array<string,mixed>|WP_Error Canonical public identity or failure.
 */
function extrachill_community_authorize_public_voice( $reference, $user_id ) {
	$reference = extrachill_community_normalize_public_voice_reference( $reference );
	$user_id   = absint( $user_id );

	if ( '' === $reference ) {
		return new WP_Error( 'invalid_public_voice', __( 'Choose a valid managed artist or venue.', 'extra-chill-community' ) );
	}
	if ( ! $user_id ) {
		return new WP_Error( 'public_voice_authentication_required', __( 'An accountable user is required.', 'extra-chill-community' ) );
	}

	if ( 0 === strpos( $reference, 'artist:' ) ) {
		$voices = extrachill_community_get_managed_artist_voices( $user_id );
	} else {
		if ( (int) get_current_user_id() !== $user_id ) {
			return new WP_Error( 'public_voice_author_mismatch', __( 'A venue voice can only be changed by its accountable author.', 'extra-chill-community' ) );
		}
		$voices = extrachill_community_get_managed_venue_voices();
		if ( is_wp_error( $voices ) ) {
			return $voices;
		}
	}

	if ( ! isset( $voices[ $reference ] ) ) {
		return new WP_Error( 'public_voice_not_managed', __( 'You no longer manage that public voice.', 'extra-chill-community' ) );
	}

	return $voices[ $reference ];
}

/**
 * Prepare a create/update voice change before the content write occurs.
 *
 * A null result preserves metadata, an empty string clears it, and a canonical
 * reference replaces it. Moderators may preserve another human's identity but
 * cannot adopt or change it. Reassigning post_author always clears the voice.
 *
 * @param array        $input          Ability input.
 * @param int          $author_id      Current accountable author.
 * @param int          $actor_id       Trusted execution actor.
 * @param int          $post_id        Existing topic/reply ID, or zero on create.
 * @param int          $new_author_id  Resulting author ID after the write.
 * @return string|null|WP_Error Prepared metadata change or failure.
 */
function extrachill_community_prepare_public_voice_change( $input, $author_id, $actor_id, $post_id = 0, $new_author_id = 0 ) {
	$author_id     = absint( $author_id );
	$actor_id      = absint( $actor_id );
	$post_id       = absint( $post_id );
	$new_author_id = $new_author_id ? absint( $new_author_id ) : $author_id;
	$existing      = $post_id ? (string) get_post_meta( $post_id, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META, true ) : '';

	if ( $post_id && $new_author_id !== $author_id ) {
		return '';
	}

	$has_input = array_key_exists( 'public_voice', $input );
	$requested = $has_input ? trim( (string) $input['public_voice'] ) : $existing;

	if ( $post_id && $actor_id !== $author_id ) {
		if ( $has_input && $requested !== $existing ) {
			return new WP_Error( 'public_voice_author_mismatch', __( 'Only the accountable author can change the public voice.', 'extra-chill-community' ) );
		}
		return null;
	}

	if ( '' === $requested ) {
		return $post_id || $has_input ? '' : null;
	}

	$voice = extrachill_community_authorize_public_voice( $requested, $new_author_id );
	if ( is_wp_error( $voice ) ) {
		if ( $post_id && ! $has_input ) {
			// Only a successful canonical projection can prove revocation. Transport,
			// dependency, and malformed-response failures preserve existing metadata.
			return 'public_voice_not_managed' === $voice->get_error_code() ? '' : null;
		}
		return $voice;
	}

	return $voice['reference'];
}

/**
 * Resolve a stored reference from canonical public entity data for rendering.
 *
 * @param string $reference Stored canonical reference.
 * @return array<string,mixed>|null Public identity, or null when unavailable.
 */
function extrachill_community_resolve_public_voice( $reference ) {
	static $resolved = array();

	$reference = extrachill_community_normalize_public_voice_reference( $reference );
	if ( '' === $reference ) {
		return null;
	}
	if ( array_key_exists( $reference, $resolved ) ) {
		return $resolved[ $reference ];
	}

	list( $type, $raw_id ) = explode( ':', $reference, 2 );
	$id                    = absint( $raw_id );
	$identity              = null;

	if ( 'artist' === $type && function_exists( 'ec_get_blog_id' ) ) {
		$artist_blog_id = absint( ec_get_blog_id( 'artist' ) );
		$switched       = $artist_blog_id && get_current_blog_id() !== $artist_blog_id;
		if ( $switched ) {
			switch_to_blog( $artist_blog_id );
		}
		try {
			$post = $artist_blog_id ? get_post( $id ) : null;
			if ( $post instanceof WP_Post && 'artist_profile' === $post->post_type && 'publish' === $post->post_status ) {
				$avatar_url = function_exists( 'get_the_post_thumbnail_url' ) ? get_the_post_thumbnail_url( $post, 'thumbnail' ) : '';
				$identity   = array(
					'reference'  => $reference,
					'type'       => 'artist',
					'id'         => $id,
					'name'       => sanitize_text_field( $post->post_title ),
					'url'        => esc_url_raw( get_permalink( $post ) ),
					'avatar_url' => esc_url_raw( (string) $avatar_url ),
				);
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	} elseif ( 'venue' === $type && function_exists( 'ec_cross_site_rest_request' ) ) {
		$response = ec_cross_site_rest_request( 'events', 'GET', '/wp/v2/venue/' . $id );
		if ( ! is_wp_error( $response ) && absint( $response['id'] ?? 0 ) === $id ) {
			$name       = is_array( $response['name'] ?? null ) ? ( $response['name']['rendered'] ?? '' ) : ( $response['name'] ?? '' );
			$url        = esc_url_raw( (string) ( $response['link'] ?? '' ) );
			$avatar_url = esc_url_raw( (string) ( $response['avatar_url'] ?? '' ) );
			if ( '' !== sanitize_text_field( wp_strip_all_tags( (string) $name ) ) && '' !== $url ) {
				$identity = array(
					'reference'  => $reference,
					'type'       => 'venue',
					'id'         => $id,
					'name'       => sanitize_text_field( wp_strip_all_tags( (string) $name ) ),
					'url'        => $url,
					'avatar_url' => $avatar_url,
				);
			}
		}
	}

	$resolved[ $reference ] = $identity;
	return $identity;
}

/**
 * Return canonical public voice data stored for a topic or reply.
 *
 * @param int $post_id Topic or reply ID.
 * @return array<string,mixed>|null Public identity.
 */
function extrachill_community_get_post_public_voice( $post_id ) {
	$reference = (string) get_post_meta( absint( $post_id ), EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META, true );
	return extrachill_community_resolve_public_voice( $reference );
}

/**
 * Return a bounded voice/disclosure envelope for ability formatters.
 *
 * @param int $post_id Topic or reply ID.
 * @return array<string,mixed>|null Public voice envelope.
 */
function extrachill_community_format_post_public_voice( $post_id ) {
	$identity = extrachill_community_get_post_public_voice( $post_id );
	if ( ! $identity ) {
		return null;
	}

	$author_id = absint( get_post_field( 'post_author', $post_id ) );
	return array(
		'reference'           => $identity['reference'],
		'type'                => $identity['type'],
		'id'                  => $identity['id'],
		'name'                => $identity['name'],
		'url'                 => $identity['url'],
		'accountable_user_id' => $author_id,
		'automated'           => '' !== sanitize_key( (string) get_post_meta( $post_id, EXTRACHILL_COMMUNITY_AUTOMATED_META, true ) ),
	);
}

/**
 * Capture only a bounded effective agent slug from a trusted principal.
 *
 * @return string Agent slug, or empty string for an ordinary user execution.
 */
function extrachill_community_get_automated_agent_disclosure() {
	if ( ! class_exists( 'WP_Agent_Access' ) || ! class_exists( 'AgentsAPI\\AI\\WP_Agent_Execution_Principal' ) ) {
		return '';
	}

	try {
		$principal = WP_Agent_Access::get_current_principal( array( 'allow_anonymous_audience' => false ) );
	} catch ( Throwable $exception ) {
		return '';
	}

	if ( ! $principal instanceof AgentsAPI\AI\WP_Agent_Execution_Principal || '__wordpress_user__' === $principal->effective_agent_id ) {
		return '';
	}

	$agent = sanitize_key( (string) $principal->effective_agent_id );
	return strlen( $agent ) <= 100 ? $agent : '';
}

/**
 * Persist a validated voice change and bounded automation disclosure.
 *
 * @param int         $post_id   Topic or reply ID.
 * @param string|null $reference Null preserves the current voice; empty clears it.
 */
function extrachill_community_persist_public_voice( $post_id, $reference = null ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return;
	}

	if ( null !== $reference ) {
		$reference = extrachill_community_normalize_public_voice_reference( $reference );
		if ( '' === $reference ) {
			delete_post_meta( $post_id, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META );
		} else {
			update_post_meta( $post_id, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META, $reference );
		}
	}

	$agent = extrachill_community_get_automated_agent_disclosure();
	if ( '' !== $agent ) {
		update_post_meta( $post_id, EXTRACHILL_COMMUNITY_AUTOMATED_META, $agent );
	}
}

/**
 * Validate native bbPress form input before a topic/reply is saved.
 *
 * @param int $post_id Existing post ID for edits, or zero for creates.
 */
function extrachill_community_validate_native_public_voice( $post_id = 0 ) {
	// bbPress verifies its native topic/reply nonce before firing pre_extras.
	if ( ! array_key_exists( 'bbp_public_voice', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}

	$requested           = trim( (string) wp_unslash( $_POST['bbp_public_voice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$existing            = $post_id ? (string) get_post_meta( $post_id, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META, true ) : '';
	$author_id           = $post_id ? absint( get_post_field( 'post_author', $post_id ) ) : (int) get_current_user_id();
	$preserve_unverified = ! empty( $_POST['bbp_public_voice_preserve'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		&& $requested === $existing;

	if ( $post_id && (int) get_current_user_id() !== $author_id ) {
		if ( $requested !== $existing ) {
			bbp_add_error( 'bbp_public_voice_author_mismatch', __( '<strong>Error</strong>: Only the accountable author can change the public voice.', 'extra-chill-community' ) );
		}
		return;
	}

	if ( '' !== $requested ) {
		$voice = extrachill_community_authorize_public_voice( $requested, $author_id );
		if ( is_wp_error( $voice ) ) {
			if ( $preserve_unverified && 'public_voice_not_managed' !== $voice->get_error_code() ) {
				$GLOBALS['extrachill_community_pending_public_voice'] = null;
				return;
			}
			bbp_add_error( 'bbp_public_voice_not_managed', '<strong>' . esc_html__( 'Error:', 'extra-chill-community' ) . '</strong> ' . esc_html( $voice->get_error_message() ) );
			return;
		}
		$requested = $voice['reference'];
	}

	$GLOBALS['extrachill_community_pending_public_voice'] = $requested;
}

/** Validate native topic creation voice input. */
function extrachill_community_validate_new_topic_public_voice() {
	extrachill_community_validate_native_public_voice();
}

/** Validate native reply creation voice input. */
function extrachill_community_validate_new_reply_public_voice() {
	extrachill_community_validate_native_public_voice();
}

/** Validate native topic edit voice input. */
function extrachill_community_validate_edit_topic_public_voice( $topic_id ) {
	extrachill_community_validate_native_public_voice( $topic_id );
}

/** Validate native reply edit voice input. */
function extrachill_community_validate_edit_reply_public_voice( $reply_id ) {
	extrachill_community_validate_native_public_voice( $reply_id );
}

/** Persist the pending native form voice after bbPress successfully saves. */
function extrachill_community_save_native_public_voice( $post_id ) {
	if ( ! array_key_exists( 'extrachill_community_pending_public_voice', $GLOBALS ) ) {
		return;
	}

	$reference = $GLOBALS['extrachill_community_pending_public_voice'];
	unset( $GLOBALS['extrachill_community_pending_public_voice'] );
	extrachill_community_persist_public_voice( $post_id, $reference );
}

add_action( 'bbp_new_topic_pre_extras', 'extrachill_community_validate_new_topic_public_voice' );
add_action( 'bbp_new_reply_pre_extras', 'extrachill_community_validate_new_reply_public_voice' );
add_action( 'bbp_edit_topic_pre_extras', 'extrachill_community_validate_edit_topic_public_voice' );
add_action( 'bbp_edit_reply_pre_extras', 'extrachill_community_validate_edit_reply_public_voice' );
add_action( 'bbp_new_topic', 'extrachill_community_save_native_public_voice', 5 );
add_action( 'bbp_new_reply', 'extrachill_community_save_native_public_voice', 5 );
add_action( 'bbp_edit_topic', 'extrachill_community_save_native_public_voice', 5 );
add_action( 'bbp_edit_reply', 'extrachill_community_save_native_public_voice', 5 );

/**
 * Render an accessible self/managed-voice selector in native bbPress forms.
 *
 * @param string $type Topic or reply.
 */
function extrachill_community_render_public_voice_selector( $type ) {
	$user_id = (int) get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	$post_id   = 'topic' === $type && function_exists( 'bbp_is_topic_edit' ) && bbp_is_topic_edit()
		? absint( bbp_get_topic_id() )
		: ( 'reply' === $type && function_exists( 'bbp_is_reply_edit' ) && bbp_is_reply_edit() ? absint( bbp_get_reply_id() ) : 0 );
	$author_id = $post_id ? absint( get_post_field( 'post_author', $post_id ) ) : $user_id;
	$current   = $post_id ? (string) get_post_meta( $post_id, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META, true ) : '';

	if ( $post_id && $author_id !== $user_id ) {
		$identity = extrachill_community_resolve_public_voice( $current );
		if ( $identity ) {
			printf(
				'<p class="bbp-public-voice-field"><span class="bbp-public-voice-label">%1$s</span> <a href="%2$s">%3$s</a><input type="hidden" name="bbp_public_voice" value="%4$s"></p>',
				esc_html__( 'Publishing as:', 'extra-chill-community' ),
				esc_url( $identity['url'] ),
				esc_html( $identity['name'] ),
				esc_attr( $current )
			);
		}
		return;
	}

	$artists             = extrachill_community_get_managed_artist_voices( $user_id );
	$venues              = extrachill_community_get_managed_venue_voices();
	$preserve_unverified = $post_id && 0 === strpos( $current, 'venue:' ) && is_wp_error( $venues );
	$current_identity    = $preserve_unverified ? extrachill_community_resolve_public_voice( $current ) : null;
	?>
	<div class="bbp-public-voice-field">
		<label for="bbp_public_voice"><?php esc_html_e( 'Publish as', 'extra-chill-community' ); ?></label>
		<?php if ( $preserve_unverified ) : ?>
			<input type="hidden" name="bbp_public_voice" value="<?php echo esc_attr( $current ); ?>">
			<input type="hidden" name="bbp_public_voice_preserve" value="1">
		<?php endif; ?>
		<select id="bbp_public_voice" name="<?php echo $preserve_unverified ? '' : 'bbp_public_voice'; ?>" aria-describedby="bbp-public-voice-help bbp-public-voice-status" <?php disabled( $preserve_unverified ); ?>>
			<?php if ( $preserve_unverified ) : ?>
				<option selected><?php echo esc_html( $current_identity ? $current_identity['name'] : $current ); ?></option>
			<?php endif; ?>
			<option value=""><?php esc_html_e( 'Myself', 'extra-chill-community' ); ?></option>
			<?php if ( ! empty( $artists ) ) : ?>
				<optgroup label="<?php esc_attr_e( 'Artists you manage', 'extra-chill-community' ); ?>">
					<?php foreach ( $artists as $voice ) : ?>
						<option value="<?php echo esc_attr( $voice['reference'] ); ?>" <?php selected( $current, $voice['reference'] ); ?>><?php echo esc_html( $voice['name'] ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
			<?php if ( ! is_wp_error( $venues ) && ! empty( $venues ) ) : ?>
				<optgroup label="<?php esc_attr_e( 'Venues you manage', 'extra-chill-community' ); ?>">
					<?php foreach ( $venues as $voice ) : ?>
						<option value="<?php echo esc_attr( $voice['reference'] ); ?>" <?php selected( $current, $voice['reference'] ); ?>><?php echo esc_html( $voice['name'] ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
		</select>
		<p id="bbp-public-voice-help" class="description"><?php esc_html_e( 'Your account remains responsible for this post. Only public entity identity is shown.', 'extra-chill-community' ); ?></p>
		<p id="bbp-public-voice-status" class="bbp-public-voice-status" role="status" aria-live="polite" aria-busy="false">
			<?php if ( $preserve_unverified ) : ?>
				<?php esc_html_e( 'The current venue voice could not be reauthorized. It will be preserved; try again later to change it.', 'extra-chill-community' ); ?>
			<?php elseif ( is_wp_error( $venues ) ) : ?>
				<?php esc_html_e( 'Managed venues could not be loaded. Artist and personal publishing remain available.', 'extra-chill-community' ); ?>
			<?php elseif ( empty( $venues ) ) : ?>
				<?php esc_html_e( 'No managed venue voices are available.', 'extra-chill-community' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Managed venue voices loaded.', 'extra-chill-community' ); ?>
			<?php endif; ?>
		</p>
		<?php if ( ! $preserve_unverified && '' !== $current && ! isset( $artists[ $current ] ) && ( is_wp_error( $venues ) || ! isset( $venues[ $current ] ) ) ) : ?>
			<p class="bbp-public-voice-warning" role="alert"><?php esc_html_e( 'The previous public voice is no longer authorized and will be cleared when you save.', 'extra-chill-community' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/** Render the topic composer voice selector. */
function extrachill_community_render_topic_public_voice_selector() {
	extrachill_community_render_public_voice_selector( 'topic' );
}

/** Render the reply composer voice selector. */
function extrachill_community_render_reply_public_voice_selector() {
	extrachill_community_render_public_voice_selector( 'reply' );
}

add_action( 'bbp_theme_after_topic_form_content', 'extrachill_community_render_topic_public_voice_selector', 20 );
add_action( 'bbp_theme_after_reply_form_content', 'extrachill_community_render_reply_public_voice_selector', 20 );

/** Enqueue the focused voice UI styles anywhere Community cards or forms render. */
function extrachill_community_enqueue_public_voice_styles() {
	$is_bbpress = function_exists( 'is_bbpress' ) && is_bbpress();
	if ( $is_bbpress || is_front_page() || is_home() || is_page( 'recent' ) ) {
		$path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/public-voices.css';
		wp_enqueue_style(
			'extrachill-community-public-voices',
			EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/public-voices.css',
			array( 'extrachill-bbpress' ),
			(string) filemtime( $path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_community_enqueue_public_voice_styles', 20 );

/**
 * Render canonical identity with accountable-human and automation disclosure.
 *
 * @param int  $post_id Topic or reply ID.
 * @param bool $compact Whether to use compact freshness-card markup.
 * @return bool Whether a public voice was rendered.
 */
function extrachill_community_render_post_public_voice( $post_id, $compact = false ) {
	$post_id  = absint( $post_id );
	$identity = extrachill_community_get_post_public_voice( $post_id );
	if ( ! $identity ) {
		return false;
	}

	$author_id   = absint( get_post_field( 'post_author', $post_id ) );
	$author      = get_userdata( $author_id );
	$author_name = $author ? $author->display_name : __( 'Community member', 'extra-chill-community' );
	$author_url  = function_exists( 'bbp_get_user_profile_url' ) ? bbp_get_user_profile_url( $author_id ) : get_author_posts_url( $author_id );
	$agent       = sanitize_key( (string) get_post_meta( $post_id, EXTRACHILL_COMMUNITY_AUTOMATED_META, true ) );
	$type_label  = 'artist' === $identity['type'] ? __( 'Artist', 'extra-chill-community' ) : __( 'Venue', 'extra-chill-community' );

	printf(
		'<span class="bbp-public-voice%1$s"><a class="bbp-public-voice-name" href="%2$s">%3$s</a><span class="bbp-public-voice-type">%4$s</span>',
		$compact ? ' is-compact' : '',
		esc_url( $identity['url'] ),
		esc_html( $identity['name'] ),
		esc_html( $type_label )
	);

	if ( ! $compact ) {
		printf(
			'<span class="bbp-public-voice-accountability">%1$s <a href="%2$s">%3$s</a></span>',
			esc_html__( 'Posted by', 'extra-chill-community' ),
			esc_url( $author_url ),
			esc_html( $author_name )
		);
	} else {
		printf( '<span class="screen-reader-text">%1$s %2$s</span>', esc_html__( 'Posted by', 'extra-chill-community' ), esc_html( $author_name ) );
	}

	if ( '' !== $agent ) {
		echo '<span class="bbp-public-voice-automated">' . esc_html__( 'Automated contribution', 'extra-chill-community' ) . '</span>';
	}

	echo '</span>';
	return true;
}

/** Filter a bbPress author display name to the canonical public voice name. */
function extrachill_community_filter_public_voice_author_name( $name, $post_id ) {
	if ( empty( $GLOBALS['extrachill_community_public_voice_render_depth'] ) ) {
		return $name;
	}
	$identity = extrachill_community_get_post_public_voice( $post_id );
	return $identity ? $identity['name'] : $name;
}

/** Filter a bbPress author profile URL to the canonical entity URL. */
function extrachill_community_filter_public_voice_author_url( $url, $post_id ) {
	if ( empty( $GLOBALS['extrachill_community_public_voice_render_depth'] ) ) {
		return $url;
	}
	$identity = extrachill_community_get_post_public_voice( $post_id );
	return $identity ? $identity['url'] : $url;
}

/**
 * Keep accountable-human disclosure available in compact bbPress author links.
 *
 * @param string $link Rendered author link.
 * @param array  $args Parsed bbPress author-link arguments.
 * @return string Filtered author link.
 */
function extrachill_community_filter_public_voice_author_link( $link, $args ) {
	if ( empty( $GLOBALS['extrachill_community_public_voice_render_depth'] ) ) {
		return $link;
	}
	$post_id  = absint( $args['post_id'] ?? 0 );
	$identity = extrachill_community_get_post_public_voice( $post_id );
	if ( ! $identity ) {
		return $link;
	}

	$author_id   = absint( get_post_field( 'post_author', $post_id ) );
	$author      = get_userdata( $author_id );
	$author_name = $author ? $author->display_name : __( 'Community member', 'extra-chill-community' );
	return $link . sprintf(
		'<span class="screen-reader-text"> %1$s %2$s</span>',
		esc_html__( 'Posted by', 'extra-chill-community' ),
		esc_html( $author_name )
	);
}

/** Begin a nested public frontend identity-rendering scope. */
function extrachill_community_begin_public_voice_render() {
	$depth = absint( $GLOBALS['extrachill_community_public_voice_render_depth'] ?? 0 );
	$GLOBALS['extrachill_community_public_voice_render_depth'] = $depth + 1;
}

/** End a nested public frontend identity-rendering scope. */
function extrachill_community_end_public_voice_render() {
	$depth = absint( $GLOBALS['extrachill_community_public_voice_render_depth'] ?? 0 );
	if ( $depth <= 1 ) {
		unset( $GLOBALS['extrachill_community_public_voice_render_depth'] );
		return;
	}
	$GLOBALS['extrachill_community_public_voice_render_depth'] = $depth - 1;
}

/** Render visible accountability under a topic's canonical public identity. */
function extrachill_community_render_topic_public_voice_disclosure() {
	if ( function_exists( 'bbp_get_topic_id' ) ) {
		extrachill_community_render_public_voice_disclosure( bbp_get_topic_id() );
	}
}

/** Render visible accountability under a reply/activity canonical identity. */
function extrachill_community_render_reply_public_voice_disclosure() {
	if ( function_exists( 'bbp_get_reply_id' ) ) {
		extrachill_community_render_public_voice_disclosure( bbp_get_reply_id() );
	}
}

/** Render type, accountable human, and automation disclosure without identity duplication. */
function extrachill_community_render_public_voice_disclosure( $post_id ) {
	$identity = extrachill_community_get_post_public_voice( $post_id );
	if ( ! $identity ) {
		return;
	}

	$author_id   = absint( get_post_field( 'post_author', $post_id ) );
	$author      = get_userdata( $author_id );
	$author_name = $author ? $author->display_name : __( 'Community member', 'extra-chill-community' );
	$author_url  = function_exists( 'bbp_get_user_profile_url' ) ? bbp_get_user_profile_url( $author_id ) : get_author_posts_url( $author_id );
	$type_label  = 'artist' === $identity['type'] ? __( 'Artist', 'extra-chill-community' ) : __( 'Venue', 'extra-chill-community' );
	$agent       = sanitize_key( (string) get_post_meta( $post_id, EXTRACHILL_COMMUNITY_AUTOMATED_META, true ) );

	printf( '<span class="bbp-public-voice-type">%s</span>', esc_html( $type_label ) );
	printf(
		'<span class="bbp-public-voice-accountability">%1$s <a href="%2$s">%3$s</a></span>',
		esc_html__( 'Posted by', 'extra-chill-community' ),
		esc_url( $author_url ),
		esc_html( $author_name )
	);
	if ( '' !== $agent ) {
		echo '<span class="bbp-public-voice-automated">' . esc_html__( 'Automated contribution', 'extra-chill-community' ) . '</span>';
	}
}

add_filter( 'bbp_get_topic_author_display_name', 'extrachill_community_filter_public_voice_author_name', 4, 2 );
add_filter( 'bbp_get_reply_author_display_name', 'extrachill_community_filter_public_voice_author_name', 4, 2 );
add_filter( 'bbp_get_topic_author_url', 'extrachill_community_filter_public_voice_author_url', 10, 2 );
add_filter( 'bbp_get_reply_author_url', 'extrachill_community_filter_public_voice_author_url', 10, 2 );
add_filter( 'bbp_get_topic_author_link', 'extrachill_community_filter_public_voice_author_link', 20, 2 );
add_filter( 'bbp_get_reply_author_link', 'extrachill_community_filter_public_voice_author_link', 20, 2 );
add_action( 'bbp_theme_before_topic_author_details', 'extrachill_community_begin_public_voice_render', 1 );
add_action( 'bbp_theme_after_topic_author_details', 'extrachill_community_render_topic_public_voice_disclosure', 20 );
add_action( 'bbp_theme_after_topic_author_details', 'extrachill_community_end_public_voice_render', 99 );
add_action( 'bbp_template_before_reply_content', 'extrachill_community_begin_public_voice_render', 1 );
add_action( 'bbp_theme_after_reply_author_details', 'extrachill_community_render_reply_public_voice_disclosure', 20 );
add_action( 'bbp_template_after_reply_content', 'extrachill_community_end_public_voice_render', 99 );
add_action( 'bbp_theme_before_topic_author', 'extrachill_community_begin_public_voice_render', 1 );
add_action( 'bbp_theme_after_topic_author', 'extrachill_community_end_public_voice_render', 99 );
