<?php
/**
 * Markdown Export REST API Endpoint
 *
 * Exports published WordPress content as markdown for sharing and AI consumption.
 *
 * @endpoint GET /wp-json/extrachill/v1/tools/markdown-export
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_markdown_export_route' );

/**
 * Registers the markdown-export endpoint.
 */
function extrachill_api_register_markdown_export_route() {
	register_rest_route(
		'extrachill/v1',
		'/tools/markdown-export',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_markdown_export',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => 'extrachill_api_validate_markdown_export_post_id',
					'description'       => 'The post ID to export as markdown.',
				),
				'blog_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => 'Optional multisite blog ID context.',
				),
			),
		)
	);
}

/**
 * Validates post_id parameter.
 *
 * @param int $post_id Post ID.
 * @return bool|WP_Error True if valid, WP_Error otherwise.
 */
function extrachill_api_validate_markdown_export_post_id( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return new WP_Error(
			'invalid_post_id',
			'A valid post_id is required.',
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Builds a markdown header block for a post.
 *
 * @param WP_Post $post Post object.
 * @param array   $meta Extra metadata lines.
 * @return string
 */
function extrachill_api_build_markdown_header( WP_Post $post, array $meta ) {
	$title     = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$date      = get_the_date( 'F j, Y', $post );
	$permalink = get_permalink( $post );

	// Get authors - use Co-Authors Plus if available, fallback to post_author.
	$authors = array();
	if ( function_exists( 'get_coauthors' ) ) {
		$coauthors = get_coauthors( $post->ID );
		foreach ( $coauthors as $coauthor ) {
			$authors[] = $coauthor->display_name;
		}
	}
	if ( empty( $authors ) ) {
		$author_name = get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( $author_name ) {
			$authors[] = $author_name;
		}
	}

	$lines   = array();
	$lines[] = '# ' . $title;
	$lines[] = '';

	if ( ! empty( $authors ) ) {
		$author_label = count( $authors ) > 1 ? 'Authors' : 'Author';
		$lines[]      = '**' . $author_label . ':** ' . implode( ', ', $authors ) . '  ';
	}

	if ( $date ) {
		$lines[] = '**Date:** ' . $date . '  ';
	}

	foreach ( $meta as $meta_line ) {
		$meta_line = trim( (string) $meta_line );
		if ( $meta_line ) {
			$lines[] = $meta_line . '  ';
		}
	}

	$lines[] = '**Source:** ' . $permalink;
	$lines[] = '';
	$lines[] = '---';
	$lines[] = '';

	return implode( "\n", $lines );
}

/**
 * Converts HTML to markdown.
 *
 * @param string $html HTML string.
 * @return string|WP_Error
 */
function extrachill_api_convert_html_to_markdown( $html ) {
	if ( ! class_exists( 'League\\HTMLToMarkdown\\HtmlConverter' ) ) {
		return new WP_Error(
			'library_missing',
			'Markdown conversion library is not available.',
			array( 'status' => 500 )
		);
	}

	$converter = new League\HTMLToMarkdown\HtmlConverter(
		array(
			'strip_tags' => true,
			'hard_break' => true,
		)
	);

	if ( class_exists( 'League\\HTMLToMarkdown\\Converter\\TableConverter' ) ) {
		$converter->getEnvironment()->addConverter( new League\HTMLToMarkdown\Converter\TableConverter() );
	}

	$markdown = trim( (string) $converter->convert( (string) $html ) );
	return $markdown;
}

/**
 * Pre-process HTML to format figcaptions for markdown conversion.
 *
 * Transforms figcaption elements into italic text on a new line
 * that will convert cleanly to markdown.
 *
 * @param string $html HTML content.
 * @return string Processed HTML.
 */
function extrachill_api_preprocess_figcaptions( $html ) {
	$html = preg_replace(
		'/<figcaption[^>]*>(.*?)<\/figcaption>/is',
		'<br><em>$1</em>',
		$html
	);
	return $html;
}

/**
 * Gets markdown for a post and optional associated replies.
 *
 * @param WP_Post $post Post object.
 * @return string|WP_Error
 */
function extrachill_api_build_markdown_body( WP_Post $post ) {
	$html = apply_filters( 'the_content', $post->post_content );
	$html = extrachill_api_preprocess_figcaptions( $html );

	$markdown = extrachill_api_convert_html_to_markdown( $html );
	if ( is_wp_error( $markdown ) ) {
		return $markdown;
	}

	if ( 'topic' === $post->post_type && function_exists( 'bbp_get_topic_post_type' ) ) {
		$replies_md = extrachill_api_build_topic_replies_markdown( $post );
		if ( is_wp_error( $replies_md ) ) {
			return $replies_md;
		}

		if ( $replies_md ) {
			$markdown = trim( $markdown . "\n\n---\n\n## Replies\n\n" . $replies_md );
		}
	}

	if ( 'reply' === $post->post_type && function_exists( 'bbp_get_reply_topic_id' ) ) {
		$topic_id = bbp_get_reply_topic_id( $post->ID );
		if ( $topic_id ) {
			$topic_url = get_permalink( $topic_id );
			if ( $topic_url ) {
				$markdown = trim( '**Topic:** ' . $topic_url . "\n\n" . $markdown );
			}
		}
	}

	return $markdown;
}

/**
 * Builds markdown for all topic replies.
 *
 * @param WP_Post $topic Topic post.
 * @return string|WP_Error
 */
function extrachill_api_build_topic_replies_markdown( WP_Post $topic ) {
	if ( ! function_exists( 'bbp_get_reply_post_type' ) ) {
		return '';
	}

	$reply_ids = get_posts(
		array(
			'post_type'      => bbp_get_reply_post_type(),
			'post_parent'    => $topic->ID,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		)
	);

	if ( ! $reply_ids || ! is_array( $reply_ids ) ) {
		return '';
	}

	$chunks = array();

	foreach ( $reply_ids as $reply_id ) {
		$reply = get_post( (int) $reply_id );
		if ( ! $reply || 'reply' !== $reply->post_type ) {
			continue;
		}

		$author = get_the_author_meta( 'display_name', (int) $reply->post_author );
		$date   = get_the_date( 'F j, Y', $reply );

		$reply_html = apply_filters( 'the_content', $reply->post_content );
		$reply_md   = extrachill_api_convert_html_to_markdown( $reply_html );
		if ( is_wp_error( $reply_md ) ) {
			return $reply_md;
		}

		$heading = '### Reply';
		if ( $author || $date ) {
			$parts = array();
			if ( $author ) {
				$parts[] = $author;
			}
			if ( $date ) {
				$parts[] = $date;
			}
			$heading .= ' by ' . implode( ' — ', $parts );
		}

		$chunks[] = $heading . "\n\n" . trim( (string) $reply_md );
	}

	return implode( "\n\n", array_filter( $chunks ) );
}

/**
 * Gets event metadata lines for markdown header.
 *
 * @param WP_Post $post Post object.
 * @return array
 */
function extrachill_api_get_event_markdown_meta_lines( WP_Post $post ) {
	$meta_lines = array();

	if ( class_exists( '\\DataMachineEvents\\Blocks\\Calendar\\Calendar_Query' ) ) {
		$event_data = \DataMachineEvents\Blocks\Calendar\Calendar_Query::parse_event_data( $post );
		if ( is_array( $event_data ) ) {
			if ( ! empty( $event_data['startDate'] ) ) {
				$date = sanitize_text_field( $event_data['startDate'] );
				$time = ! empty( $event_data['startTime'] ) ? sanitize_text_field( $event_data['startTime'] ) : '';
				$line = '**Date:** ' . $date;
				if ( $time ) {
					$line .= ' at ' . $time;
				}
				$meta_lines[] = $line;
			}

			if ( ! empty( $event_data['venue'] ) ) {
				$meta_lines[] = '**Venue:** ' . sanitize_text_field( $event_data['venue'] );
			}
		}
	}

	if ( empty( $meta_lines ) ) {
		$event_datetime = get_post_meta( $post->ID, '_datamachine_event_datetime', true );
		if ( $event_datetime ) {
			$dt           = new DateTime( $event_datetime, wp_timezone() );
			$meta_lines[] = '**Date:** ' . $dt->format( 'F j, Y g:i A' );
		}

		$venue_terms = get_the_terms( $post->ID, 'venue' );
		if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
			$meta_lines[] = '**Venue:** ' . $venue_terms[0]->name;
		}
	}

	return $meta_lines;
}

/**
 * REST callback: export a post as markdown.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_get_markdown_export( $request ) {
	$post_id = absint( $request->get_param( 'post_id' ) );
	$blog_id = absint( $request->get_param( 'blog_id' ) );

	$original_blog_id = get_current_blog_id();

	$should_switch = is_multisite() && $blog_id && $blog_id !== $original_blog_id;

	if ( $should_switch ) {
		$switched = switch_to_blog( $blog_id );
		if ( ! $switched ) {
			return new WP_Error(
				'invalid_blog_id',
				'Unable to switch to the requested blog.',
				array( 'status' => 400 )
			);
		}
	}

	try {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return new WP_Error(
				'not_found',
				'Post not found.',
				array( 'status' => 404 )
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'not_public',
				'Only published content can be exported.',
				array( 'status' => 403 )
			);
		}

		$meta_lines = array();
		if ( 'datamachine_events' === $post->post_type ) {
			$meta_lines = extrachill_api_get_event_markdown_meta_lines( $post );
		}

		$header = extrachill_api_build_markdown_header( $post, $meta_lines );

		$body = extrachill_api_build_markdown_body( $post );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$markdown = trim( $header . "\n" . $body );

		return rest_ensure_response(
			array(
				'post_id'   => (int) $post_id,
				'blog_id'   => (int) get_current_blog_id(),
				'post_type' => (string) $post->post_type,
				'markdown'  => $markdown,
			)
		);
	} finally {
		if ( $should_switch ) {
			restore_current_blog();
		}
	}
}
