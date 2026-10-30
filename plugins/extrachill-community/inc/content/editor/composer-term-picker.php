<?php
/**
 * Topic Taxonomy Correction
 *
 * Optional network-aware taxonomy correction for existing bbPress topics.
 *
 * New topics rely on the network-owned asynchronous classifier and do not show
 * taxonomy fields. Existing topics retain a collapsed correction UI that
 * searches approved network identities, projects missing terms locally, and
 * submits the returned local IDs through the existing edit handlers.
 *
 * Community consumes the network abilities directly. It does not load or wait
 * on Data Machine, and an unavailable ability runtime never blocks editing.
 *
 * @package ExtraChillCommunity
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomies the topic correction UI offers, in render order.
 *
 * Each taxonomy must be registered for `topic`.
 *
 * Each entry:
 * - taxonomy     Taxonomy slug.
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
			'label'       => __( 'Location', 'extra-chill-community' ),
			'placeholder' => __( 'Search locations (e.g. Charleston)…', 'extra-chill-community' ),
			'field'       => 'bbp_topic_location',
		),
		array(
			'taxonomy'    => 'festival',
			'label'       => __( 'Festival', 'extra-chill-community' ),
			'placeholder' => __( 'Search festivals (e.g. Bonnaroo)…', 'extra-chill-community' ),
			'field'       => 'bbp_topic_festival',
		),
		array(
			'taxonomy'    => 'artist',
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
add_action( 'init', 'extrachill_community_publish_discussion_composer_contract', 20 );

/**
 * Resolve a valid entity continuation from composer query state.
 *
 * Contract: `?compose=discussion&entity_taxonomy=<taxonomy>&entity_slug=<slug>`.
 * Only the entity taxonomies already supported by the composer are accepted,
 * and the slug must resolve to an existing REST-enabled topic term.
 *
 * @param array<string,mixed>|null $query Query values, or null for the request.
 * @return array{taxonomy:string,term:WP_Term}|null Valid continuation state.
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
	if ( ! $term instanceof WP_Term ) {
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
 * Build the localized config the edit-only correction UI consumes.
 *
 * New-topic forms intentionally receive no taxonomy config. Existing local
 * assignments seed the controls with the local IDs required by wp_set_object_terms().
 *
 * @param int $topic_id Topic ID (0 on the create flow).
 * @return array<string,mixed> Localizable config.
 */
function extrachill_community_term_picker_config( $topic_id = 0 ) {
	$taxonomies = array();
	$topic_id   = absint( $topic_id );

	if ( 0 === $topic_id ) {
		return array(
			'restUrl'    => esc_url_raw( rest_url() ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'topicId'    => 0,
			'taxonomies' => array(),
		);
	}

	foreach ( extrachill_community_term_picker_taxonomies() as $entry ) {
		$taxonomy = $entry['taxonomy'];

		$tax_object = get_taxonomy( $taxonomy );
		if ( ! $tax_object || ! is_object_in_taxonomy( 'topic', $taxonomy ) ) {
			continue;
		}

		$selected = array();
		$terms    = get_the_terms( $topic_id, $taxonomy );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$selected[] = array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		$taxonomies[] = array(
			'taxonomy'    => $taxonomy,
			'label'       => $entry['label'],
			'placeholder' => $entry['placeholder'],
			'field'       => $entry['field'],
			'selected'    => $selected,
		);
	}

	return array(
		'restUrl'    => esc_url_raw( rest_url() ),
		'restNonce'  => wp_create_nonce( 'wp_rest' ),
		'topicId'    => $topic_id,
		'taxonomies' => $taxonomies,
	);
}

/**
 * Enqueue the correction UI only while editing an existing topic.
 *
 * Uses the wp-scripts build artifact and its generated dependency manifest.
 */
function extrachill_community_enqueue_term_picker() {
	if ( ! function_exists( 'bbp_is_topic_edit' ) || ! bbp_is_topic_edit() ) {
		return;
	}

	$config = extrachill_community_term_picker_config( bbp_get_topic_id() );

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
			(string) filemtime( $style_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_community_enqueue_term_picker', 20 );

/**
 * Render collapsed correction controls on topic edit forms only.
 *
 * Automatic network classification owns the create flow. These controls are a
 * secondary human correction path and remain optional when the ability runtime
 * is unavailable.
 */
function extrachill_community_render_term_picker_mounts() {
	if ( ! function_exists( 'bbp_is_topic_edit' ) || ! bbp_is_topic_edit() ) {
		return;
	}

	$mounts = array();
	foreach ( extrachill_community_term_picker_taxonomies() as $entry ) {
		$tax_object = get_taxonomy( $entry['taxonomy'] );
		if ( ! $tax_object || ! is_object_in_taxonomy( 'topic', $entry['taxonomy'] ) ) {
			continue;
		}

		$mounts[] = sprintf( '<div class="ec-term-picker-mount" data-taxonomy="%s"></div>', esc_attr( $entry['taxonomy'] ) );
	}

	if ( empty( $mounts ) ) {
		return;
	}

	printf(
		'<details class="ec-topic-term-corrections"><summary>%s</summary><p class="ec-topic-term-corrections__description">%s</p>%s</details>',
		esc_html__( 'Correct classifications (optional)', 'extra-chill-community' ),
		esc_html__( 'Add or remove approved network terms. Your selections are saved as human corrections.', 'extra-chill-community' ),
		implode( '', $mounts ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each mount is escaped above.
	);
}
add_action( 'bbp_theme_before_topic_form_location', 'extrachill_community_render_term_picker_mounts' );
