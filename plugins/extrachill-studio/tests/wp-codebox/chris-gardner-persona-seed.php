<?php
/**
 * Fixture runtime for the Studio social-manager WP Codebox persona.
 *
 * The harness loads Studio's real production browser bundle while replacing
 * external social and analytics services with deterministic local REST routes.
 * No request can reach a live social account.
 */

defined( 'ABSPATH' ) || exit;

$studio_dir        = WP_PLUGIN_DIR . '/extrachill-studio';
$studio_build_dir  = $studio_dir . '/build/blocks/studio';
$studio_view_js    = $studio_build_dir . '/view.js';
$studio_asset_file = $studio_build_dir . '/view.asset.php';

if ( ! file_exists( $studio_view_js ) || ! file_exists( $studio_asset_file ) ) {
	throw new RuntimeException( 'Studio build assets are missing. Run `npm run build` before this recipe.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

global $wpdb;

$fixture_users = array(
	101 => array(
		'login'        => 'studio-social-manager',
		'display_name' => 'Studio Social Manager',
		'role'         => 'editor',
	),
	102 => array(
		'login'        => 'studio-team-contributor',
		'display_name' => 'Studio Team Contributor',
		'role'         => 'author',
	),
);

foreach ( $fixture_users as $user_id => $fixture_user ) {
	if ( ! get_user_by( 'id', $user_id ) ) {
		$inserted = $wpdb->insert(
			$wpdb->users,
			array(
				'ID'              => $user_id,
				'user_login'      => $fixture_user['login'],
				'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
				'user_nicename'   => $fixture_user['login'],
				'user_email'      => $fixture_user['login'] . '@example.test',
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $fixture_user['display_name'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Unable to create Studio persona fixture user ' . $user_id . '.' );
		}
		clean_user_cache( $user_id );
	}

	$user = new WP_User( $user_id );
	$user->set_role( $fixture_user['role'] );
}

$upload_dir = wp_upload_dir();
$image_path = trailingslashit( $upload_dir['path'] ) . 'studio-persona-festival-crowd.png';
$image      = imagecreatetruecolor( 1200, 800 );
$navy       = imagecolorallocate( $image, 20, 29, 48 );
$purple     = imagecolorallocate( $image, 111, 45, 189 );
$white      = imagecolorallocate( $image, 245, 245, 245 );
imagefill( $image, 0, 0, $navy );
imagefilledrectangle( $image, 0, 520, 1200, 800, $purple );
imagestring( $image, 5, 430, 360, 'EXTRA CHILL LIVE', $white );
imagepng( $image, $image_path );
imagedestroy( $image );

$attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'Festival crowd',
		'post_status'    => 'inherit',
	),
	$image_path
);

if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
	throw new RuntimeException( 'Unable to seed the Studio persona media fixture.' );
}

wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $image_path ) );
$image_url = wp_get_attachment_url( $attachment_id );

$asset = require $studio_asset_file;
$state = array(
	'asset_url'     => plugins_url( 'extrachill-studio/build/blocks/studio/view.js' ),
	'asset_css'     => plugins_url( 'extrachill-studio/build/blocks/studio/view.css' ),
	'style_css'     => plugins_url( 'extrachill-studio/build/blocks/studio/style.css' ),
	'dependencies'  => array_values(
		array_unique(
			array_merge(
				isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array( 'wp-api-fetch', 'wp-element', 'wp-i18n' ),
				array( 'wp-core-data' )
			)
		)
	),
	'version'       => isset( $asset['version'] ) ? (string) $asset['version'] : '0',
	'attachment_id' => (int) $attachment_id,
	'image_url'     => $image_url,
);

$state_export = var_export( $state, true );
$harness      = <<<'PHP'
<?php
/**
 * Plugin Name: Studio Social Manager Persona Harness
 * Description: Deterministic local services for the WP Codebox Studio persona.
 */

$studio_persona = __STATE__;

add_action( 'init', static function () {
	register_post_meta(
		'post',
		'_studio_social_platforms',
		array(
			'type'         => 'array',
			'single'       => true,
			'show_in_rest' => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		)
	);
	foreach ( array( '_studio_social_caption', '_studio_social_media_kind' ) as $key ) {
		register_post_meta( 'post', $key, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
	}
	register_post_meta(
		'post',
		'_studio_social_images',
		array(
			'type'         => 'array',
			'single'       => true,
			'show_in_rest' => array( 'schema' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ),
		)
	);
} );

$studio_persona_permission = static function () {
	return current_user_can( 'edit_posts' );
};

add_filter(
	'rest_pre_dispatch',
	static function ( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( 'POST' !== $request->get_method() || '/wp/v2/posts' !== $request->get_route() ) {
			return $result;
		}

		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) || empty( $meta['_studio_social_platforms'] ) ) {
			return $result;
		}

		update_option( 'studio_persona_last_social_draft', $request->get_json_params(), false );
		return new WP_REST_Response( array( 'id' => 7101 ), 201 );
	},
	10,
	3
);

add_action( 'rest_api_init', static function () use ( &$studio_persona, $studio_persona_permission ) {
	register_rest_route(
		'datamachine/v1',
		'/socials/platforms',
		array(
			'methods'             => 'GET',
			'permission_callback' => $studio_persona_permission,
			'callback'            => static function () {
				$publish = array( array( 'slug' => 'publish', 'label' => 'Publish' ) );
				$platforms = array(
					array( 'slug' => 'instagram', 'label' => 'Instagram', 'username' => 'extrachill', 'capabilities' => array_merge( $publish, array( array( 'slug' => 'comments', 'label' => 'Comments' ), array( 'slug' => 'giveaway', 'label' => 'Giveaway' ) ) ), 'maxImages' => 10, 'charLimit' => 2200, 'supportsCarousel' => true, 'supportsVideo' => true, 'supportedMediaKinds' => array( 'image', 'carousel', 'reel', 'story' ) ),
					array( 'slug' => 'facebook', 'label' => 'Facebook', 'username' => 'Extra Chill', 'capabilities' => array_merge( $publish, array( array( 'slug' => 'comments', 'label' => 'Comments' ) ) ), 'maxImages' => 10, 'charLimit' => 63206, 'supportsCarousel' => true, 'supportedMediaKinds' => array( 'image', 'carousel' ) ),
					array( 'slug' => 'twitter', 'label' => 'X / Twitter', 'username' => 'extra_chill', 'capabilities' => $publish, 'maxImages' => 4, 'charLimit' => 280, 'supportsCarousel' => true, 'supportedMediaKinds' => array( 'image', 'carousel' ) ),
					array( 'slug' => 'bluesky', 'label' => 'Bluesky', 'username' => 'extrachill.com', 'capabilities' => $publish, 'maxImages' => 4, 'charLimit' => 300, 'supportedMediaKinds' => array( 'image' ) ),
					array( 'slug' => 'threads', 'label' => 'Threads', 'username' => 'extrachill', 'capabilities' => $publish, 'maxImages' => 10, 'charLimit' => 500, 'supportsCarousel' => true, 'supportedMediaKinds' => array( 'image', 'carousel' ) ),
					array( 'slug' => 'pinterest', 'label' => 'Pinterest', 'username' => 'Extra Chill', 'capabilities' => $publish, 'maxImages' => 1, 'charLimit' => 500, 'supportedMediaKinds' => array( 'image' ) ),
					array( 'slug' => 'linkedin', 'label' => 'LinkedIn', 'username' => 'Extra Chill', 'capabilities' => $publish, 'maxImages' => 9, 'charLimit' => 3000, 'supportedMediaKinds' => array( 'image' ) ),
					array( 'slug' => 'mastodon', 'label' => 'Mastodon', 'username' => '@extrachill@music.example', 'capabilities' => $publish, 'maxImages' => 4, 'charLimit' => 500, 'supportedMediaKinds' => array( 'image' ) ),
					array( 'slug' => 'tumblr', 'label' => 'Tumblr', 'username' => 'extrachill', 'capabilities' => $publish, 'maxImages' => 0, 'charLimit' => 0, 'supportedMediaKinds' => array( 'text' ) ),
					array( 'slug' => 'tiktok', 'label' => 'TikTok', 'username' => 'extrachill', 'capabilities' => $publish, 'maxImages' => 0, 'charLimit' => 2200, 'supportsVideo' => true, 'supportedMediaKinds' => array( 'video' ) ),
					array( 'slug' => 'youtube', 'label' => 'YouTube', 'username' => 'Extra Chill', 'capabilities' => $publish, 'maxImages' => 0, 'charLimit' => 5000, 'supportsVideo' => true, 'supportedMediaKinds' => array( 'video' ) ),
				);

				foreach ( $platforms as &$platform ) {
					$platform['type']          = 'publish';
					$platform['authenticated'] = true;
				}
				unset( $platform );

				return array( 'platforms' => $platforms );
			},
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/network-media',
		array(
			'methods'             => 'GET',
			'permission_callback' => $studio_persona_permission,
			'callback'            => static function () use ( &$studio_persona ) {
				return array(
					'items'       => array(
						array(
							'id'         => $studio_persona['attachment_id'],
							'blog_id'    => 1,
							'sourceId'   => '1:' . $studio_persona['attachment_id'],
							'url'        => $studio_persona['image_url'],
							'previewUrl' => $studio_persona['image_url'],
							'title'      => 'Festival crowd',
							'alt'        => 'A packed crowd watching a local concert',
							'caption'    => 'Extra Chill live coverage',
							'mime_type'  => 'image/png',
							'media_type' => 'image',
							'date'       => gmdate( 'c' ),
							'width'      => 1200,
							'height'     => 800,
						),
					),
					'total'       => 1,
					'total_pages' => 1,
					'page'        => 1,
					'per_page'    => 24,
				);
			},
		)
	);

	register_rest_route(
		'datamachine/v1',
		'/socials/post',
		array(
			'methods'             => 'POST',
			'permission_callback' => $studio_persona_permission,
			'callback'            => static function () {
				return array( 'success' => true, 'job_id' => 7001, 'status' => 'pending' );
			},
		)
	);

	register_rest_route(
		'datamachine/v1',
		'/socials/jobs/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'permission_callback' => $studio_persona_permission,
			'callback'            => static function () {
				return array(
					'success' => true,
					'jobs'    => array(
						array(
							'job_id'      => 7001,
							'user_id'     => get_current_user_id(),
							'status'      => 'completed',
							'created_at'  => gmdate( 'c' ),
							'engine_data' => array(
								'results' => array(
									array(
										'platform'         => 'instagram',
										'success'          => true,
										'platform_post_id' => 'fixture-post-7001',
										'platform_url'     => home_url( '/studio-persona/social-result/' ),
									),
								),
							),
						),
					),
					'total'   => 1,
				);
			},
		)
	);

	register_rest_route(
		'datamachine/v1',
		'/socials/comments/(?P<platform>[a-z0-9_-]+)',
		array(
			'methods'             => 'GET',
			'permission_callback' => $studio_persona_permission,
			'callback'            => static function ( WP_REST_Request $request ) {
				return array(
					'success' => true,
					'data'    => array(
						'comments' => array(
							array(
								'id'              => 'fixture-comment-1',
								'platform'        => $request['platform'],
								'author_username' => 'charlestonmusicfan',
								'text'            => 'Who is playing first?',
								'timestamp'       => gmdate( 'c' ),
								'like_count'      => 2,
								'reply_count'     => 0,
								'mentions'        => array(),
								'parent_id'       => null,
								'raw'             => array(),
							),
						),
						'count'    => 1,
						'platform' => $request['platform'],
					),
				);
			},
		)
	);

	register_rest_route(
		'datamachine/v1',
		'/socials/comments/(?P<platform>[a-z0-9_-]+)/reply',
		array(
			'methods'             => 'POST',
			'permission_callback' => $studio_persona_permission,
			'callback'            => static function ( WP_REST_Request $request ) {
				update_option( 'studio_persona_last_reply', $request->get_param( 'message' ), false );
				return array(
					'success' => true,
					'data'    => array(
						'comment_id' => (string) $request->get_param( 'comment_id' ),
						'reply_id'   => 'fixture-reply-1',
						'message'    => (string) $request->get_param( 'message' ),
					),
				);
			},
		)
	);

	foreach ( array( 'summary', 'surface-growth', 'retention', 'conversion-map' ) as $route ) {
		register_rest_route(
			'extrachill/v1',
			'/analytics/' . $route,
			array(
				'methods'             => 'GET',
				'permission_callback' => $studio_persona_permission,
				'callback'            => static function () {
					return array();
				},
			)
		);
	}

	register_rest_route(
		'datamachine/v1',
		'/analytics/ga',
		array(
			'methods'             => 'POST',
			'permission_callback' => $studio_persona_permission,
			'callback'            => static function () {
				return array( 'success' => false, 'results' => array() );
			},
		)
	);
} );

add_action( 'template_redirect', static function () use ( &$studio_persona ) {
	if ( '/studio-persona/' !== wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ) {
		return;
	}

	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		status_header( 403 );
		exit( 'Studio persona access denied.' );
	}

	show_admin_bar( false );
	wp_register_script(
		'extrachill-analytics-chart',
		'',
		array(),
		'fixture',
		true
	);
	wp_add_inline_script(
		'extrachill-analytics-chart',
		'window.ExtraChillChart={Chart:class{constructor(){}destroy(){}update(){}},default:class{constructor(){}destroy(){}update(){}}};'
	);
	wp_register_script( 'extrachill-analytics-date-range', '', array(), 'fixture', true );

	wp_enqueue_script(
		'extrachill-studio-persona',
		$studio_persona['asset_url'],
		$studio_persona['dependencies'],
		$studio_persona['version'],
		true
	);
	wp_enqueue_style( 'extrachill-studio-persona-view', $studio_persona['asset_css'], array(), $studio_persona['version'] );
	wp_enqueue_style( 'extrachill-studio-persona-style', $studio_persona['style_css'], array(), $studio_persona['version'] );

	$user              = wp_get_current_user();
	$can_brand_socials = 'studio-social-manager' === $user->user_login;
	$network_sites     = array(
		array( 'id' => 1, 'name' => 'Extra Chill', 'url' => home_url( '/' ), 'host' => wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
		array( 'id' => 12, 'name' => 'Extra Chill Studio', 'url' => home_url( '/studio-persona/' ), 'host' => wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
	);

	status_header( 200 );
	nocache_headers();
	?><!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Extra Chill Studio Persona</title>
		<?php wp_head(); ?>
	</head>
	<body <?php body_class( 'studio-persona-fixture' ); ?>>
		<main
			class="ec-studio-block"
			data-ec-studio-root
			data-user-name="<?php echo esc_attr( $user->display_name ); ?>"
			data-site-name="Extra Chill Studio"
			data-site-url="<?php echo esc_url( home_url( '/studio-persona/' ) ); ?>"
			data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-socials-api-base="<?php echo esc_url( rest_url( 'datamachine/v1/socials/' ) ); ?>"
			data-headline="Extra Chill Studio"
			data-description="Plan, publish, and understand Extra Chill content in one team workspace."
			data-social-platforms="[]"
			data-can-brand-socials="<?php echo $can_brand_socials ? 'true' : 'false'; ?>"
			data-network-sites="<?php echo esc_attr( wp_json_encode( $network_sites ) ); ?>"
		>
			<div class="ec-studio-app__mount" data-ec-studio-app></div>
		</main>
		<?php wp_footer(); ?>
	</body>
	</html><?php
	exit;
} );
PHP;

$harness = str_replace( '__STATE__', $state_export, $harness );
$mu_dir  = WPMU_PLUGIN_DIR;
wp_mkdir_p( $mu_dir );

if ( false === file_put_contents( $mu_dir . '/studio-social-manager-persona.php', $harness ) ) {
	throw new RuntimeException( 'Unable to write the Studio persona runtime harness.' );
}

echo wp_json_encode(
	array(
		'schema'        => 'extrachill-studio/persona-fixture/v1',
		'persona'       => 'nontechnical-team-social-manager',
		'url'           => '/studio-persona/',
		'attachment_id' => (int) $attachment_id,
		'external_writes' => false,
	),
	JSON_PRETTY_PRINT
);
