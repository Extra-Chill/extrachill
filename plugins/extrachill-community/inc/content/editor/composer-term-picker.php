<?php
/**
 * Composer Term-Picker
 *
 * Curated, pick-from-existing taxonomy tagging for the bbPress topic composer.
 *
 * Users tag their post with EXISTING curated network taxonomy terms (location
 * festival, and artist) via an autocomplete React picker. The
 * picker searches terms through the WP REST API (NO AJAX, per the system-wide
 * rule) and submits the chosen term IDs as a hidden `${field}[]` array that the
 * server-side save handler assigns on bbp_new_topic / bbp_edit_topic.
 *
 * Curated only: the picker NEVER creates terms. Typing a non-matching string
 * mints nothing, so the network's shared taxonomy tree never drifts.
 *
 * Taxonomy-parameterized: the picker is driven by a config array (one entry per
 * taxonomy). Enabling another taxonomy is a config addition here, not a new
 * component.
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
 * Each taxonomy must be registered for `topic` and REST-enabled. No React
 * change is required to add a future taxonomy.
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
		array(
			'taxonomy'    => 'festival',
			'rest_base'   => 'festival',
			'label'       => __( 'Festival', 'extra-chill-community' ),
			'placeholder' => __( 'Search festivals (e.g. Bonnaroo)…', 'extra-chill-community' ),
			'field'       => 'bbp_topic_festival',
		),
		array(
			'taxonomy'    => 'artist',
			'rest_base'   => 'artist',
			'label'       => __( 'Artist', 'extra-chill-community' ),
			'placeholder' => __( 'Search artists…', 'extra-chill-community' ),
			'field'       => 'bbp_topic_artist',
		),
	);

	/**
	 * Filter the taxonomies offered by the composer term-picker.
	 *
	 * This is the generalization seam: future taxonomies can be enabled purely
	 * by extending this config (each must be registered for the `topic` post
	 * type and REST-enabled).
	 *
	 * @param array $taxonomies Taxonomy picker config.
	 */
	return apply_filters( 'extrachill_community_term_picker_taxonomies', $taxonomies );
}

/**
 * Return the public discussion composer contract.
 *
 * This serializable definition is both the live resolver configuration and the
 * deployment marker read by cross-site consumers with get_blog_option().
 *
 * @return array{
 *     schema_version:int,
 *     action:string,
 *     query_parameters:array{action:string,taxonomy:string,slug:string},
 *     supported_taxonomies:string[]
 * }
 */
function extrachill_community_discussion_composer_contract() {
	$taxonomies = array_column( extrachill_community_term_picker_taxonomies(), 'taxonomy' );

	return array(
		'schema_version'       => 1,
		'action'               => 'discussion',
		'query_parameters'     => array(
			'action'   => 'compose',
			'taxonomy' => 'entity_taxonomy',
			'slug'     => 'entity_slug',
		),
		'supported_taxonomies' => array_values( array_unique( $taxonomies ) ),
	);
}

/**
 * Option key used to publish the composer contract on the Community site.
 *
 * @return string
 */
function extrachill_community_discussion_composer_contract_option() {
	return 'extrachill_community_discussion_composer_contract';
}

/**
 * Publish or migrate the deployment-discoverable composer contract.
 *
 * The plugin is site-active on Community, so the current-site option is
 * directly readable from any network site with get_blog_option().
 *
 * @return bool Whether the stored contract changed.
 */
function extrachill_community_publish_discussion_composer_contract() {
	$option   = extrachill_community_discussion_composer_contract_option();
	$contract = extrachill_community_discussion_composer_contract();

	if ( get_option( $option, null ) === $contract ) {
		return false;
	}

	update_option( $option, $contract, false );
	return true;
}
add_action( 'plugins_loaded', 'extrachill_community_publish_discussion_composer_contract', 20 );

/**
 * Resolve a valid entity continuation from composer query state.
 *
 * Contract: `?compose=discussion&entity_taxonomy=<taxonomy>&entity_slug=<slug>`.
 * Only the entity taxonomies already supported by the composer are accepted,
 * and the slug must resolve to an existing REST-enabled topic term.
 *
 * @param array<string,mixed>|null $query Query values, or null for the request.
 * @return array{taxonomy:string,term:object}|null Valid continuation state.
 */
function extrachill_community_get_discussion_composer_state( $query = null ) {
	$contract = extrachill_community_discussion_composer_contract();
	$keys     = $contract['query_parameters'];

	if ( null === $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only composer state; no mutation occurs.
		$query = $_GET;
	}

	if ( ! isset( $query[ $keys['action'] ], $query[ $keys['taxonomy'] ], $query[ $keys['slug'] ] )
		|| ! is_scalar( $query[ $keys['action'] ] )
		|| ! is_scalar( $query[ $keys['taxonomy'] ] )
		|| ! is_scalar( $query[ $keys['slug'] ] ) ) {
		return null;
	}

	$raw_compose  = wp_unslash( (string) $query[ $keys['action'] ] );
	$raw_taxonomy = wp_unslash( (string) $query[ $keys['taxonomy'] ] );
	$raw_slug     = wp_unslash( (string) $query[ $keys['slug'] ] );
	$compose      = sanitize_key( $raw_compose );
	$taxonomy     = sanitize_key( $raw_taxonomy );
	$slug         = sanitize_title( $raw_slug );

	if ( $raw_compose !== $compose || $raw_taxonomy !== $taxonomy || $raw_slug !== $slug
		|| $contract['action'] !== $compose
		|| ! in_array( $taxonomy, $contract['supported_taxonomies'], true )
		|| '' === $slug ) {
		return null;
	}

	$tax_object = get_taxonomy( $taxonomy );
	if ( ! $tax_object || empty( $tax_object->show_in_rest ) || ! is_object_in_taxonomy( 'topic', $taxonomy ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	return array(
		'taxonomy' => $taxonomy,
		'term'     => $term,
	);
}

/**
 * Build the canonical Community composer URL for a validated entity term.
 *
 * @param string $taxonomy Entity taxonomy.
 * @param string $slug     Existing term slug.
 * @return string Composer URL, or an empty string for invalid state.
 */
function extrachill_community_get_discussion_composer_url( $taxonomy, $slug ) {
	$contract = extrachill_community_discussion_composer_contract();
	$keys     = $contract['query_parameters'];
	$state    = extrachill_community_get_discussion_composer_state(
		array(
			$keys['action']   => $contract['action'],
			$keys['taxonomy'] => $taxonomy,
			$keys['slug']     => $slug,
		)
	);
	if ( ! $state ) {
		return '';
	}

	$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url( '/' );

	return add_query_arg(
		array(
			$keys['action']   => $contract['action'],
			$keys['taxonomy'] => $state['taxonomy'],
			$keys['slug']     => $state['term']->slug,
		),
		trailingslashit( $community_url )
	);
}

/**
 * Build the canonical login URL for a validated composer continuation.
 *
 * Extra Chill Users owns validation and precedence for the eventual login
 * round trip. Community only supplies its validated same-network destination.
 *
 * @param string $taxonomy Entity taxonomy.
 * @param string $slug     Existing term slug.
 * @return string Login URL, or an empty string for invalid state.
 */
function extrachill_community_get_discussion_composer_login_url( $taxonomy, $slug ) {
	$redirect_to = extrachill_community_get_discussion_composer_url( $taxonomy, $slug );
	if ( '' === $redirect_to ) {
		return '';
	}

	$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url( '/' );

	return add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), trailingslashit( $community_url ) . 'login/' );
}

/**
 * Whether the current user may receive composer preselection state.
 *
 * The normal bbPress form and submission checks remain authoritative; this
 * gate only prevents continuation state from bypassing topic permissions.
 *
 * @return bool
 */
function extrachill_community_can_continue_discussion_composer() {
	return is_user_logged_in()
		&& function_exists( 'bbp_current_user_can_publish_topics' )
		&& bbp_current_user_can_publish_topics();
}

/**
 * Send logged-out continuation requests through the canonical login page.
 */
function extrachill_community_maybe_redirect_discussion_composer_login() {
	if ( ! is_front_page() || is_user_logged_in() ) {
		return;
	}

	$state = extrachill_community_get_discussion_composer_state();
	if ( ! $state ) {
		return;
	}

	$login_url = extrachill_community_get_discussion_composer_login_url( $state['taxonomy'], $state['term']->slug );
	if ( '' === $login_url ) {
		return;
	}

	wp_safe_redirect( $login_url );
	exit;
}
add_action( 'template_redirect', 'extrachill_community_maybe_redirect_discussion_composer_login' );

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
	$taxonomies   = array();
	$continuation = 0 === (int) $topic_id && extrachill_community_can_continue_discussion_composer()
		? extrachill_community_get_discussion_composer_state()
		: null;

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
		} elseif ( $continuation && $taxonomy === $continuation['taxonomy'] ) {
			$selected[] = array(
				'id'     => (int) $continuation['term']->term_id,
				'name'   => $continuation['term']->name,
				'parent' => (int) $continuation['term']->parent,
			);
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
