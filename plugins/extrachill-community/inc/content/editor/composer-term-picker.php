<?php
/**
 * Composer Term-Picker
 *
 * Curated, pick-from-existing taxonomy tagging for the bbPress topic composer.
 *
 * Users tag their post with EXISTING curated network taxonomy terms (location
 * today; artist/festival/venue later) via an autocomplete React picker. The
 * picker searches terms through the WP REST API (NO AJAX, per the system-wide
 * rule) and submits the chosen term IDs as a hidden `${field}[]` array that the
 * server-side save handler assigns on bbp_new_topic / bbp_edit_topic.
 *
 * Curated only: the picker NEVER creates terms. Typing a non-matching string
 * mints nothing, so the network's shared taxonomy tree never drifts.
 *
 * Taxonomy-parameterized: the picker is driven by a config array (one entry per
 * taxonomy). Enabling artist/festival/venue later is a config addition here, not
 * a new component — build location only now, leave the seam.
 *
 * @package ExtraChillCommunity
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomies the composer term-picker offers, in render order.
 *
 * Location-only today. To add artist/festival/venue later, append entries here
 * (and ensure each taxonomy is registered for `topic` and REST-enabled). No
 * React change required.
 *
 * Each entry:
 * - taxonomy     Taxonomy slug.
 * - rest_base    REST base for /wp/v2/<rest_base> term search.
 * - label        Visible field label.
 * - placeholder  Search input placeholder.
 * - field        POST field name; values submit as `${field}[]`.
 *
 * @return array<int,array<string,string>> Taxonomy picker config.
 */
function extrachill_community_term_picker_taxonomies() {
	$taxonomies = array(
		array(
			'taxonomy'    => 'location',
			'rest_base'   => 'location',
			'label'       => __( 'Location', 'extra-chill-community' ),
			'placeholder' => __( 'Search locations (e.g. Charleston)…', 'extra-chill-community' ),
			'field'       => 'bbp_topic_location',
		),
	);

	/**
	 * Filter the taxonomies offered by the composer term-picker.
	 *
	 * This is the generalization seam: artist/festival/venue can be enabled
	 * later purely by extending this config (each must be registered for the
	 * `topic` post type and REST-enabled).
	 *
	 * @param array $taxonomies Taxonomy picker config.
	 */
	return apply_filters( 'extrachill_community_term_picker_taxonomies', $taxonomies );
}

/**
 * Build the localized config the React picker consumes.
 *
 * Filters out taxonomies that are not actually registered/REST-enabled, and
 * seeds each taxonomy's `selected` terms when editing an existing topic.
 *
 * @param int $topic_id Topic ID (0 on the create flow).
 * @return array<string,mixed> Localizable config.
 */
function extrachill_community_term_picker_config( $topic_id = 0 ) {
	$taxonomies = array();

	foreach ( extrachill_community_term_picker_taxonomies() as $entry ) {
		$taxonomy = $entry['taxonomy'];

		$tax_object = get_taxonomy( $taxonomy );
		if ( ! $tax_object || empty( $tax_object->show_in_rest ) ) {
			continue;
		}

		$selected = array();
		if ( $topic_id > 0 ) {
			$terms = get_the_terms( $topic_id, $taxonomy );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$selected[] = array(
						'id'     => (int) $term->term_id,
						'name'   => $term->name,
						'parent' => (int) $term->parent,
					);
				}
			}
		}

		$rest_base = ! empty( $tax_object->rest_base ) ? $tax_object->rest_base : $taxonomy;

		$taxonomies[] = array(
			'taxonomy'     => $taxonomy,
			'restBase'     => $rest_base,
			'label'        => $entry['label'],
			'placeholder'  => $entry['placeholder'],
			'hierarchical' => (bool) $tax_object->hierarchical,
			'field'        => $entry['field'],
			'selected'     => $selected,
		);
	}

	return array(
		'restUrl'    => esc_url_raw( rest_url() ),
		'restNonce'  => wp_create_nonce( 'wp_rest' ),
		'taxonomies' => $taxonomies,
	);
}

/**
 * Enqueue the term-picker script + style on the bbPress topic composer.
 *
 * Guarded to bbPress contexts where the topic form renders (single forum,
 * topic edit, or the homepage New Topic modal). Uses the wp-scripts build
 * artifact and its generated dependency manifest.
 */
function extrachill_community_enqueue_term_picker() {
	if ( ! function_exists( 'is_bbpress' ) ) {
		return;
	}

	// Only where the create/edit topic form can appear.
	$is_topic_form_context = is_bbpress() || is_front_page();
	if ( ! $is_topic_form_context ) {
		return;
	}

	$config = extrachill_community_term_picker_config(
		( function_exists( 'bbp_is_topic_edit' ) && bbp_is_topic_edit() ) ? bbp_get_topic_id() : 0
	);

	// Nothing to render if no taxonomy resolved.
	if ( empty( $config['taxonomies'] ) ) {
		return;
	}

	$script_rel = 'build/term-picker.js';
	// wp-scripts emits JS-imported styles with a `style-` prefix.
	$style_rel = 'build/style-term-picker.css';
	$asset_rel = 'build/term-picker.asset.php';

	$script_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . $script_rel;
	$asset_path  = EXTRACHILL_COMMUNITY_PLUGIN_DIR . $asset_rel;

	if ( ! file_exists( $script_path ) ) {
		return;
	}

	$asset = file_exists( $asset_path )
		? require $asset_path
		: array(
			'dependencies' => array(),
			'version'      => EXTRACHILL_COMMUNITY_VERSION,
		);

	wp_enqueue_script(
		'extrachill-community-term-picker',
		EXTRACHILL_COMMUNITY_PLUGIN_URL . $script_rel,
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_localize_script( 'extrachill-community-term-picker', 'extrachillTermPicker', $config );

	$style_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . $style_rel;
	if ( file_exists( $style_path ) ) {
		wp_enqueue_style(
			'extrachill-community-term-picker',
			EXTRACHILL_COMMUNITY_PLUGIN_URL . $style_rel,
			array(),
			filemtime( $style_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_community_enqueue_term_picker', 20 );

/**
 * Render the term-picker mount points inside the topic form.
 *
 * One mount div per configured taxonomy; React hydrates each. Output replaces
 * the minimal #57 location <select> (the save handler is generalized to read
 * the picker's array field). Hooked on the same location-form action #57 left
 * in form-topic.php so the picker drops into the established slot.
 */
function extrachill_community_render_term_picker_mounts() {
	foreach ( extrachill_community_term_picker_taxonomies() as $entry ) {
		$tax_object = get_taxonomy( $entry['taxonomy'] );
		if ( ! $tax_object || empty( $tax_object->show_in_rest ) ) {
			continue;
		}

		printf(
			'<div class="ec-term-picker-mount" data-taxonomy="%s"></div>',
			esc_attr( $entry['taxonomy'] )
		);
	}
}
add_action( 'bbp_theme_before_topic_form_location', 'extrachill_community_render_term_picker_mounts' );
