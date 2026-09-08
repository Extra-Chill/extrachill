<?php
/**
 * Genre import lockout for the event upsert path.
 *
 * Genre on events is a derived projection of artist genres
 * (Extra-Chill/extrachill-events#828); the import path can never write it
 * (Extra-Chill/extrachill-events#30 §3). Two gates close every write route
 * this plugin owns:
 *
 *  - `datamachine_taxonomy_assign_value` refuses the assignment outright for
 *    genre on the event post type. extrachill-network ships the same gate
 *    (vocabulary ownership); this one keeps the rule enforced in the domain
 *    owner even when the network release lags.
 *  - `datamachine_tools` strips the genre parameter from the registered
 *    upsert_event tool schema, so the AI never sees it. The generic taxonomy
 *    parameter builder (data-machine core TaxonomyHandler) applies its
 *    per-taxonomy filter too late to remove a parameter; the registered tool
 *    definition is corrected here instead.
 *
 * @package ExtraChillEvents
 * @since 0.65.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/GenreSync.php';

/**
 * Initialize the genre import lockout hooks
 */
function extrachill_events_init_import_lockout() {
	add_filter( 'datamachine_taxonomy_assign_value', 'extrachill_events_lock_genre_assign_value', 10, 3 );
	add_filter( 'datamachine_tools', 'extrachill_events_strip_genre_from_upsert_tool', 100 );
}

/**
 * Refuse genre assignments on the event post type.
 *
 * @param mixed  $value    Supplied taxonomy value.
 * @param string $taxonomy Taxonomy being assigned.
 * @param int    $post_id  Post receiving the assignment.
 * @return mixed The untouched value, or '' to skip the assignment.
 */
function extrachill_events_lock_genre_assign_value( $value, $taxonomy, $post_id ) {
	unset( $post_id );

	if ( \ExtraChillEvents\Core\GenreSync::is_import_locked_taxonomy( (string) $taxonomy, DATA_MACHINE_EVENTS_POST_TYPE ) ) {
		return '';
	}

	return $value;
}

/**
 * Remove the genre parameter from the upsert_event tool schema.
 *
 * @param array $tools Raw datamachine_tools registry.
 * @return array Registry with genre stripped from the upsert_event schema.
 */
function extrachill_events_strip_genre_from_upsert_tool( array $tools ): array {
	return \ExtraChillEvents\Core\GenreSync::strip_genre_tool_param( $tools );
}
