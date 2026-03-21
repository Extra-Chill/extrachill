<?php
/**
 * Post Transfer — Cross-site post transfer for multisite.
 *
 * Transfers a complete WordPress post (content, meta, revisions,
 * taxonomy terms, featured image, embedded media) from one subsite
 * to another using only WordPress core APIs.
 *
 * @package ExtraChillStudio
 * @since   0.2.0
 */

namespace ExtraChillStudio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transfer a post from one multisite subsite to another.
 *
 * Copies the post, all revisions, post meta, taxonomy terms,
 * featured image, and rewrites embedded media URLs. Optionally
 * deletes the source post after successful transfer.
 *
 * @param int  $source_post_id Post ID on the source site.
 * @param int  $source_blog_id Source blog ID.
 * @param int  $target_blog_id Target blog ID.
 * @param bool $delete_source  Whether to delete the original after transfer.
 * @return int|\WP_Error New post ID on target site, or WP_Error.
 */
function transfer_post( int $source_post_id, int $source_blog_id, int $target_blog_id, bool $delete_source = false ) {

	// ── Step 1: Read everything from the source site ────────────────────

	switch_to_blog( $source_blog_id );

	$source_post = get_post( $source_post_id, ARRAY_A );
	if ( ! $source_post ) {
		restore_current_blog();
		return new \WP_Error( 'invalid_post', 'Source post not found.' );
	}

	$source_meta = get_post_meta( $source_post_id );

	$source_revisions = wp_get_post_revisions( $source_post_id, array(
		'order'         => 'ASC',
		'check_enabled' => false,
	) );

	// Taxonomy terms by name (IDs are per-site).
	$source_taxonomies = array();
	$taxonomies        = get_object_taxonomies( $source_post['post_type'] );
	foreach ( $taxonomies as $taxonomy ) {
		$terms = wp_get_object_terms( $source_post_id, $taxonomy, array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$source_taxonomies[ $taxonomy ] = $terms;
		}
	}

	// Featured image URL.
	$featured_image_url = null;
	$thumbnail_id       = get_post_thumbnail_id( $source_post_id );
	if ( $thumbnail_id ) {
		$featured_image_url = wp_get_attachment_url( $thumbnail_id );
	}

	// Source uploads base URL for content rewriting.
	$source_upload_dir = wp_get_upload_dir();
	$source_base_url   = $source_upload_dir['baseurl'];

	restore_current_blog();

	// ── Step 2: Create the post on the target site ──────────────────────

	switch_to_blog( $target_blog_id );

	if ( ! function_exists( 'media_sideload_image' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$new_post = array(
		'post_title'            => $source_post['post_title'],
		'post_content'          => $source_post['post_content'],
		'post_excerpt'          => $source_post['post_excerpt'],
		'post_status'           => $source_post['post_status'],
		'post_type'             => $source_post['post_type'],
		'post_author'           => $source_post['post_author'],
		'post_date'             => $source_post['post_date'],
		'post_date_gmt'         => $source_post['post_date_gmt'],
		'post_modified'         => $source_post['post_modified'],
		'post_modified_gmt'     => $source_post['post_modified_gmt'],
		'post_name'             => $source_post['post_name'],
		'post_password'         => $source_post['post_password'],
		'post_content_filtered' => $source_post['post_content_filtered'],
		'comment_status'        => $source_post['comment_status'],
		'ping_status'           => $source_post['ping_status'],
		'menu_order'            => $source_post['menu_order'],
		'post_parent'           => 0,
	);

	// Prevent auto-revision on insert.
	remove_action( 'post_updated', 'wp_save_post_revision' );
	remove_action( 'wp_after_insert_post', 'wp_save_post_revision_on_insert' );

	$new_post_id = wp_insert_post( wp_slash( $new_post ), true );

	add_action( 'post_updated', 'wp_save_post_revision' );
	add_action( 'wp_after_insert_post', 'wp_save_post_revision_on_insert' );

	if ( is_wp_error( $new_post_id ) ) {
		restore_current_blog();
		return $new_post_id;
	}

	// ── Step 3: Copy post meta ──────────────────────────────────────────

	$skip_meta_keys = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_thumbnail_id',
	);

	foreach ( $source_meta as $meta_key => $meta_values ) {
		if ( in_array( $meta_key, $skip_meta_keys, true ) ) {
			continue;
		}
		foreach ( $meta_values as $meta_value ) {
			add_post_meta( $new_post_id, $meta_key, $meta_value );
		}
	}

	// ── Step 4: Copy taxonomy terms ─────────────────────────────────────

	foreach ( $source_taxonomies as $taxonomy => $term_names ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			wp_set_object_terms( $new_post_id, $term_names, $taxonomy );
		}
	}

	// ── Step 5: Transfer featured image ─────────────────────────────────

	if ( $featured_image_url ) {
		$sideloaded = media_sideload_image( $featured_image_url, $new_post_id, null, 'id' );
		if ( ! is_wp_error( $sideloaded ) ) {
			set_post_thumbnail( $new_post_id, $sideloaded );
		}
	}

	// ── Step 6: Rewrite embedded media URLs in content ──────────────────

	$content                = $source_post['post_content'];
	$source_base_url_quoted = preg_quote( $source_base_url, '/' );

	if ( preg_match_all( '/' . $source_base_url_quoted . '\/[^\s"\'<>]+/i', $content, $matches ) ) {
		$unique_urls     = array_unique( $matches[0] );
		$url_replacements = array();

		foreach ( $unique_urls as $old_url ) {
			$sideloaded_id = media_sideload_image( $old_url, $new_post_id, null, 'id' );
			if ( ! is_wp_error( $sideloaded_id ) ) {
				$new_url = wp_get_attachment_url( $sideloaded_id );
				if ( $new_url ) {
					$url_replacements[ $old_url ] = $new_url;
				}
			}
		}

		if ( ! empty( $url_replacements ) ) {
			$content = str_replace(
				array_keys( $url_replacements ),
				array_values( $url_replacements ),
				$content
			);

			wp_update_post( array(
				'ID'           => $new_post_id,
				'post_content' => wp_slash( $content ),
			) );
		}
	}

	// ── Step 7: Copy revisions ──────────────────────────────────────────

	foreach ( $source_revisions as $revision ) {
		wp_insert_post( wp_slash( array(
			'post_title'    => $revision->post_title,
			'post_content'  => $revision->post_content,
			'post_excerpt'  => $revision->post_excerpt,
			'post_author'   => $revision->post_author,
			'post_date'     => $revision->post_date,
			'post_date_gmt' => $revision->post_date_gmt,
			'post_parent'   => $new_post_id,
			'post_type'     => 'revision',
			'post_status'   => 'inherit',
			'post_name'     => $new_post_id . '-revision-v1',
		) ), true );
	}

	// ── Step 8: Record transfer provenance ──────────────────────────────

	update_post_meta( $new_post_id, '_ec_transferred_from_blog', $source_blog_id );
	update_post_meta( $new_post_id, '_ec_transferred_from_post', $source_post_id );
	update_post_meta( $new_post_id, '_ec_transferred_at', current_time( 'mysql' ) );

	restore_current_blog();

	// ── Step 9: Delete source (optional) ────────────────────────────────

	if ( $delete_source ) {
		switch_to_blog( $source_blog_id );
		wp_delete_post( $source_post_id, true );
		restore_current_blog();
	}

	return $new_post_id;
}
