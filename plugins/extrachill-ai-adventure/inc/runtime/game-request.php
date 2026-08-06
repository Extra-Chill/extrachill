<?php
/**
 * Trusted adventure resolution and bounded public request state.
 *
 * @package ExtraChillAIAdventure
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_AI_ADVENTURE_STATE_TTL = 2 * HOUR_IN_SECONDS;

/** Return the complete public gameplay argument schema. */
function extrachill_ai_adventure_rest_args(): array {
	return array(
		'action'        => array(
			'type'     => 'string',
			'required' => true,
			'enum'     => array( 'start', 'introduce', 'play' ),
		),
		'adventure'     => array(
			'type'      => 'string',
			'maxLength' => 512,
		),
		'state'         => array(
			'type'      => 'string',
			'maxLength' => 24576,
		),
		'characterName' => array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 60,
		),
		'playerInput'   => array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 500,
		),
	);
}

/** Validate the action-specific request shape and reject extra context fields. */
function extrachill_ai_adventure_validate_request_shape( $params ) {
	if ( ! is_array( $params ) ) {
		return new WP_Error( 'invalid_game_request', __( 'A JSON object is required.', 'extrachill-ai-adventure' ), array( 'status' => 400 ) );
	}

	$allowed = array( 'action', 'adventure', 'state', 'characterName', 'playerInput' );
	if ( array_diff( array_keys( $params ), $allowed ) ) {
		return new WP_Error( 'invalid_game_request', __( 'The request contains unsupported game context.', 'extrachill-ai-adventure' ), array( 'status' => 400 ) );
	}

	$action   = $params['action'] ?? '';
	$required = array(
		'start'     => array( 'adventure', 'characterName' ),
		'introduce' => array( 'state' ),
		'play'      => array( 'state', 'playerInput' ),
	);
	if ( ! isset( $required[ $action ] ) ) {
		return new WP_Error( 'invalid_game_action', __( 'The requested game action is invalid.', 'extrachill-ai-adventure' ), array( 'status' => 400 ) );
	}

	foreach ( $required[ $action ] as $key ) {
		if ( ! isset( $params[ $key ] ) || ! is_string( $params[ $key ] ) || '' === trim( $params[ $key ] ) ) {
			return new WP_Error( 'invalid_game_request', __( 'The request is missing required game data.', 'extrachill-ai-adventure' ), array( 'status' => 400 ) );
		}
	}

	$permitted = array_merge( array( 'action' ), $required[ $action ] );
	if ( array_diff( array_keys( $params ), $permitted ) ) {
		return new WP_Error( 'invalid_game_request', __( 'The request contains data that is not valid for this action.', 'extrachill-ai-adventure' ), array( 'status' => 400 ) );
	}

	return true;
}

/** Apply the platform's atomic admission primitive to a client and verified game scope. */
function extrachill_ai_adventure_admit_request( WP_REST_Request $request, array $params ) {
	if ( ! function_exists( 'extrachill_api_check_public_write_rate_limit' ) ) {
		return new WP_Error( 'game_admission_unavailable', __( 'Game admission is temporarily unavailable.', 'extrachill-ai-adventure' ), array( 'status' => 503 ) );
	}

	$token = 'start' === $params['action'] ? (string) $params['adventure'] : (string) $params['state'];
	$data  = extrachill_ai_adventure_decode_token( $token, 'start' === $params['action'] ? 'adventure' : 'state' );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$global = extrachill_api_check_public_write_rate_limit( $request, 'ai-adventure', 30 );
	if ( is_wp_error( $global ) ) {
		return $global;
	}

	$scope = ! empty( $data['sid'] ) ? (string) $data['sid'] : (string) ( $data['fp'] ?? '' );
	return extrachill_api_check_public_write_rate_limit( $request, 'ai-adventure-' . substr( hash( 'sha256', $scope ), 0, 16 ), 12 );
}

/** Encode a signed, URL-safe token. */
function extrachill_ai_adventure_encode_token( array $payload ): string {
	$body      = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
	$signature = hash_hmac( 'sha256', 'v1.' . $body, wp_salt( 'auth' ) );
	return 'v1.' . $body . '.' . $signature;
}

/** Decode and authenticate a versioned token. */
function extrachill_ai_adventure_decode_token( string $token, string $kind ) {
	if ( strlen( $token ) > 24576 || 1 !== preg_match( '/^v1\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $token, $matches ) ) {
		return new WP_Error( 'invalid_game_state', __( 'The game state is invalid.', 'extrachill-ai-adventure' ), array( 'status' => 403 ) );
	}

	$expected = hash_hmac( 'sha256', 'v1.' . $matches[1], wp_salt( 'auth' ) );
	if ( ! hash_equals( $expected, $matches[2] ) ) {
		return new WP_Error( 'invalid_game_state', __( 'The game state could not be verified.', 'extrachill-ai-adventure' ), array( 'status' => 403 ) );
	}

	$decoded = base64_decode( strtr( $matches[1], '-_', '+/' ), true );
	$data    = is_string( $decoded ) ? json_decode( $decoded, true, 12 ) : null;
	if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || $kind !== ( $data['kind'] ?? null ) ) {
		return new WP_Error( 'invalid_game_state', __( 'The game state has an unsupported format.', 'extrachill-ai-adventure' ), array( 'status' => 403 ) );
	}

	return $data;
}

/** Build the stable fingerprint used to locate one saved adventure block. */
function extrachill_ai_adventure_fingerprint( array $attributes ): string {
	return hash(
		'sha256',
		wp_json_encode(
			array(
				'title'             => $attributes['title'] ?? '',
				'adventurePrompt'   => $attributes['adventurePrompt'] ?? '',
				'gameMasterPersona' => $attributes['gameMasterPersona'] ?? 'You are a helpful and creative text-based adventure game master.',
				'innerBlocksJSON'   => $attributes['innerBlocksJSON'] ?? '[]',
			)
		)
	);
}

/** Create the signed public reference emitted by the dynamic block. */
function extrachill_ai_adventure_create_reference( int $post_id, array $attributes ): string {
	return extrachill_ai_adventure_encode_token(
		array(
			'v'    => 1,
			'kind' => 'adventure',
			'blog' => (int) get_current_blog_id(),
			'post' => $post_id,
			'fp'   => extrachill_ai_adventure_fingerprint( $attributes ),
		)
	);
}

/** Find an adventure block recursively by its trusted saved-data fingerprint. */
function extrachill_ai_adventure_find_block( array $blocks, string $fingerprint ): ?array {
	foreach ( $blocks as $block ) {
		if ( 'extrachill/ai-adventure' === ( $block['blockName'] ?? '' ) && hash_equals( $fingerprint, extrachill_ai_adventure_fingerprint( $block['attrs'] ?? array() ) ) ) {
			return $block;
		}
		$found = extrachill_ai_adventure_find_block( $block['innerBlocks'] ?? array(), $fingerprint );
		if ( null !== $found ) {
			return $found;
		}
	}
	return null;
}

/** Validate and normalize trusted author-created adventure data. */
function extrachill_ai_adventure_normalize_config( array $attributes ) {
	$lengths = array(
		'title'             => 200,
		'adventurePrompt'   => 4000,
		'gameMasterPersona' => 2000,
	);
	$config  = array();
	foreach ( $lengths as $key => $maximum ) {
		$value = trim( wp_strip_all_tags( (string) ( $attributes[ $key ] ?? '' ) ) );
		if ( strlen( $value ) > $maximum ) {
			return new WP_Error( 'invalid_adventure', __( 'This adventure contains oversized author content.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) );
		}
		$config[ $key ] = $value;
	}

	$inner = json_decode( (string) ( $attributes['innerBlocksJSON'] ?? '[]' ), true, 8 );
	if ( ! is_array( $inner ) || count( $inner ) < 1 || count( $inner ) > 12 ) {
		return new WP_Error( 'invalid_adventure', __( 'This adventure has an invalid path structure.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) );
	}

	$config['steps'] = array();
	foreach ( $inner as $path ) {
		if ( ! is_array( $path ) || 'extrachill/ai-adventure-path' !== ( $path['name'] ?? '' ) ) {
			return new WP_Error( 'invalid_adventure', __( 'This adventure has an invalid path.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) );
		}
		$path_prompt = trim( wp_strip_all_tags( (string) ( $path['attributes']['pathPrompt'] ?? '' ) ) );
		$steps       = $path['innerBlocks'] ?? array();
		if ( strlen( $path_prompt ) > 2000 || ! is_array( $steps ) || count( $steps ) > 32 ) {
			return new WP_Error( 'invalid_adventure', __( 'This adventure has an invalid path structure.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) );
		}

		foreach ( $steps as $step ) {
			$attrs    = is_array( $step ) ? ( $step['attributes'] ?? array() ) : array();
			$step_id  = (string) ( $attrs['stepId'] ?? '' );
			$prompt   = trim( wp_strip_all_tags( (string) ( $attrs['stepPrompt'] ?? '' ) ) );
			$triggers = $attrs['triggers'] ?? array();
			if ( 'extrachill/ai-adventure-step' !== ( $step['name'] ?? '' ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $step_id ) || isset( $config['steps'][ $step_id ] ) || strlen( $prompt ) > 3000 || ! is_array( $triggers ) || count( $triggers ) > 12 ) {
				return new WP_Error( 'invalid_adventure', __( 'This adventure has an invalid step.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) );
			}
			$normalized_triggers = array();
			foreach ( $triggers as $index => $trigger ) {
				$action      = trim( wp_strip_all_tags( (string) ( $trigger['triggerPhrase'] ?? '' ) ) );
				$destination = (string) ( $trigger['destinationStep'] ?? '' );
				if ( strlen( $action ) > 300 || 1 !== preg_match( '/^(?:[A-Za-z0-9_-]{1,64}|end_game)$/', $destination ) ) {
					return new WP_Error( 'invalid_adventure', __( 'This adventure has an invalid trigger.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) );
				}
				$normalized_triggers[] = array(
					'id'          => '' !== $destination ? $destination : 'trigger-' . $index,
					'action'      => $action,
					'destination' => $destination,
				);
			}
			$config['steps'][ $step_id ] = array(
				'path_prompt' => $path_prompt,
				'step_prompt' => $prompt,
				'triggers'    => $normalized_triggers,
			);
			if ( count( $config['steps'] ) > 64 ) {
				return new WP_Error( 'invalid_adventure', __( 'This adventure has too many steps.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) );
			}
		}
	}

	foreach ( $config['steps'] as $step ) {
		foreach ( $step['triggers'] as $trigger ) {
			if ( 'end_game' !== $trigger['destination'] && ! isset( $config['steps'][ $trigger['destination'] ] ) ) {
				return new WP_Error( 'invalid_adventure', __( 'This adventure links to an unknown step.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) );
			}
		}
	}

	$config['first_step'] = (string) array_key_first( $config['steps'] );
	return '' === $config['first_step'] ? new WP_Error( 'invalid_adventure', __( 'This adventure has no playable steps.', 'extrachill-ai-adventure' ), array( 'status' => 422 ) ) : $config;
}

/** Resolve a signed reference against the current published post content. */
function extrachill_ai_adventure_resolve_reference( array $reference ) {
	if ( (int) ( $reference['blog'] ?? 0 ) !== (int) get_current_blog_id() || (int) ( $reference['post'] ?? 0 ) < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) ( $reference['fp'] ?? '' ) ) ) {
		return new WP_Error( 'invalid_adventure_reference', __( 'The adventure reference is invalid.', 'extrachill-ai-adventure' ), array( 'status' => 403 ) );
	}

	$post = get_post( (int) $reference['post'] );
	if ( ! $post || ! is_post_publicly_viewable( $post ) ) {
		return new WP_Error( 'adventure_not_available', __( 'This adventure is not publicly available.', 'extrachill-ai-adventure' ), array( 'status' => 404 ) );
	}

	$block = extrachill_ai_adventure_find_block( parse_blocks( $post->post_content ), (string) $reference['fp'] );
	return null === $block
		? new WP_Error( 'adventure_changed', __( 'This adventure has changed. Please restart the game.', 'extrachill-ai-adventure' ), array( 'status' => 409 ) )
		: extrachill_ai_adventure_normalize_config( $block['attrs'] ?? array() );
}

/** Resolve a bounded request into trusted runtime context. */
function extrachill_ai_adventure_resolve_request( array $params ) {
	$action = (string) $params['action'];
	if ( 'start' === $action ) {
		$reference = extrachill_ai_adventure_decode_token( (string) $params['adventure'], 'adventure' );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		$config = extrachill_ai_adventure_resolve_reference( $reference );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$state = array(
			'v'           => 1,
			'kind'        => 'state',
			'blog'        => (int) $reference['blog'],
			'post'        => (int) $reference['post'],
			'fp'          => (string) $reference['fp'],
			'sid'         => wp_generate_uuid4(),
			'step'        => $config['first_step'],
			'character'   => sanitize_text_field( $params['characterName'] ),
			'history'     => array(),
			'progression' => array(),
			'iat'         => time(),
		);
	} else {
		$state = extrachill_ai_adventure_decode_token( (string) $params['state'], 'state' );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( time() - (int) ( $state['iat'] ?? 0 ) > EXTRACHILL_AI_ADVENTURE_STATE_TTL || time() < (int) ( $state['iat'] ?? 0 ) - 60 ) {
			return new WP_Error( 'expired_game_state', __( 'This game session has expired. Please restart the adventure.', 'extrachill-ai-adventure' ), array( 'status' => 403 ) );
		}
		$config = extrachill_ai_adventure_resolve_reference( $state );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
	}

	if ( ! isset( $config['steps'][ $state['step'] ?? '' ] ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/', (string) ( $state['sid'] ?? '' ) ) || ! is_array( $state['history'] ?? null ) || count( $state['history'] ) > 4 || ! is_array( $state['progression'] ?? null ) || count( $state['progression'] ) > 8 ) {
		return new WP_Error( 'invalid_game_state', __( 'The game state contains invalid progression data.', 'extrachill-ai-adventure' ), array( 'status' => 403 ) );
	}

	$step = $config['steps'][ $state['step'] ];
	return array(
		'action'               => $action,
		'player_input'         => sanitize_text_field( $params['playerInput'] ?? '' ),
		'adventure_title'      => $config['title'],
		'adventure_prompt'     => $config['adventurePrompt'],
		'persona'              => $config['gameMasterPersona'],
		'path_prompt'          => $step['path_prompt'],
		'step_prompt'          => $step['step_prompt'],
		'triggers'             => $step['triggers'],
		'character_name'       => (string) $state['character'],
		'conversation_history' => $state['history'],
		'progression_history'  => $state['progression'],
		'state'                => $state,
		'config'               => $config,
	);
}

/** Advance only server-owned state after a successful provider turn. */
function extrachill_ai_adventure_advance_state( array $game, array $response ): string {
	$state     = $game['state'];
	$narrative = substr( wp_strip_all_tags( (string) ( $response['narrative'] ?? '' ) ), 0, 1500 );
	if ( 'play' === $game['action'] ) {
		$state['history'][] = array( 'type' => 'player', 'content' => substr( $game['player_input'], 0, 500 ) );
	}
	if ( '' !== $narrative ) {
		$state['history'][] = array( 'type' => 'ai', 'content' => $narrative );
	}
	$state['history'] = array_slice( $state['history'], -4 );

	$next_step = (string) ( $response['next_step_id'] ?? '' );
	if ( '' !== $next_step && 'end_game' !== $next_step && isset( $game['config']['steps'][ $next_step ] ) ) {
		$trigger = current( array_filter( $game['triggers'], static fn( $item ) => $next_step === $item['destination'] ) );
		$state['progression'][] = array(
			'stepAction'       => substr( $game['step_prompt'], 0, 300 ),
			'triggerActivated' => substr( (string) ( $trigger['action'] ?? '' ), 0, 200 ),
		);
		$state['progression'] = array_slice( $state['progression'], -8 );
		$state['step']        = $next_step;
	}
	$state['iat'] = time();
	return extrachill_ai_adventure_encode_token( $state );
}
