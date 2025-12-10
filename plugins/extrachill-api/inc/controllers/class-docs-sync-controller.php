<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExtraChill_Docs_Sync_Controller {

	/**
	 * Syncs a documentation post.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response object.
	 */
	public static function sync_doc( $request ) {
		$docs_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'docs' ) : null;
		if ( ! $docs_blog_id || get_current_blog_id() !== $docs_blog_id ) {
			return new WP_Error(
				'invalid_site',
				'Documentation sync is only allowed on the docs site.',
				[ 'status' => 400 ]
			);
		}

		if ( ! post_type_exists( 'ec_doc' ) ) {
			return new WP_Error(
				'missing_post_type',
				'The ec_doc post type is not registered on this site.',
				[ 'status' => 500 ]
			);
		}

		$params        = $request->get_params();
		$source_file   = $params['source_file'];
		$title         = $params['title'];
		$content       = $params['content'];
		$platform_slug = $params['platform_slug'];
		$filesize      = $params['filesize'];
		$timestamp     = $params['timestamp'];
		$force         = $params['force'];
		$slug          = $params['slug'];
		$excerpt       = $params['excerpt'];

		// Convert Markdown to HTML before storing.
		require_once EXTRACHILL_API_PATH . 'vendor/autoload.php';
		$parser       = new Parsedown();
		$html_content = $parser->text( $content );

		// Add IDs to headers for TOC anchor linking.
		$html_content = self::add_header_ids( $html_content );

		// 2. Calculate hash to detect changes.
		$hash = hash( 'sha256', $content . $title . $platform_slug . $excerpt );

		// 3. Find existing post by source_file meta.
		$existing_post = self::get_post_by_source_file( $source_file );

		if ( $existing_post ) {
			// Check if update is needed.
			$stored_hash = get_post_meta( $existing_post->ID, '_sync_hash', true );
			if ( ! $force && $stored_hash === $hash ) {
				return new WP_REST_Response( [
					'success' => true,
					'action'  => 'skipped',
					'id'      => $existing_post->ID,
				] );
			}
			$post_id = $existing_post->ID;
			$action  = 'updated';
		} else {
			$post_id = 0;
			$action  = 'created';
		}

		// 4. Handle Taxonomy (Platform).
		$term_id = self::ensure_platform_term( $platform_slug );
		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}

		// 5. Insert/Update Post.
		$post_data = [
			'ID'           => $post_id,
			'post_title'   => $title,
			'post_content' => $html_content,
			'post_excerpt' => $excerpt,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'ec_doc',
			'meta_input'   => [
				'_source_file'    => $source_file,
				'_sync_hash'      => $hash,
				'_sync_timestamp' => $timestamp,
				'_sync_filesize'  => $filesize,
			],
		];

		$id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// 6. Set Terms.
		wp_set_object_terms( $id, [ $term_id ], 'ec_doc_platform' );

		return new WP_REST_Response( [
			'success' => true,
			'action'  => $action,
			'id'      => $id,
		] );
	}

	/**
	 * Helper to find post by source file meta.
	 */
	private static function get_post_by_source_file( $source_file ) {
		$args = [
			'post_type'   => 'ec_doc',
			'post_status' => 'any',
			'meta_query'  => [
				[
					'key'   => '_source_file',
					'value' => $source_file,
				],
			],
			'posts_per_page' => 1,
		];
		$query = new WP_Query( $args );
		return $query->have_posts() ? $query->posts[0] : null;
	}

	/**
	 * Add IDs to headers for TOC anchor linking.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with IDs added to headers.
	 */
	private static function add_header_ids( $html ) {
		$used_ids = [];

		return preg_replace_callback(
			'/<(h2)([^>]*)>(.*?)<\/h2>/i',
			function( $matches ) use ( &$used_ids ) {
				$attrs = $matches[2];
				$text  = $matches[3];

				// Skip if already has an id.
				if ( preg_match( '/id=["\']/', $attrs ) ) {
					return $matches[0];
				}

				// Generate slug from text (strip tags first).
				$slug    = sanitize_title( wp_strip_all_tags( $text ) );
				$base_id = 'toc-' . $slug;
				$id      = $base_id;

				// Handle duplicates.
				$counter = 2;
				while ( in_array( $id, $used_ids, true ) ) {
					$id = $base_id . '-' . $counter;
					$counter++;
				}
				$used_ids[] = $id;

				return sprintf( '<h2%s id="%s">%s</h2>', $attrs, esc_attr( $id ), $text );
			},
			$html
		);
	}

	/**
	 * Helper to get or create platform term.
	 */
	private static function ensure_platform_term( $slug ) {
		$term = get_term_by( 'slug', $slug, 'ec_doc_platform' );
		if ( $term ) {
			return $term->term_id;
		}

		// Create if not exists.
		$name = ucwords( str_replace( '-', ' ', $slug ) );
		$result = wp_insert_term( $name, 'ec_doc_platform', [ 'slug' => $slug ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['term_id'];
	}
}
