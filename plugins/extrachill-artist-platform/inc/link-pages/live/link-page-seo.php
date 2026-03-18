<?php
/**
 * Link Page SEO Integration
 *
 * Provides SEO context for artist link pages. Hooks into extrachill-seo
 * filters so the SEO plugin renders proper meta tags when ec_seo_render_head()
 * is called from the link page template.
 *
 * Also registers published link pages in the sitemap.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build SEO context array for a link page.
 *
 * @param int $artist_id    Artist profile post ID.
 * @param int $link_page_id Link page post ID.
 * @return array Context array for ec_seo_render_head().
 */
function extrachill_artist_link_page_seo_context( $artist_id, $link_page_id ) {
	$link_page = get_post( $link_page_id );
	$artist    = get_post( $artist_id );

	if ( ! $link_page || ! $artist ) {
		return array();
	}

	$slug        = $link_page->post_name;
	$artist_name = $artist->post_title;
	$canonical   = 'https://extrachill.link/' . $slug . '/';

	// Description from artist bio/excerpt.
	$description = '';
	if ( ! empty( $artist->post_excerpt ) ) {
		$description = wp_strip_all_tags( $artist->post_excerpt );
	} elseif ( ! empty( $artist->post_content ) ) {
		$content     = wp_strip_all_tags( $artist->post_content );
		$content     = preg_replace( '/\s+/', ' ', trim( $content ) );
		$description = $content;
	}

	// Truncate to 160 chars.
	if ( strlen( $description ) > 160 ) {
		$description = substr( $description, 0, 157 );
		$last_space  = strrpos( $description, ' ' );
		if ( false !== $last_space ) {
			$description = substr( $description, 0, $last_space );
		}
		$description .= '...';
	}

	if ( empty( $description ) ) {
		$description = sprintf( '%s — all important links in one place on Extra Chill.', $artist_name );
	}

	// Profile image for OG.
	$image     = '';
	$image_alt = '';
	$thumb_id  = get_post_thumbnail_id( $artist_id );
	if ( $thumb_id ) {
		$image_src = wp_get_attachment_image_url( $thumb_id, 'large' );
		if ( $image_src ) {
			$image     = $image_src;
			$image_alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
			if ( empty( $image_alt ) ) {
				$image_alt = $artist_name;
			}
		}
	}

	return array(
		'title'       => $artist_name . ' | extrachill.link',
		'description' => $description,
		'canonical'   => $canonical,
		'image'       => $image,
		'image_alt'   => $image_alt,
		'og_type'     => 'profile',
	);
}

/**
 * Add published link pages to the sitemap.
 *
 * @param array $urls Existing sitemap URL entries.
 * @return array Modified URL entries.
 */
function extrachill_artist_link_page_sitemap_urls( $urls ) {
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return $urls;
	}

	$current_blog = get_current_blog_id();
	$did_switch   = false;

	if ( $current_blog !== $artist_blog_id ) {
		switch_to_blog( $artist_blog_id );
		$did_switch = true;
	}

	$link_pages = get_posts(
		array(
			'post_type'      => 'artist_link_page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $link_pages as $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page ) {
			continue;
		}

		$urls[] = array(
			'loc'     => 'https://extrachill.link/' . $page->post_name . '/',
			'lastmod' => get_the_modified_date( 'c', $page ),
		);
	}

	if ( $did_switch ) {
		restore_current_blog();
	}

	return $urls;
}
add_filter( 'extrachill_seo_sitemap_urls', 'extrachill_artist_link_page_sitemap_urls' );
