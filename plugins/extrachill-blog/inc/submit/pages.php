<?php
/**
 * Native page rendering and Blocks Everywhere host integration.
 *
 * @package ExtraChillBlog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Match a native page by its full hierarchical path.
 *
 * WordPress is_page() compares slugs, not a child page's full path, so using "write"
 * alone could collide with an unrelated page elsewhere in the hierarchy.
 *
 * @param string $path Page path.
 * @return bool Whether the current queried page is the path.
 */
function extrachill_blog_dispatch_is_page( $path ) {
	if ( ! is_page() || ! isset( EXTRACHILL_BLOG_DISPATCH_PAGES[ $path ] ) ) {
		return false;
	}
	$current = get_queried_object();
	$owned   = (array) get_option( EXTRACHILL_BLOG_DISPATCH_PAGES_OPTION, array() );
	return $current instanceof WP_Post
		&& isset( $owned[ $path ] )
		&& (int) $current->ID === (int) $owned[ $path ]
		&& EXTRACHILL_BLOG_DISPATCH_PAGES[ $path ]['sentinel'] === $current->post_content;
}

/**
 * Resolve and authorize the requested canonical draft without leaking it.
 *
 * @return WP_Post|WP_Error Authorized post or generic error.
 */
function extrachill_blog_dispatch_editor_post() {
	static $resolved = null;
	if ( null !== $resolved ) {
		return $resolved;
	}

	$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route selector; authorization follows.
	if ( ! is_user_logged_in() ) {
		$resolved = new WP_Error( 'artist_dispatch_login_required', __( 'Please log in to write an Artist Dispatch.', 'extrachill-blog' ), array( 'status' => 403 ) );
		return $resolved;
	}
	if ( ! $post_id ) {
		$resolved = new WP_Error( 'artist_dispatch_not_found', __( 'That Artist Dispatch draft is unavailable.', 'extrachill-blog' ), array( 'status' => 404 ) );
		return $resolved;
	}

	$post      = get_post( $post_id );
	$submitter = $post ? absint( get_post_meta( $post_id, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, true ) ) : 0;
	if ( ! $post || 'post' !== $post->post_type || ! extrachill_blog_is_artist_dispatch( $post_id ) || get_current_user_id() !== $submitter ) {
		$resolved = new WP_Error( 'artist_dispatch_not_found', __( 'That Artist Dispatch draft is unavailable.', 'extrachill-blog' ), array( 'status' => 404 ) );
		return $resolved;
	}
	$access = extrachill_blog_dispatch_access();
	if ( 'draft' !== $post->post_status || ! current_user_can( 'edit_post', $post_id ) || ! extrachill_blog_dispatch_is_approved( $access ) || ! extrachill_blog_dispatch_has_current_terms( $access ) ) {
		$resolved = new WP_Error( 'artist_dispatch_locked', __( 'This Artist Dispatch is not available for editing.', 'extrachill-blog' ), array( 'status' => 403 ) );
		return $resolved;
	}

	$resolved = $post;
	return $resolved;
}

/**
 * Protect the editor page before any private markup or assets render.
 */
function extrachill_blog_dispatch_protect_editor() {
	if ( ! extrachill_blog_dispatch_is_page( 'submit/write' ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	$post = extrachill_blog_dispatch_editor_post();
	if ( is_wp_error( $post ) ) {
		$status = (int) $post->get_error_data()['status'];
		wp_die( esc_html( $post->get_error_message() ), esc_html__( 'Artist Dispatch unavailable', 'extrachill-blog' ), array( 'response' => $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer response code, not output.
	}
	if ( ! extrachill_blog_dispatch_has_editor_dependency() ) {
		wp_die(
			esc_html__( 'The writing editor is temporarily unavailable. An administrator must activate Blocks Everywhere 3.6.0 or newer.', 'extrachill-blog' ),
			esc_html__( 'Editor dependency unavailable', 'extrachill-blog' ),
			array( 'response' => 503 )
		);
	}
}
add_action( 'template_redirect', 'extrachill_blog_dispatch_protect_editor', 1 );

/**
 * Register the Artist Dispatch editor as a Blocks Everywhere context.
 *
 * @param array $contexts Registered contexts.
 * @return array Contexts.
 */
function extrachill_blog_dispatch_editor_context( $contexts ) {
	$contexts['artist-dispatch'] = array(
		'type'              => 'artist-dispatch',
		'textarea'          => '#artist-dispatch-content',
		'container'         => '.artist-dispatch-editor',
		'trigger'           => 'wp_enqueue_scripts',
		'trigger_priority'  => 20,
		'condition'         => function () {
			return extrachill_blog_dispatch_is_page( 'submit/write' ) && extrachill_blog_dispatch_editor_post() instanceof WP_Post;
		},
		'oembed_permission' => function () {
			return extrachill_blog_dispatch_editor_post() instanceof WP_Post;
		},
		'settings_provider' => function ( $settings ) {
			$post = extrachill_blog_dispatch_editor_post();
			if ( ! $post instanceof WP_Post ) {
				return $settings;
			}
			$settings['postEntity'] = array(
				'type' => 'post',
				'id'   => (int) $post->ID,
			);
			$settings['editor']['hasUploadPermissions'] = false;
			$settings['blocksEverywhere']['blocks']['allowBlocks'] = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/separator', 'core/embed' );
			$settings['blocksEverywhere']['allowEmbeds'] = array( 'youtube', 'vimeo', 'soundcloud', 'spotify', 'bandcamp' );
			$settings['blocksEverywhere']['chrome'] = array(
				'mode'    => 'full-height',
				'topBar'  => true,
				'preview' => true,
				'footer'  => true,
			);
			$settings['blocksEverywhere']['sidebar'] = array(
				'inserter'  => true,
				'inspector' => false,
			);
			$settings['blocksEverywhere']['settingsKey'] = 'artist-dispatch';
			return $settings;
		},
		'preload_paths'     => function ( $paths ) {
			$post = extrachill_blog_dispatch_editor_post();
			if ( $post instanceof WP_Post ) {
				$paths[] = '/wp/v2/posts/' . $post->ID . '?context=edit';
				$paths[] = '/wp/v2/posts/' . $post->ID . '/autosaves?context=edit';
			}
			return array_values( array_unique( $paths ) );
		},
	);
	return $contexts;
}
add_filter( 'blocks_everywhere_contexts', 'extrachill_blog_dispatch_editor_context' );

/**
 * Enqueue assets on the three submission pages.
 */
function extrachill_blog_dispatch_enqueue_assets() {
	if ( ! extrachill_blog_dispatch_is_page( 'submit' ) && ! extrachill_blog_dispatch_is_page( 'submit/guidelines' ) && ! extrachill_blog_dispatch_is_page( 'submit/write' ) ) {
		return;
	}
	$css = EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/css/artist-dispatch.css';
	wp_enqueue_style( 'extrachill-blog-artist-dispatch', EXTRACHILL_BLOG_PLUGIN_URL . 'assets/css/artist-dispatch.css', array( 'extrachill-root' ), filemtime( $css ) );

	if ( extrachill_blog_dispatch_is_page( 'submit' ) && is_user_logged_in() ) {
		$js = EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/js/artist-dispatch.js';
		wp_enqueue_script( 'extrachill-blog-artist-dispatch', EXTRACHILL_BLOG_PLUGIN_URL . 'assets/js/artist-dispatch.js', array( 'wp-api-fetch' ), filemtime( $js ), true );
		wp_localize_script(
			'extrachill-blog-artist-dispatch',
			'ecArtistDispatch',
			array(
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'restRoot'     => esc_url_raw( rest_url() ),
				'writeUrl'     => home_url( '/submit/write/' ),
				'termsVersion' => EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION,
			)
		);
	}

	if ( extrachill_blog_dispatch_is_page( 'submit/write' ) && extrachill_blog_dispatch_editor_post() instanceof WP_Post ) {
		$js = EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/js/artist-dispatch-editor.js';
		wp_enqueue_script( 'extrachill-blog-artist-dispatch-editor', EXTRACHILL_BLOG_PLUGIN_URL . 'assets/js/artist-dispatch-editor.js', array( 'blocks-everywhere', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n' ), filemtime( $js ), true );
		wp_localize_script( 'extrachill-blog-artist-dispatch-editor', 'ecArtistDispatchEditor', array( 'dashboardUrl' => home_url( '/submit/' ) ) );
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_blog_dispatch_enqueue_assets', 30 );

/**
 * Render the public Artist Dispatch introduction.
 *
 * @return string HTML.
 */
function extrachill_blog_dispatch_intro_html() {
	return '<header class="artist-dispatch-hero"><p class="artist-dispatch-kicker">' . esc_html__( 'Stories from inside the music', 'extrachill-blog' ) . '</p><h1>' . esc_html__( 'Artist Dispatch', 'extrachill-blog' ) . '</h1><p>' . esc_html__( 'Every song, show, and tour has a story. Artist Dispatch is a place to share yours in your own words, with help from an Extra Chill editor.', 'extrachill-blog' ) . '</p></header><section class="artist-dispatch-panel ec-surface-card ec-mobile-full-width-panel"><h2>' . esc_html__( 'Tell us what happened', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Write about making a record, life on tour, a lesson from the studio, the scene around you, or another part of music that you have lived firsthand.', 'extrachill-blog' ) . '</p><p>' . esc_html__( 'This is a place for honest stories, not press releases or reviews of your own music. An Extra Chill editor reads every piece and may help you shape it before it goes live.', 'extrachill-blog' ) . '</p><a class="button-2 button-medium" href="' . esc_url( home_url( '/submit/guidelines/' ) ) . '">' . esc_html__( 'See how it works', 'extrachill-blog' ) . '</a></section>';
}

/**
 * Render logged-out state without nonce or personalized data.
 *
 * @return string HTML.
 */
function extrachill_blog_dispatch_logged_out_html() {
	$login = wp_login_url( home_url( '/submit/' ) );
	$join  = function_exists( 'ec_get_site_url' ) ? trailingslashit( ec_get_site_url( 'community' ) ) . 'join/' : 'https://community.extrachill.com/join/';
	return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel"><h2>' . esc_html__( 'Have a story to share?', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Log in to see if you can request access. If you are new here, join the community, meet other music people, and start taking part.', 'extrachill-blog' ) . '</p><div class="artist-dispatch-actions"><a class="button-1 button-medium" href="' . esc_url( $login ) . '">' . esc_html__( 'Log in', 'extrachill-blog' ) . '</a><a class="button-2 button-medium" href="' . esc_url( $join ) . '">' . esc_html__( 'Join the community', 'extrachill-blog' ) . '</a></div></section>';
}

/**
 * Render eligibility criteria from the owner contract.
 *
 * @param array $eligibility Eligibility state.
 * @return string HTML.
 */
function extrachill_blog_dispatch_criteria_html( $eligibility ) {
	$criteria = isset( $eligibility['criteria'] ) && is_array( $eligibility['criteria'] ) ? $eligibility['criteria'] : array();
	if ( empty( $criteria ) ) {
		return '';
	}
	$html   = '<ul class="artist-dispatch-criteria">';
	$labels = array(
		'points'            => __( '50 community points', 'extrachill-blog' ),
		'onboarding'        => __( 'Account setup complete', 'extrachill-blog' ),
		'claimed_account'   => __( 'Account confirmed', 'extrachill-blog' ),
		'active_moderation' => __( 'Account in good standing', 'extrachill-blog' ),
		'claimed_artist'    => __( 'Connected artist profile', 'extrachill-blog' ),
	);
	foreach ( $criteria as $key => $criterion ) {
		if ( ! is_array( $criterion ) || ! isset( $labels[ $key ] ) ) {
			continue;
		}
		$html .= '<li class="' . ( ! empty( $criterion['passed'] ) ? 'is-met' : 'is-unmet' ) . '"><strong>' . esc_html( $labels[ $key ] ) . '</strong></li>';
	}
	foreach ( isset( $eligibility['reasons'] ) ? (array) $eligibility['reasons'] : array() as $reason ) {
		$html .= '<li class="is-unmet"><span>' . esc_html( $reason ) . '</span></li>';
	}
	return $html . '</ul>';
}

/**
 * Resolve safe represented-artist IDs from the Users owner contract.
 *
 * @param array $access Access state.
 * @return array<int,array{name:string,url:string}> Artist display data keyed by ID.
 */
function extrachill_blog_dispatch_access_artists( $access ) {
	$artist_ids = isset( $access['eligibility']['criteria']['claimed_artist']['artist_ids'] )
		? array_map( 'absint', (array) $access['eligibility']['criteria']['claimed_artist']['artist_ids'] )
		: array();
	$artists    = array();
	foreach ( $artist_ids as $artist_id ) {
		$display = extrachill_blog_dispatch_artist_display( $artist_id );
		if ( ! empty( $display ) ) {
			$artists[ $artist_id ] = $display;
		}
	}
	return $artists;
}

/**
 * Render the access request form from canonical represented artists.
 *
 * @param array $access Access state.
 * @return string HTML.
 */
function extrachill_blog_dispatch_request_html( $access ) {
	$artists = extrachill_blog_dispatch_access_artists( $access );
	$options = '';
	foreach ( $artists as $artist_id => $artist ) {
		$options .= '<option value="' . esc_attr( $artist_id ) . '">' . esc_html( $artist['name'] ) . '</option>';
	}
	if ( '' === $options ) {
		return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel is-closed"><h2>' . esc_html__( 'Connect your artist profile', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Before you can request access, your Extra Chill account needs to be connected to the artist profile you manage.', 'extrachill-blog' ) . '</p></section>';
	}

	/* translators: %s: Artist Dispatch guidelines URL. */
	$acknowledgement = sprintf( wp_kses_post( __( 'I have read the <a href="%s">Artist Dispatch guidelines</a>, explained my connection to this artist or project, and agree to the publishing terms.', 'extrachill-blog' ) ), esc_url( home_url( '/submit/guidelines/' ) ) );
	return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel"><h2>' . esc_html__( 'Request Artist Dispatch access', 'extrachill-blog' ) . '</h2><form id="artist-dispatch-request" class="artist-dispatch-form"><label>' . esc_html__( 'Artist or project you represent', 'extrachill-blog' ) . '<select name="artist_id" required>' . $options . '</select></label><label>' . esc_html__( 'What story do you want to tell?', 'extrachill-blog' ) . '<textarea name="description" minlength="50" maxlength="2000" required></textarea></label><label>' . esc_html__( 'Optional sample or reference URL', 'extrachill-blog' ) . '<input type="url" name="sample_url"></label><label class="ec-checkbox-row"><input type="checkbox" name="acknowledgement" required><span>' . $acknowledgement . '</span></label><button class="button-1 button-medium" type="submit">' . esc_html__( 'Request access', 'extrachill-blog' ) . '</button><p class="artist-dispatch-message" aria-live="polite"></p></form></section>';
}

/**
 * Query the approved contributor's own marked posts.
 *
 * @return array<string,WP_Post[]> Posts by status.
 */
function extrachill_blog_dispatch_dashboard_posts() {
	$groups = array(
		'draft'   => array(),
		'pending' => array(),
		'publish' => array(),
	);
	$query  = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => array_keys( $groups ),
			'author'         => get_current_user_id(),
			'posts_per_page' => 50,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_key'       => EXTRACHILL_BLOG_DISPATCH_SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded current-author dashboard.
			'meta_value'     => EXTRACHILL_BLOG_DISPATCH_SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded current-author dashboard.
		)
	);
	foreach ( $query->posts as $post ) {
		$groups[ $post->post_status ][] = $post;
	}
	return $groups;
}

/**
 * Render one dashboard group.
 *
 * @param string    $title Group title.
 * @param WP_Post[] $posts Posts.
 * @param bool      $editable Whether links resume editing.
 * @return string HTML.
 */
function extrachill_blog_dispatch_group_html( $title, $posts, $editable = false ) {
	$html = '<section class="artist-dispatch-group"><h3>' . esc_html( $title ) . '</h3>';
	if ( empty( $posts ) ) {
		return $html . '<p class="artist-dispatch-empty">' . esc_html__( 'Nothing here yet.', 'extrachill-blog' ) . '</p></section>';
	}
	$html .= '<ul>';
	foreach ( $posts as $post ) {
		$title_text = $post->post_title ? $post->post_title : __( 'Untitled Artist Dispatch', 'extrachill-blog' );
		$url        = $editable ? add_query_arg( 'post', $post->ID, home_url( '/submit/write/' ) ) : ( 'publish' === $post->post_status ? get_permalink( $post ) : '' );
		$html      .= '<li><div><strong>' . esc_html( $title_text ) . '</strong><span>' . esc_html( get_the_modified_date( '', $post ) ) . '</span></div>';
		if ( $url ) {
			$html .= '<a href="' . esc_url( $url ) . '">' . esc_html( $editable ? __( 'Resume', 'extrachill-blog' ) : __( 'View', 'extrachill-blog' ) ) . '</a>';
		}
		$html .= '</li>';
	}
	return $html . '</ul></section>';
}

/**
 * Render the approved dashboard.
 *
 * @param array $access Access state.
 * @return string HTML.
 */
function extrachill_blog_dispatch_dashboard_html( $access ) {
	$groups    = extrachill_blog_dispatch_dashboard_posts();
	$artist_id = absint( isset( $access['artist_id'] ) ? $access['artist_id'] : 0 );
	return '<section class="artist-dispatch-dashboard ec-surface-card ec-mobile-full-width-panel"><div class="artist-dispatch-dashboard__head"><div><p class="artist-dispatch-kicker">' . esc_html__( 'Approved contributor', 'extrachill-blog' ) . '</p><h2>' . esc_html__( 'Your Artist Dispatches', 'extrachill-blog' ) . '</h2></div><button id="artist-dispatch-new" class="button-1 button-medium" type="button" data-artist="' . esc_attr( $artist_id ) . '">' . esc_html__( 'New Artist Dispatch', 'extrachill-blog' ) . '</button></div><p id="artist-dispatch-new-message" class="artist-dispatch-message" aria-live="polite"></p><div class="artist-dispatch-groups">' . extrachill_blog_dispatch_group_html( __( 'Drafts', 'extrachill-blog' ), $groups['draft'], true ) . extrachill_blog_dispatch_group_html( __( 'Awaiting Review', 'extrachill-blog' ), $groups['pending'] ) . extrachill_blog_dispatch_group_html( __( 'Published', 'extrachill-blog' ), $groups['publish'] ) . '</div></section>';
}

/**
 * Render current-user cohort state.
 *
 * @return string HTML.
 */
function extrachill_blog_dispatch_state_html() {
	if ( ! is_user_logged_in() ) {
		return extrachill_blog_dispatch_logged_out_html();
	}
	$access = extrachill_blog_dispatch_access();
	if ( is_wp_error( $access ) || empty( $access['eligibility']['policy']['pilot_enabled'] ) ) {
		return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel is-closed"><h2>' . esc_html__( 'Artist Dispatch is taking a break', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'We are not accepting new requests right now. You can still use the rest of Extra Chill as usual.', 'extrachill-blog' ) . '</p></section>';
	}

	switch ( $access['status'] ) {
		case 'approved':
			if ( ! extrachill_blog_dispatch_is_approved( $access ) ) {
				return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel is-closed"><h2>' . esc_html__( 'We could not open your writing tools', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Your approval is safe. An Extra Chill editor needs to fix your access before you can start writing.', 'extrachill-blog' ) . '</p></section>';
			}
			if ( ! extrachill_blog_dispatch_has_current_terms( $access ) ) {
				return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel is-closed"><h2>' . esc_html__( 'Please review the updated guidelines', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'The Artist Dispatch guidelines have changed since you were approved. Accept the current version before you start your next story.', 'extrachill-blog' ) . '</p></section>';
			}
			if ( ! extrachill_blog_dispatch_has_editor_dependency() ) {
				return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel is-closed"><h2>' . esc_html__( 'The writing tool is temporarily unavailable', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Please try again later. Your approval and existing stories are safe.', 'extrachill-blog' ) . '</p></section>';
			}
			return extrachill_blog_dispatch_dashboard_html( $access );
		case 'pending':
			return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel"><h2>' . esc_html__( 'We are taking a look', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'An Extra Chill editor is reviewing your artist connection and story idea. We will let you know when a decision is made.', 'extrachill-blog' ) . '</p></section>';
		case 'rejected':
			return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel"><h2>' . esc_html__( 'Not this time', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Keep taking part in the community and working on the story you want to tell. The checklist below will show when you are ready to request access again.', 'extrachill-blog' ) . '</p>' . extrachill_blog_dispatch_criteria_html( isset( $access['eligibility'] ) ? $access['eligibility'] : array() ) . '</section>';
		case 'revoked':
		case 'moderated':
			return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel is-closed"><h2>' . esc_html__( 'Artist Dispatch is not available for this account', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'You cannot submit a new story right now. Anything already published will stay online.', 'extrachill-blog' ) . '</p></section>';
		default:
			$eligibility = isset( $access['eligibility'] ) ? $access['eligibility'] : array();
			if ( ! empty( $eligibility['eligible'] ) ) {
				return extrachill_blog_dispatch_request_html( $access );
			}
			return '<section class="artist-dispatch-state ec-surface-card ec-mobile-full-width-panel"><h2>' . esc_html__( 'Almost there', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Complete the steps below to request Artist Dispatch access. Your community activity helps us get to know you before we hand over the keys to the writing tools.', 'extrachill-blog' ) . '</p>' . extrachill_blog_dispatch_criteria_html( $eligibility ) . '</section>';
	}
}

/**
 * Render the complete guidelines default.
 *
 * @return string HTML.
 */
function extrachill_blog_dispatch_guidelines_html() {
	return '<div class="artist-dispatch-guidelines"><header><p class="artist-dispatch-kicker">' . esc_html__( 'Artist Dispatch', 'extrachill-blog' ) . '</p><h1>' . esc_html__( 'A few things to know', 'extrachill-blog' ) . '</h1><p>' . esc_html__( 'These guidelines help you tell a strong story and make sure everyone involved is treated fairly.', 'extrachill-blog' ) . '</p></header>' .
	'<section><h2>' . esc_html__( 'Write what you know', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Tell a story you actually lived: making a release, writing a song, recording in the studio, going on tour, building a scene, or learning something the hard way. Give readers details they would not get from a press release. Do not review your own music or pretend to be an outside reporter.', 'extrachill-blog' ) . '</p></section>' .
	'<section><h2>' . esc_html__( 'Get the details right', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Double-check names, dates, credits, quotes, and other facts. Ask permission before sharing a private conversation. If you later spot a mistake, tell us so we can fix it.', 'extrachill-blog' ) . '</p></section>' .
	'<section><h2>' . esc_html__( 'Send us your own work', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Your story should be original. Tell us if any part has appeared somewhere else. Do not copy another writer, invent experiences or quotes, or reuse someone else’s work without credit.', 'extrachill-blog' ) . '</p></section>' .
	'<section><h2>' . esc_html__( 'Be open about your connection', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Tell readers how you are connected to the artist or project. Let us know about labels, managers, venues, sponsors, payment, free tickets, travel, gifts, or personal relationships that matter to the story. We will add a short note to every published Dispatch so readers understand where you are coming from.', 'extrachill-blog' ) . '</p></section>' .
	'<section><h2>' . esc_html__( 'Only share media you can use', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'The writing tool is text-first and does not accept uploads yet. If an editor asks for a photo or other media, send only work you own or have permission to use, along with the creator’s name. Tell us if an image was generated with AI.', 'extrachill-blog' ) . '</p></section>' .
	'<section><h2>' . esc_html__( 'AI can help, but the story must be yours', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'You may use AI to organize notes, transcribe a conversation, or clean up a draft. You are still responsible for every fact and quote. Never use it to invent something you did, heard, saw, or were told, and tell your editor if AI played a major role in the piece.', 'extrachill-blog' ) . '</p></section>' .
	'<section><h2>' . esc_html__( 'An editor works with every story', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'Sending a story does not guarantee that it will be published. We may ask questions, suggest changes, fix wording, hold a piece, or decide it is not right for Extra Chill. You can have up to three drafts and one story waiting for review at a time.', 'extrachill-blog' ) . '</p></section>' .
	'<section><h2>' . esc_html__( 'Your work stays yours', 'extrachill-blog' ) . '</h2><p>' . esc_html__( 'You keep the copyright. If we accept your story, you give Extra Chill permission to edit, publish, archive, share, and promote it. Artist Dispatch does not promise payment, and it is never paid placement. Any separate written agreement with Extra Chill takes priority over these guidelines.', 'extrachill-blog' ) . '</p></section></div>';
}

/**
 * Render the secure canonical post editor host.
 *
 * @return string HTML.
 */
function extrachill_blog_dispatch_editor_html() {
	$post = extrachill_blog_dispatch_editor_post();
	if ( ! $post instanceof WP_Post ) {
		return '';
	}
	return '<div class="artist-dispatch-write"><div class="artist-dispatch-write__intro"><a href="' . esc_url( home_url( '/submit/' ) ) . '">← ' . esc_html__( 'Your Dispatches', 'extrachill-blog' ) . '</a><p>' . esc_html__( 'Write at your own pace, preview the finished page, and send it to an editor when you are ready.', 'extrachill-blog' ) . '</p></div><div class="artist-dispatch-editor ec-surface-card ec-mobile-full-width-panel"><textarea id="artist-dispatch-content" class="wp-editor-area">' . esc_textarea( $post->post_content ) . '</textarea></div></div>';
}

/**
 * Replace native page shells with dynamic submission content.
 *
 * @param string $content Stored page content.
 * @return string Rendered content.
 */
function extrachill_blog_dispatch_page_content( $content ) {
	if ( ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	if ( extrachill_blog_dispatch_is_page( 'submit' ) ) {
		return '<main class="artist-dispatch-page">' . extrachill_blog_dispatch_intro_html() . extrachill_blog_dispatch_state_html() . '</main>';
	}
	if ( extrachill_blog_dispatch_is_page( 'submit/guidelines' ) ) {
		return extrachill_blog_dispatch_guidelines_html();
	}
	if ( extrachill_blog_dispatch_is_page( 'submit/write' ) ) {
		return extrachill_blog_dispatch_editor_html();
	}
	return $content;
}
add_filter( 'the_content', 'extrachill_blog_dispatch_page_content' );

/**
 * Hide native page titles because each surface supplies its own heading.
 *
 * @param bool $show Whether to show the title.
 * @return bool Filtered value.
 */
function extrachill_blog_dispatch_hide_page_title( $show ) {
	return extrachill_blog_dispatch_is_page( 'submit' ) || extrachill_blog_dispatch_is_page( 'submit/guidelines' ) || extrachill_blog_dispatch_is_page( 'submit/write' ) ? false : $show;
}
add_filter( 'extrachill_show_page_title', 'extrachill_blog_dispatch_hide_page_title' );
