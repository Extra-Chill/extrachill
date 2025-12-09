<?php
/**
 * Documentation Platform Taxonomy Seeding
 *
 * Seeds default ec_doc_platform terms on plugin activation.
 *
 * AGENT REFERENCE: Each slug maps to:
 * - ec_docs/{slug}/ subdirectory for markdown source files
 * - {site}.extrachill.com for docs-info API calls
 *
 * Platform Mapping:
 * | Slug            | ec_docs/ Dir      | Site Domain               |
 * |-----------------|-------------------|---------------------------|
 * | artist-platform | artist-platform/  | artist.extrachill.com     |
 * | community       | community/        | community.extrachill.com  |
 * | events-calendar | events-calendar/  | events.extrachill.com     |
 * | stream          | stream/           | stream.extrachill.com     |
 * | newsletter      | newsletter/       | newsletter.extrachill.com |
 * | shop            | shop/             | shop.extrachill.com       |
 * | chat            | chat/             | chat.extrachill.com       |
 * | blog            | blog/             | extrachill.com            |
 * | horoscopes      | horoscopes/       | horoscope.extrachill.com  |
 *
 * @package ExtraChillDocs
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds default platform terms on plugin activation.
 *
 * @since 0.1.0
 * @return void
 */
function extrachill_docs_seed_platforms() {
	// Ensure taxonomy is registered before seeding.
	extrachill_docs_register_platform_taxonomy();

	$platforms = array(
		'artist-platform' => 'Artist Platform',
		'community'       => 'Community',
		'events-calendar' => 'Events',
		'stream'          => 'Stream',
		'newsletter'      => 'Newsletter',
		'shop'            => 'Shop',
		'chat'            => 'Chat',
		'blog'            => 'Blog',
		'horoscopes'      => 'Horoscopes',
	);

	foreach ( $platforms as $slug => $name ) {
		if ( ! term_exists( $slug, 'ec_doc_platform' ) ) {
			wp_insert_term( $name, 'ec_doc_platform', array( 'slug' => $slug ) );
		}
	}
}
