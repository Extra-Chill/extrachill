<?php
declare(strict_types=1);
/**
 * Ability: extrachill/trivia-create
 *
 * Deterministic serializer that turns a structured list of trivia questions
 * into valid `extrachill/trivia` block markup and either creates a new post or
 * appends the blocks to an existing one. This ability contains NO question
 * generation or research logic — callers (CLI, REST, agents) supply fully
 * authored questions, and this serializer guarantees the markup matches what
 * the block editor would have produced.
 *
 * Mirrors the trivia block's attribute schema (see
 * src/blocks/trivia/block.json) so serialized blocks hydrate identically to
 * editor-authored ones.
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_trivia_create_ability' );

/**
 * Register the trivia-create ability.
 */
function extrachill_content_blocks_register_trivia_create_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$question_schema = array(
		'type'       => 'object',
		'properties' => array(
			'question'            => array(
				'type'        => 'string',
				'description' => __( 'The trivia question. Basic inline HTML is allowed.', 'extrachill-content-blocks' ),
			),
			'options'             => array(
				'type'        => 'array',
				'description' => __( 'Answer choices (2 or more). Plain text.', 'extrachill-content-blocks' ),
				'items'       => array( 'type' => 'string' ),
				'minItems'    => 2,
			),
			'correctAnswer'       => array(
				'type'        => 'integer',
				'description' => __( 'Zero-based index of the correct option.', 'extrachill-content-blocks' ),
				'minimum'     => 0,
			),
			'answerJustification' => array(
				'type'        => 'string',
				'description' => __( 'Optional explanation shown after answering. Basic inline HTML allowed.', 'extrachill-content-blocks' ),
			),
			'resultMessages'      => array(
				'type'        => 'object',
				'description' => __( 'Optional per-question score-tier messages (excellent/good/okay/poor).', 'extrachill-content-blocks' ),
			),
			'scoreRanges'         => array(
				'type'        => 'object',
				'description' => __( 'Optional per-question score-tier thresholds (excellent/good/okay).', 'extrachill-content-blocks' ),
			),
		),
		'required'   => array( 'question', 'options', 'correctAnswer' ),
	);

	wp_register_ability(
		'extrachill/trivia-create',
		array(
			'label'               => __( 'Create Trivia Quiz', 'extrachill-content-blocks' ),
			'description'         => __( 'Serialize structured trivia questions into extrachill/trivia blocks and create or append them to a post.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'questions'      => array(
						'type'        => 'array',
						'description' => __( 'Ordered list of trivia questions to serialize.', 'extrachill-content-blocks' ),
						'items'       => $question_schema,
						'minItems'    => 1,
					),
					'post_id'        => array(
						'type'        => 'integer',
						'description' => __( 'Existing post to append the trivia blocks to. Omit to create a new post.', 'extrachill-content-blocks' ),
					),
					'post_title'     => array(
						'type'        => 'string',
						'description' => __( 'Title for the new post. Required when post_id is omitted.', 'extrachill-content-blocks' ),
					),
					'post_status'    => array(
						'type'        => 'string',
						'description' => __( 'Status for a newly created post.', 'extrachill-content-blocks' ),
						'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
					),
					'post_type'      => array(
						'type'        => 'string',
						'description' => __( 'Post type for a newly created post.', 'extrachill-content-blocks' ),
					),
					'result_messages' => array(
						'type'        => 'object',
						'description' => __( 'Quiz-wide default score-tier messages applied to every question lacking its own resultMessages.', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'questions' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'         => array(
						'type'        => 'integer',
						'description' => __( 'The post the trivia blocks were written to.', 'extrachill-content-blocks' ),
					),
					'post_url'        => array(
						'type'        => 'string',
						'description' => __( 'Permalink of the post.', 'extrachill-content-blocks' ),
					),
					'edit_url'        => array(
						'type'        => 'string',
						'description' => __( 'Editor URL of the post.', 'extrachill-content-blocks' ),
					),
					'questions_added' => array(
						'type'        => 'integer',
						'description' => __( 'Number of trivia blocks serialized in this call.', 'extrachill-content-blocks' ),
					),
					'created'         => array(
						'type'        => 'boolean',
						'description' => __( 'True when a new post was created, false when appended to an existing one.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => 'extrachill_content_blocks_trivia_create_permission',
			'execute_callback'    => 'extrachill_content_blocks_trivia_create_ability_execute',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => false,
				),
			),
		)
	);
}

/**
 * Permission callback for trivia-create.
 *
 * Writing trivia blocks creates or edits posts, so require edit_posts. When a
 * specific post_id is targeted, require edit capability on that post.
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_content_blocks_trivia_create_permission( array $input = array() ) {
	if ( ! empty( $input['post_id'] ) ) {
		$post_id = absint( $input['post_id'] );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to edit this post.', 'extrachill-content-blocks' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		return new \WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to create posts.', 'extrachill-content-blocks' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Default per-question score-tier messages, matching the block's render-time
 * and editor defaults (src/blocks/trivia/render.php and block.json).
 *
 * @return array<string, string>
 */
function extrachill_content_blocks_trivia_default_result_messages(): array {
	return array(
		'excellent' => '🏆 Trivia Master!',
		'good'      => '🎉 Great Job!',
		'okay'      => '👍 Not Bad!',
		'poor'      => '🤔 Keep Trying!',
	);
}

/**
 * Build a single extrachill/trivia block array from one authored question.
 *
 * The returned structure is suitable for serialize_block(), which emits the
 * canonical `<!-- wp:extrachill/trivia {...} /-->` self-closing comment the
 * dynamic block hydrates from. Only non-default attributes are emitted to keep
 * the markup lean and matching editor output.
 *
 * @param array               $question         One authored question.
 * @param array<string,string>|null $quiz_messages Quiz-wide default resultMessages, or null.
 * @return array|WP_Error Block array or validation error.
 */
function extrachill_content_blocks_trivia_build_block( array $question, ?array $quiz_messages ) {
	$text = isset( $question['question'] ) ? trim( (string) $question['question'] ) : '';
	if ( '' === $text ) {
		return new \WP_Error(
			'invalid_question',
			__( 'Each question must include non-empty question text.', 'extrachill-content-blocks' )
		);
	}

	$options = isset( $question['options'] ) && is_array( $question['options'] )
		? array_values( array_map( 'strval', $question['options'] ) )
		: array();
	$options = array_values( array_filter( $options, static fn( $o ) => '' !== trim( $o ) ) );

	if ( count( $options ) < 2 ) {
		return new \WP_Error(
			'invalid_options',
			sprintf(
				/* translators: %s: question text */
				__( 'Question "%s" must have at least two non-empty options.', 'extrachill-content-blocks' ),
				wp_strip_all_tags( $text )
			)
		);
	}

	$correct = isset( $question['correctAnswer'] ) ? (int) $question['correctAnswer'] : 0;
	if ( $correct < 0 || $correct >= count( $options ) ) {
		return new \WP_Error(
			'invalid_correct_answer',
			sprintf(
				/* translators: 1: correct index, 2: question text, 3: option count */
				__( 'correctAnswer index %1$d is out of range for question "%2$s" (%3$d options).', 'extrachill-content-blocks' ),
				$correct,
				wp_strip_all_tags( $text ),
				count( $options )
			)
		);
	}

	$attrs = array(
		'question'      => wp_kses_post( $text ),
		'options'       => $options,
		'correctAnswer' => $correct,
		'blockId'       => uniqid( 'trivia_', false ),
	);

	$justification = isset( $question['answerJustification'] ) ? trim( (string) $question['answerJustification'] ) : '';
	if ( '' !== $justification ) {
		$attrs['answerJustification'] = wp_kses_post( $justification );
	}

	// Per-question resultMessages take precedence; fall back to quiz-wide
	// messages when provided. When neither is given, omit the attribute so the
	// block falls back to its own defaults at render time.
	$messages = null;
	if ( isset( $question['resultMessages'] ) && is_array( $question['resultMessages'] ) ) {
		$messages = $question['resultMessages'];
	} elseif ( null !== $quiz_messages ) {
		$messages = $quiz_messages;
	}
	if ( is_array( $messages ) && ! empty( $messages ) ) {
		$attrs['resultMessages'] = array_map( 'sanitize_text_field', $messages );
	}

	if ( isset( $question['scoreRanges'] ) && is_array( $question['scoreRanges'] ) && ! empty( $question['scoreRanges'] ) ) {
		$attrs['scoreRanges'] = array_map( 'intval', $question['scoreRanges'] );
	}

	return array(
		'blockName'    => 'extrachill/trivia',
		'attrs'        => $attrs,
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	);
}

/**
 * Execute callback for extrachill/trivia-create.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Result data or error.
 */
function extrachill_content_blocks_trivia_create_ability_execute( array $input ): array|\WP_Error {
	$questions = isset( $input['questions'] ) && is_array( $input['questions'] ) ? $input['questions'] : array();
	if ( empty( $questions ) ) {
		return new \WP_Error(
			'no_questions',
			__( 'At least one question is required.', 'extrachill-content-blocks' )
		);
	}

	$quiz_messages = null;
	if ( isset( $input['result_messages'] ) && is_array( $input['result_messages'] ) && ! empty( $input['result_messages'] ) ) {
		// Merge over the block defaults so a partial override stays complete.
		$quiz_messages = array_merge(
			extrachill_content_blocks_trivia_default_result_messages(),
			array_map( 'sanitize_text_field', $input['result_messages'] )
		);
	}

	// Serialize each authored question into canonical block markup.
	$markup_parts = array();
	foreach ( $questions as $index => $question ) {
		if ( ! is_array( $question ) ) {
			return new \WP_Error(
				'invalid_question',
				sprintf(
					/* translators: %d: question position (1-based) */
					__( 'Question #%d is not a valid object.', 'extrachill-content-blocks' ),
					(int) $index + 1
				)
			);
		}

		$block = extrachill_content_blocks_trivia_build_block( $question, $quiz_messages );
		if ( is_wp_error( $block ) ) {
			return $block;
		}

		$markup_parts[] = serialize_block( $block );
	}

	// Blocks are separated by a blank line, matching the editor's own
	// block-to-block serialization spacing.
	$new_markup = implode( "\n\n", $markup_parts );

	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

	if ( $post_id > 0 ) {
		$existing = get_post( $post_id );
		if ( ! $existing ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Post %d not found.', 'extrachill-content-blocks' ),
					$post_id
				)
			);
		}

		$separator = '' === trim( (string) $existing->post_content ) ? '' : "\n\n";
		$updated   = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $existing->post_content . $separator . $new_markup,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'post_id'         => $post_id,
			'post_url'        => (string) get_permalink( $post_id ),
			'edit_url'        => (string) get_edit_post_link( $post_id, 'raw' ),
			'questions_added' => count( $markup_parts ),
			'created'         => false,
		);
	}

	// Creating a new post requires a title.
	$post_title = isset( $input['post_title'] ) ? sanitize_text_field( $input['post_title'] ) : '';
	if ( '' === $post_title ) {
		return new \WP_Error(
			'missing_post_title',
			__( 'post_title is required when creating a new post (no post_id given).', 'extrachill-content-blocks' )
		);
	}

	$post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';
	if ( ! post_type_exists( $post_type ) ) {
		return new \WP_Error(
			'invalid_post_type',
			sprintf(
				/* translators: %s: post type */
				__( 'Post type "%s" does not exist.', 'extrachill-content-blocks' ),
				$post_type
			)
		);
	}

	$allowed_status = array( 'draft', 'pending', 'publish', 'private' );
	$post_status    = isset( $input['post_status'] ) ? sanitize_key( $input['post_status'] ) : 'draft';
	if ( ! in_array( $post_status, $allowed_status, true ) ) {
		$post_status = 'draft';
	}

	$new_id = wp_insert_post(
		array(
			'post_title'   => $post_title,
			'post_content' => $new_markup,
			'post_status'  => $post_status,
			'post_type'    => $post_type,
		),
		true
	);

	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	return array(
		'post_id'         => (int) $new_id,
		'post_url'        => (string) get_permalink( $new_id ),
		'edit_url'        => (string) get_edit_post_link( $new_id, 'raw' ),
		'questions_added' => count( $markup_parts ),
		'created'         => true,
	);
}
