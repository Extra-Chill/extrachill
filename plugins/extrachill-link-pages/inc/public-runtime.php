<?php
/**
 * Public routing, rendering lifecycle, SEO, and cache projection.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

defined( 'EC_LINK_PAGES_REWRITE_VERSION' ) || define( 'EC_LINK_PAGES_REWRITE_VERSION', '20260823' );

/** Whether the current request uses the public Link Page host. */
function ec_is_link_page_public_host( $host = null ) {
	$host = null === $host ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) : $host;
	$host = strtolower( preg_replace( '/:\d+$/', '', (string) $host ) );
	return in_array( $host, array( 'extrachill.link', 'www.extrachill.link' ), true );
}

/** Return one canonical public URL. */
function ec_get_link_page_public_url( $link_page_id ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return '';
	}
	if ( $storage_blog_id && get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id ) {
				return ec_get_link_page_public_url( $link_page_id );
			}
		);
	}
	if ( EC_LINK_PAGE_POST_TYPE !== get_post_type( $link_page_id ) ) {
		return '';
	}
	$slug = get_post_field( 'post_name', $link_page_id );
	return $slug ? 'https://extrachill.link/' . rawurlencode( $slug ) . '/' : '';
}

/** Return the owner-configured public query variable. */
function ec_get_link_page_public_query_var() {
	$query_var = apply_filters( 'ec_link_page_public_query_var', 'link_page' );
	return is_string( $query_var ) && preg_match( '/^[a-z0-9_]+$/', $query_var ) ? $query_var : 'link_page';
}

/** Return owner-configured public query variables. */
function ec_get_link_page_public_query_vars() {
	$vars = apply_filters( 'ec_link_page_public_query_vars', array( ec_get_link_page_public_query_var() ) );
	return array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $vars ) ? $vars : array() ) ) ) );
}

/** Return owner-configured public route exclusions. */
function ec_get_link_page_public_exclusions() {
	$excluded = apply_filters( 'ec_link_page_public_exclusions', array( 'wp-login', 'wp-admin', 'admin' ) );
	return array_values( array_unique( array_filter( array_map( 'sanitize_title', is_array( $excluded ) ? $excluded : array() ) ) ) );
}

/** Return every public cache key for one page. */
function ec_link_page_public_urls( $link_page_id ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return array();
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		$result = ec_with_link_page_storage_blog(
			static function () use ( $link_page_id ) {
				return ec_link_page_public_urls( $link_page_id );
			}
		);
		return is_wp_error( $result ) ? array() : $result;
	}
	$url = ec_get_link_page_public_url( $link_page_id );
	if ( ! $url ) {
		return array();
	}
	$urls = array( $url );
	if ( 'extra-chill' === get_post_field( 'post_name', $link_page_id ) ) {
		$urls[] = 'https://extrachill.link/';
	}
	return $urls;
}

/** Add host-owned URLs to Extra Chill Cache targeted invalidation. */
function ec_link_page_cache_post_change_urls( $urls, $post_id, $post_type ) {
	return EC_LINK_PAGE_POST_TYPE === $post_type ? ec_link_page_public_urls( $post_id ) : $urls;
}

/** Register the historical query variable and host-only catch-all. */
function ec_register_link_page_public_rewrites() {
	$query_var = ec_get_link_page_public_query_var();
	add_rewrite_tag( '%' . $query_var . '%', '([^&]+)' );
	if ( ! ec_is_link_page_public_host() ) {
		return;
	}
	$excluded = ec_get_link_page_public_exclusions();
	foreach ( get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	) as $page_id ) {
		$slug = get_post_field( 'post_name', $page_id );
		if ( $slug ) {
			$excluded[] = preg_quote( $slug, '/' );
		}
	}
	add_rewrite_rule(
		'^(?!' . implode(
			'|',
			array_map(
				static function ( $slug ) {
					return preg_quote( $slug, '/' );
				},
				array_unique( $excluded )
			)
		) . ')([^/]+)/?$',
		'index.php?' . $query_var . '=$matches[1]',
		'top'
	);
}

/** Preserve public query variables. */
function ec_add_link_page_public_query_vars( $vars ) {
	foreach ( ec_get_link_page_public_query_vars() as $var ) {
		$vars[] = $var;
	}
	return array_values( array_unique( $vars ) );
}

/** Terminate successful redirect processing in production. */
function ec_terminate_link_page_request( $url, $status ) {
	if ( apply_filters( 'ec_link_page_terminate_request', true, $url, $status ) ) {
		exit;
	}
}

/** Version-gated soft rewrite flush. */
function ec_maybe_flush_link_page_public_rewrites() {
	if ( EC_LINK_PAGES_REWRITE_VERSION === get_option( 'ec_link_pages_rewrite_version' ) ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'ec_link_pages_rewrite_version', EC_LINK_PAGES_REWRITE_VERSION );
}

/** Disable core canonical guessing on the public host. */
function ec_prevent_link_page_public_canonical_redirect( $redirect_url, $requested_url ) {
	unset( $requested_url );
	$host = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ?? ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
	return ec_is_link_page_public_host( $host ) ? false : $redirect_url;
}

/** Send a public redirect without terminating testable control flow. */
function ec_link_page_public_redirect( $url, $status = 301, $safe = false ) {
	$url    = esc_url_raw( $url, array( 'http', 'https' ) );
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( ! $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return new WP_Error( 'invalid_link_page_redirect_url', 'The Link Page redirect target must use HTTP or HTTPS.' );
	}
	if ( $safe ) {
		$redirected = wp_safe_redirect( $url, $status );
	} else {
		$redirected = wp_redirect( $url, $status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Owner projections may intentionally target an external URL.
	}
	if ( ! $redirected ) {
		return new WP_Error( 'link_page_redirect_failed', 'The Link Page redirect headers could not be sent.' );
	}
	ec_terminate_link_page_request( $url, $status );
	return true;
}

/** Resolve the host path into the main query before HEAD exits. */
function ec_resolve_link_page_public_query() {
	if ( ( defined( 'EXTRCH_LINKPAGE_DEV' ) && EXTRCH_LINKPAGE_DEV ) || ! ec_is_link_page_public_host() ) {
		return;
	}
	global $wp_query;
	$path    = trim( (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
	$special = apply_filters( 'ec_link_page_public_special_route', null, $path );
	if ( is_array( $special ) && ! empty( $special['url'] ) ) {
		$redirected = ec_link_page_public_redirect( $special['url'], isset( $special['status'] ) ? (int) $special['status'] : 302, ! empty( $special['safe'] ) );
		if ( is_wp_error( $redirected ) ) {
			status_header( 500 );
		}
		return;
	}
	$root = '' === $path || 'extra-chill' === $path;
	if ( 'extra-chill' === $path ) {
		$redirected = ec_link_page_public_redirect( 'https://extrachill.link/', 301 );
		if ( is_wp_error( $redirected ) ) {
			status_header( 500 );
		}
		return;
	}
	$slug     = $root ? 'extra-chill' : $path;
	$resolved = ec_with_link_page_storage_blog(
		static function () use ( $slug ) {
			$ids = get_posts(
				array(
					'name'        => $slug,
					'post_type'   => EC_LINK_PAGE_POST_TYPE,
					'post_status' => 'publish',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			return empty( $ids ) ? null : get_post( $ids[0] );
		}
	);
	if ( is_wp_error( $resolved ) ) {
		status_header( 500 );
		return;
	}
	if ( ! $resolved ) {
		if ( $root ) {
			status_header( 404 );
		} else {
			$redirected = ec_link_page_public_redirect( 'https://extrachill.link/', 301 );
			if ( is_wp_error( $redirected ) ) {
				status_header( 500 );
			}
		}
		return;
	}
	$post                              = $resolved;
	$wp_query->posts                   = array( $post );
	$wp_query->post_count              = 1;
	$wp_query->found_posts             = 1;
	$wp_query->max_num_pages           = 1;
	$wp_query->is_single               = true;
	$wp_query->is_singular             = true;
	$wp_query->is_404                  = false;
	$wp_query->query_vars['name']      = $slug;
	$wp_query->query_vars['post_type'] = EC_LINK_PAGE_POST_TYPE;
	$wp_query->queried_object_id       = (int) $post->ID;
	$wp_query->queried_object          = $post;
	status_header( 200 );
	$data = ec_read_link_page_persistence( $post->ID );
	if ( ! is_wp_error( $data ) && ! empty( $data['settings']['redirect_enabled'] ) ) {
		$redirected = ec_link_page_public_redirect( $data['settings']['redirect_target_url'], 302 );
		if ( is_wp_error( $redirected ) ) {
			status_header( 500 );
		}
	}
}

/** Use the standalone shell for host routes and direct CPT requests. */
function ec_link_page_public_template( $template ) {
	global $wp_query;
	$resolved = ! empty( $wp_query->posts[0] ) && EC_LINK_PAGE_POST_TYPE === get_post_type( $wp_query->posts[0] );
	if ( $resolved && ( ec_is_link_page_public_host() || is_singular( EC_LINK_PAGE_POST_TYPE ) ) ) {
		return EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'templates/single-link-page.php';
	}
	return $template;
}

/** Redirect direct CPT requests to the public host or a configured temporary target. */
function ec_redirect_direct_link_page_request() {
	if ( ! is_singular( EC_LINK_PAGE_POST_TYPE ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post || empty( $post->ID ) ) {
		return;
	}
	$data = ec_read_link_page_persistence( $post->ID );
	if ( ! is_wp_error( $data ) && ! empty( $data['settings']['redirect_enabled'] ) ) {
		$redirected = ec_link_page_public_redirect( $data['settings']['redirect_target_url'], 302 );
		if ( is_wp_error( $redirected ) ) {
			status_header( 500 );
		}
		return;
	}
	if ( ! ec_is_link_page_public_host() && ! ( defined( 'EXTRCH_LINKPAGE_DEV' ) && EXTRCH_LINKPAGE_DEV ) ) {
		$url = ec_get_link_page_public_url( $post->ID );
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$url .= '?' . sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
		}
		$redirected = ec_link_page_public_redirect( $url, 301, true );
		if ( is_wp_error( $redirected ) ) {
			status_header( 500 );
		}
	}
}

/** Enqueue generic assets through the historical minimal-head hook. */
function ec_enqueue_link_page_minimal_assets( $link_page_id, $owner = null ) {
	unset( $link_page_id, $owner );
	$styles = array(
		'extrch-link-page'           => 'assets/css/extrch-links.css',
		'extrch-share-modal'         => 'assets/css/extrch-share-modal.css',
		'extrch-custom-social-icons' => 'assets/css/custom-social-icons.css',
	);
	foreach ( $styles as $handle => $path ) {
		$file = EXTRACHILL_LINK_PAGES_PLUGIN_DIR . $path;
		wp_enqueue_style( $handle, plugins_url( $path, EXTRACHILL_LINK_PAGES_PLUGIN_FILE ), array(), file_exists( $file ) ? filemtime( $file ) : EXTRACHILL_LINK_PAGES_VERSION );
	}
	wp_enqueue_style( 'extrch-font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css', array(), '6.7.2' );
	foreach ( array(
		'extrch-share-modal'       => 'assets/js/extrch-share-modal.js',
		'extrch-public-tracking'   => 'assets/js/link-page-public-tracking.js',
		'extrch-link-page-youtube' => 'assets/js/link-page-youtube-embed.js',
	) as $handle => $path ) {
		$file = EXTRACHILL_LINK_PAGES_PLUGIN_DIR . $path;
		wp_enqueue_script( $handle, plugins_url( $path, EXTRACHILL_LINK_PAGES_PLUGIN_FILE ), array(), file_exists( $file ) ? filemtime( $file ) : EXTRACHILL_LINK_PAGES_VERSION, true );
	}
}

/** Generate the historical CSS variable block. */
function ec_link_page_css_variables_style_block( $css_vars, $element_id = 'link-page-custom-vars' ) {
	if ( ! is_array( $css_vars ) || empty( $css_vars ) ) {
		return '';
	}
	$output = '<style id="' . esc_attr( $element_id ) . '">:root {';
	foreach ( $css_vars as $key => $value ) {
		if ( null !== $value && false !== $value ) {
			$output .= esc_html( $key ) . ':' . esc_html( $value ) . ';';
		}
	}
	return $output . '}</style>';
}

/** Render one section with the historical DOM contract. */
function ec_render_link_page_section( $section, $link_page_id, $youtube_enabled ) {
	if ( ! is_array( $section ) ) {
		return '';
	}
	ob_start();
	if ( ! empty( $section['section_title'] ) ) {
		echo '<div class="extrch-link-page-section-title">' . esc_html( $section['section_title'] ) . '</div>';
	}
	echo '<div class="extrch-link-page-links">';
	foreach ( $section['links'] ?? array() as $link ) {
		if ( empty( $link['link_url'] ) || empty( $link['link_text'] ) ) {
			continue;
		}
		$youtube = $youtube_enabled && ( false !== strpos( $link['link_url'], 'youtube.com' ) || false !== strpos( $link['link_url'], 'youtu.be' ) );
		require EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'templates/components/single-link.php';
	}
	echo '</div>';
	return ob_get_clean();
}

/** Render the minimal public head. */
function ec_render_link_page_public_head( $link_page_id, $data, $projection ) {
	echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<title>' . esc_html( $projection['display_title'] ) . ' | extrachill.link</title>';
	$seo             = array_merge(
		array(
			'title'       => $projection['display_title'] . ' | extrachill.link',
			'description' => $projection['bio'],
			'canonical'   => ec_get_link_page_public_url( $link_page_id ),
			'image'       => $projection['profile_img_url'],
			'image_alt'   => $projection['display_title'],
			'og_type'     => 'profile',
		),
		$projection['seo']
	);
	$schema_override = null;
	if ( ! empty( $seo['schema'] ) && is_array( $seo['schema'] ) && function_exists( 'add_filter' ) && function_exists( 'remove_filter' ) ) {
		$schema_override = static function () use ( $seo ) {
			return $seo['schema'];
		};
		add_filter( 'extrachill_seo_schema_graph', $schema_override, PHP_INT_MAX );
	}
	if ( function_exists( 'ExtraChill\SEO\Core\ec_seo_render_head' ) ) {
		\ExtraChill\SEO\Core\ec_seo_render_head( $seo );
	} else {
		$description = $seo['description'] ? $seo['description'] : 'All important links in one place.';
		echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $description ) ) . '">';
		echo '<link rel="canonical" href="' . esc_url( $seo['canonical'] ) . '">';
	}
	if ( $schema_override ) {
		remove_filter( 'extrachill_seo_schema_graph', $schema_override, PHP_INT_MAX );
	} elseif ( ! empty( $seo['schema'] ) && is_array( $seo['schema'] ) ) {
		echo '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $seo['schema'],
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . '</script>';
	}
	$icon = get_site_icon_url( 32 );
	if ( $icon ) {
		echo '<link rel="icon" href="' . esc_url( $icon ) . '" sizes="32x32" />';
		echo '<link rel="icon" href="' . esc_url( $icon ) . '" sizes="192x192" />';
		echo '<link rel="apple-touch-icon" href="' . esc_url( $icon ) . '">';
	}
	echo ec_link_page_css_variables_style_block( $data['css_vars'], 'extrch-link-page-custom-vars' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by generator.
	echo $projection['_rendered_components']['head']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output.
	$legacy_arguments = ! empty( $projection['legacy_head_arguments'] ) ? $projection['legacy_head_arguments'] : array( $projection['_context']['owner'] );
	do_action( 'extrachill_artist_link_page_minimal_head', $link_page_id, ...$legacy_arguments ); // Historical hook name.
	wp_print_styles();
	$meta_id = $data['settings']['meta_pixel_id'];
	if ( $meta_id && ctype_digit( (string) $meta_id ) ) {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Per-page tracking ID must be emitted in the isolated minimal head.
		echo '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init","' . esc_js( $meta_id ) . '");fbq("track","PageView");</script>';
		echo '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=' . esc_attr( $meta_id ) . '&ev=PageView&noscript=1"></noscript>';
	}
	$tag_id = $data['settings']['google_tag_id'];
	if ( $tag_id && preg_match( '/^(G|AW)-[a-zA-Z0-9]+$/', $tag_id ) ) {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Per-page tracking ID must be emitted in the isolated minimal head.
		echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $tag_id ) . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config","' . esc_js( $tag_id ) . '");</script>';
	}
}

/** Add all published local records to the SEO sitemap. */
function ec_link_page_sitemap_urls( $urls ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return $urls;
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		$result = ec_with_link_page_storage_blog(
			static function () use ( $urls ) {
				return ec_link_page_sitemap_urls( $urls );
			}
		);
		return is_wp_error( $result ) ? $urls : $result;
	}
	foreach ( get_posts(
		array(
			'post_type'      => EC_LINK_PAGE_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	) as $page_id ) {
		$urls[] = array(
			'loc'     => ec_get_link_page_public_url( $page_id ),
			'lastmod' => get_the_modified_date( 'c', $page_id ),
		);
	}
	return $urls;
}

add_action( 'init', 'ec_register_link_page_public_rewrites', 25 );
add_action( 'init', 'ec_maybe_flush_link_page_public_rewrites', 30 );
add_action( 'template_redirect', 'ec_resolve_link_page_public_query', 5 );
add_action( 'template_redirect', 'ec_redirect_direct_link_page_request', 10 );
add_action( 'extrachill_artist_link_page_minimal_head', 'ec_enqueue_link_page_minimal_assets', 10, 2 ); // Historical hook name.
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'query_vars', 'ec_add_link_page_public_query_vars' );
	add_filter( 'redirect_canonical', 'ec_prevent_link_page_public_canonical_redirect', 10, 2 );
	add_filter( 'template_include', 'ec_link_page_public_template' );
	add_filter( 'extrachill_seo_sitemap_urls', 'ec_link_page_sitemap_urls' );
	add_filter( 'extrachill_cache_post_change_urls', 'ec_link_page_cache_post_change_urls', 10, 3 );
}
