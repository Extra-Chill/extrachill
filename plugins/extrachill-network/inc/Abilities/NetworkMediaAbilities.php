<?php
/**
 * Network Media Abilities
 *
 * Cross-site media library access for the network. Uploads can target any
 * network site via blog_id and accept URL or base64 image sources, so callers
 * without a local file (agents, native clients) can add images. Phase 1 of the network-wide
 * unified media library tracked in extrachill-network#2 — currently scoped to
 * blog 1 (the main site, where editorial media lives) so consumers like Studio
 * can read and write that library from any subsite.
 *
 * Future phases extend this to all network sites, add Redis caching, register
 * Gutenberg `InserterMediaCategory`s, and integrate with IBE. Today's contract
 * (REST shape, ability slugs, response keys) is designed to remain stable
 * through that evolution — only the internal site iteration changes.
 *
 * @package ExtraChillNetwork\Abilities
 * @since 1.12.0
 */

namespace ExtraChillNetwork\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NetworkMediaAbilities {

	private static bool $registered = false;

	/**
	 * The blog ID of the main editorial media library.
	 *
	 * Phase 1 reads/writes only this site. When the cross-site loop ships
	 * (extrachill-network#2 phases 2+), this constant becomes one entry in
	 * a list of sites to iterate.
	 */
	private const MAIN_BLOG_ID = 1;

	/** URL and base64 imports: accepted image types and their extensions. */
	private const IMAGE_TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);

	/** URL and base64 import size cap. */
	private const MAX_IMPORT_BYTES = 5 * 1024 * 1024;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		// Category is registered once via inc/Abilities/CategoryRegistration.php.
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/network-media-list',
			array(
				'label'               => __( 'List Network Media', 'extrachill-network' ),
				'description'         => __( 'List attachments from the main editorial media library, filtered by media type and search term.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array(),
					'properties' => array(
						'media_type' => array(
							'type'        => 'string',
							'enum'        => array( 'image', 'video', 'audio' ),
							'description' => __( 'Filter by media type.', 'extrachill-network' ),
						),
						'search'     => array(
							'type'        => 'string',
							'description' => __( 'Filename or title search.', 'extrachill-network' ),
						),
						'per_page'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => __( 'Results per page.', 'extrachill-network' ),
						),
						'page'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page number.', 'extrachill-network' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'items'       => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array( 'type' => 'integer' ),
									'blog_id'    => array( 'type' => 'integer' ),
									'sourceId'   => array( 'type' => 'string' ),
									'url'        => array( 'type' => 'string' ),
									'previewUrl' => array( 'type' => 'string' ),
									'title'      => array( 'type' => 'string' ),
									'alt'        => array( 'type' => 'string' ),
									'caption'    => array( 'type' => 'string' ),
									'mime_type'  => array( 'type' => 'string' ),
									'media_type' => array( 'type' => 'string' ),
									'date'       => array( 'type' => 'string' ),
									'width'      => array( 'type' => 'integer' ),
									'height'     => array( 'type' => 'integer' ),
								),
							),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'executeList' ),
				'permission_callback' => array( $this, 'listPermissionCallback' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/network-media-upload',
			array(
				'label'               => __( 'Upload to Network Media', 'extrachill-network' ),
				'description'         => __( 'Add a file to a network site\'s media library and return the attachment. Sources: a multipart upload, a public image URL (for example a chat attachment URL), or base64 image bytes. Defaults to the main editorial library; pass blog_id to target another network site, e.g. the link pages site for link page images or the artist site for artist profile images, then assign the returned id with that domain\'s ability. Optional crop. URL and base64 imports accept JPG, PNG, GIF or WebP up to 5MB.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array(),
					'properties' => array(
						'blog_id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Network site whose media library receives the file. Defaults to the main editorial site.', 'extrachill-network' ),
						),
						'source_url'   => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'Public http(s) image URL to import.', 'extrachill-network' ),
						),
						'image_base64' => array(
							'type'        => 'string',
							'description' => __( 'Base64 image bytes or a data: URI.', 'extrachill-network' ),
						),
						'crop'         => array(
							'type'                 => 'object',
							'description'          => __( 'Optional crop as fractions of the source image. The top two thirds is {x:0,y:0,width:1,height:0.667}.', 'extrachill-network' ),
							'required'             => array( 'x', 'y', 'width', 'height' ),
							'properties'           => array(
								'x'      => array(
									'type'    => 'number',
									'minimum' => 0,
									'maximum' => 1,
								),
								'y'      => array(
									'type'    => 'number',
									'minimum' => 0,
									'maximum' => 1,
								),
								'width'  => array(
									'type'    => 'number',
									'minimum' => 0.01,
									'maximum' => 1,
								),
								'height' => array(
									'type'    => 'number',
									'minimum' => 0.01,
									'maximum' => 1,
								),
							),
							'additionalProperties' => false,
						),
						'tmp_name' => array(
							'type'        => 'string',
							'description' => __( 'PHP $_FILES["tmp_name"] path.', 'extrachill-network' ),
						),
						'name'     => array(
							'type'        => 'string',
							'description' => __( 'Original filename.', 'extrachill-network' ),
						),
						'type'     => array(
							'type'        => 'string',
							'description' => __( 'MIME type as reported by the upload.', 'extrachill-network' ),
						),
						'size'     => array(
							'type'        => 'integer',
							'description' => __( 'File size in bytes.', 'extrachill-network' ),
						),
						'title'    => array(
							'type'        => 'string',
							'description' => __( 'Optional attachment title. Defaults to filename without extension.', 'extrachill-network' ),
						),
						'alt'      => array(
							'type'        => 'string',
							'description' => __( 'Optional alt text.', 'extrachill-network' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'blog_id'    => array( 'type' => 'integer' ),
						'sourceId'   => array( 'type' => 'string' ),
						'url'        => array( 'type' => 'string' ),
						'previewUrl' => array( 'type' => 'string' ),
						'title'      => array( 'type' => 'string' ),
						'mime_type'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUpload' ),
				'permission_callback' => array( $this, 'permissionCallback' ),
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * List permission check.
	 *
	 * Listing always reads blog 1, so its check ignores any blog_id input.
	 *
	 * @return bool
	 */
	public function listPermissionCallback(): bool {
		return $this->permissionCallback( array() );
	}

	/**
	 * Upload permission check.
	 *
	 * Requires `upload_files` on the target site: blog 1 (the main editorial
	 * site) unless `blog_id` names another network site.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	public function permissionCallback( $input = array() ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$blog_id = self::targetBlogId( is_array( $input ) ? $input : array() );
		if ( ! $blog_id ) {
			return false;
		}

		switch_to_blog( $blog_id );
		try {
			return current_user_can( 'upload_files' );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Execute network-media-list.
	 *
	 * @param array $input Input parameters.
	 * @return array Normalised list response.
	 */
	public function executeList( array $input ): array {
		$media_type = isset( $input['media_type'] ) ? (string) $input['media_type'] : '';
		$search     = isset( $input['search'] ) ? (string) $input['search'] : '';
		$per_page   = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
		$page       = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		switch_to_blog( self::MAIN_BLOG_ID );
		try {
			$query_args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			);

			if ( '' !== $search ) {
				$query_args['s'] = $search;
			}

			if ( '' !== $media_type ) {
				$query_args['post_mime_type'] = $media_type;
			}

			$query = new \WP_Query( $query_args );

			$items = array();
			foreach ( $query->posts as $attachment ) {
				$item = $this->normaliseAttachment( $attachment->ID );
				if ( null !== $item ) {
					$items[] = $item;
				}
			}

			return array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Execute network-media-upload.
	 *
	 * Accepts a file descriptor matching PHP's `$_FILES` shape, performs the
	 * upload + attachment insertion on blog 1, and returns the normalised
	 * attachment record. The REST handler is responsible for marshalling
	 * `$_FILES` into the input array.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Normalised attachment, or WP_Error.
	 */
	public function executeUpload( array $input ) {
		$sources = (int) isset( $input['tmp_name'] ) + (int) isset( $input['source_url'] ) + (int) isset( $input['image_base64'] );
		if ( 1 !== $sources ) {
			return new \WP_Error( 'invalid_source', __( 'Supply exactly one of a file upload, source_url, or image_base64.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		$blog_id = self::targetBlogId( $input );
		if ( ! $blog_id ) {
			return new \WP_Error( 'invalid_blog', __( 'blog_id must be an existing network site.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		if ( ! isset( $input['tmp_name'] ) ) {
			return $this->importImage( $input, $blog_id );
		}
		if ( isset( $input['crop'] ) ) {
			return new \WP_Error( 'invalid_crop', __( 'crop applies to source_url and image_base64 imports.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'name', 'type', 'size' ) as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				return new \WP_Error( 'invalid_source', __( 'A file upload needs tmp_name, name, type and size.', 'extrachill-network' ), array( 'status' => 400 ) );
			}
		}

		$file_descriptor = array(
			'tmp_name' => (string) $input['tmp_name'],
			'name'     => (string) $input['name'],
			'type'     => (string) $input['type'],
			'size'     => (int) $input['size'],
			'error'    => UPLOAD_ERR_OK,
		);

		$title = isset( $input['title'] ) ? (string) $input['title'] : '';
		$alt   = isset( $input['alt'] ) ? (string) $input['alt'] : '';

		/* Validate the uploaded file before crossing the blog boundary so any rejection short-circuits cleanly. */
		$check = wp_check_filetype_and_ext( $file_descriptor['tmp_name'], $file_descriptor['name'] );
		if ( empty( $check['type'] ) ) {
			return new \WP_Error(
				'invalid_file_type',
				__( 'Unsupported file type.', 'extrachill-network' ),
				array( 'status' => 400 )
			);
		}

		switch_to_blog( $blog_id );
		try {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$handled = wp_handle_upload( $file_descriptor, array( 'test_form' => false ) );

			if ( ! $handled || ! empty( $handled['error'] ) ) {
				$message = ! empty( $handled['error'] ) ? $handled['error'] : __( 'Upload failed.', 'extrachill-network' );
				return new \WP_Error( 'upload_failed', $message, array( 'status' => 500 ) );
			}

			$attachment_title = '' !== $title
				? $title
				: preg_replace( '/\.[^.]+$/', '', basename( $handled['file'] ) );

			$attachment_id = wp_insert_attachment(
				array(
					'guid'           => $handled['url'],
					'post_author'    => get_current_user_id(),
					'post_mime_type' => $handled['type'],
					'post_title'     => $attachment_title,
					'post_content'   => '',
					'post_status'    => 'inherit',
				),
				$handled['file'],
				0
			);

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $handled['file'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}

			$item = $this->normaliseAttachment( $attachment_id );
			if ( null === $item ) {
				return new \WP_Error(
					'attachment_normalise_failed',
					__( 'Upload succeeded but the attachment could not be loaded.', 'extrachill-network' ),
					array( 'status' => 500 )
				);
			}

			return $item;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Resolve the target site: blog 1 by default, otherwise an existing,
	 * non-archived network site. Returns 0 when invalid.
	 *
	 * @param array $input Ability input.
	 * @return int
	 */
	private static function targetBlogId( array $input ): int {
		if ( ! isset( $input['blog_id'] ) ) {
			return self::MAIN_BLOG_ID;
		}
		$blog_id = (int) $input['blog_id'];
		$site    = $blog_id > 0 && function_exists( 'get_site' ) ? get_site( $blog_id ) : null;
		if ( ! $site || ! empty( $site->deleted ) || ! empty( $site->archived ) || ! empty( $site->spam ) ) {
			return 0;
		}
		return $blog_id;
	}

	/**
	 * Normalise an optional crop box of fractions.
	 *
	 * @param mixed $crop Crop input.
	 * @return array|null|\WP_Error
	 */
	public static function cropBox( $crop ) {
		if ( null === $crop ) {
			return null;
		}
		if ( ! is_array( $crop ) ) {
			return new \WP_Error( 'invalid_crop', __( 'crop must be an object with x, y, width and height fractions.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		$box = array();
		foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) {
			if ( ! isset( $crop[ $key ] ) || ! is_numeric( $crop[ $key ] ) ) {
				return new \WP_Error( 'invalid_crop', __( 'crop requires numeric x, y, width and height.', 'extrachill-network' ), array( 'status' => 400 ) );
			}
			$box[ $key ] = (float) $crop[ $key ];
		}
		if ( $box['x'] < 0 || $box['y'] < 0 || $box['width'] <= 0 || $box['height'] <= 0 || $box['x'] + $box['width'] > 1.0001 || $box['y'] + $box['height'] > 1.0001 ) {
			return new \WP_Error( 'invalid_crop', __( 'crop must stay inside the image (fractions from 0 to 1).', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		return $box;
	}

	/**
	 * Import an image from a public URL or base64, optionally crop it, and
	 * sideload it into the target site's library. Temp files are always removed.
	 *
	 * @param array $input   Ability input.
	 * @param int   $blog_id Target site.
	 * @return array|\WP_Error
	 */
	private function importImage( array $input, int $blog_id ) {
		$crop = self::cropBox( $input['crop'] ?? null );
		if ( is_wp_error( $crop ) ) {
			return $crop;
		}

		if ( isset( $input['source_url'] ) ) {
			$url = $input['source_url'];
			if ( ! is_string( $url ) || ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) || ! wp_http_validate_url( $url ) ) {
				return new \WP_Error( 'invalid_source_url', __( 'A public http(s) image URL is required.', 'extrachill-network' ), array( 'status' => 400 ) );
			}
			self::loadMediaApi();
			$temp = download_url( $url, 30 );
			if ( is_wp_error( $temp ) ) {
				return $temp;
			}
		} else {
			$temp = self::tempFromBase64( $input['image_base64'] );
			if ( is_wp_error( $temp ) ) {
				return $temp;
			}
		}

		$cropped = '';
		try {
			$mime = self::validateImageFile( $temp );
			if ( is_wp_error( $mime ) ) {
				return $mime;
			}
			$extension = self::IMAGE_TYPES[ $mime ];
			$source    = $temp;
			if ( $crop ) {
				$editor = wp_get_image_editor( $temp, array( 'mime_type' => $mime ) );
				if ( is_wp_error( $editor ) ) {
					return $editor;
				}
				$size = $editor->get_size();
				$x    = (int) round( $crop['x'] * $size['width'] );
				$y    = (int) round( $crop['y'] * $size['height'] );
				$w    = max( 1, min( (int) round( $crop['width'] * $size['width'] ), $size['width'] - $x ) );
				$h    = max( 1, min( (int) round( $crop['height'] * $size['height'] ), $size['height'] - $y ) );
				$done = $editor->crop( $x, $y, $w, $h );
				if ( is_wp_error( $done ) ) {
					return $done;
				}
				$saved = $editor->save( $temp . '-crop.' . $extension, $mime );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$cropped = $saved['path'];
				$source  = $cropped;
			}

			$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
			$name  = ( '' !== $title ? sanitize_file_name( $title ) : 'image' ) . '.' . $extension;

			switch_to_blog( $blog_id );
			try {
				$attachment_id = media_handle_sideload(
					array(
						'name'     => $name,
						'tmp_name' => $source,
					),
					0,
					'' !== $title ? $title : null
				);
				if ( is_wp_error( $attachment_id ) ) {
					return $attachment_id;
				}
				if ( isset( $input['alt'] ) && '' !== $input['alt'] ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
				}
				$item = $this->normaliseAttachment( (int) $attachment_id );
				return null !== $item ? $item : new \WP_Error( 'attachment_normalise_failed', __( 'Upload succeeded but the attachment could not be loaded.', 'extrachill-network' ), array( 'status' => 500 ) );
			} finally {
				restore_current_blog();
			}
		} finally {
			foreach ( array( $temp, $cropped ) as $file ) {
				if ( $file && file_exists( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	/**
	 * Check actual image bytes and the size cap.
	 *
	 * @param string $file Local path.
	 * @return string|\WP_Error Detected MIME type.
	 */
	public static function validateImageFile( string $file ) {
		$mime = wp_get_image_mime( $file );
		if ( ! $mime || ! isset( self::IMAGE_TYPES[ $mime ] ) ) {
			return new \WP_Error( 'invalid_file_type', __( 'Only JPG, PNG, GIF, and WebP images are allowed.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		if ( filesize( $file ) > self::MAX_IMPORT_BYTES ) {
			return new \WP_Error( 'file_too_large', __( 'Images are limited to 5MB.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		return $mime;
	}

	/**
	 * Decode base64 (raw or data: URI) into a temp file, rejecting oversized
	 * payloads before decoding.
	 *
	 * @param mixed $data Base64 payload.
	 * @return string|\WP_Error Temp path.
	 */
	public static function tempFromBase64( $data ) {
		if ( ! is_string( $data ) || '' === $data ) {
			return new \WP_Error( 'invalid_source', __( 'image_base64 must be a non-empty string.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		if ( 0 === strpos( $data, 'data:' ) ) {
			$comma = strpos( $data, ',' );
			$data  = false === $comma ? '' : substr( $data, $comma + 1 );
		}
		if ( strlen( $data ) > (int) ceil( self::MAX_IMPORT_BYTES * 4 / 3 ) + 8 ) {
			return new \WP_Error( 'file_too_large', __( 'Images are limited to 5MB.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		$bytes = base64_decode( (string) preg_replace( '/\s+/', '', $data ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a caller-supplied image payload.
		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error( 'invalid_source', __( 'image_base64 is not valid base64.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		self::loadMediaApi();
		$temp = wp_tempnam( 'network-media' );
		if ( ! $temp || false === file_put_contents( $temp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- staging a temp file for sideload.
			return new \WP_Error( 'upload_failed', __( 'Could not stage the image.', 'extrachill-network' ), array( 'status' => 500 ) );
		}
		return $temp;
	}

	/** Load the admin media API only after a source has been validated. */
	private static function loadMediaApi(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Normalise an attachment ID into the canonical media item shape.
	 *
	 * Must be called inside the correct blog context (after switch_to_blog).
	 * Returns null for IDs that don't resolve to an attachment.
	 *
	 * @param int $attachment_id Attachment ID on the current blog.
	 * @return array|null
	 */
	private function normaliseAttachment( int $attachment_id ): ?array {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		$blog_id       = get_current_blog_id();
		$mime_type     = (string) get_post_mime_type( $attachment );
		$mime_segments = explode( '/', $mime_type, 2 );
		$media_type    = $mime_segments[0];
		$full_url      = (string) wp_get_attachment_url( $attachment_id );
		$preview       = wp_get_attachment_image_src( $attachment_id, 'medium' );
		$metadata      = wp_get_attachment_metadata( $attachment_id );

		return array(
			'id'         => $attachment_id,
			'blog_id'    => $blog_id,
			'sourceId'   => sprintf( '%d:%d', $blog_id, $attachment_id ),
			'url'        => $full_url,
			'previewUrl' => is_array( $preview ) && ! empty( $preview[0] ) ? (string) $preview[0] : $full_url,
			'title'      => (string) $attachment->post_title,
			'alt'        => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'    => (string) $attachment->post_excerpt,
			'mime_type'  => $mime_type,
			'media_type' => $media_type,
			'date'       => (string) $attachment->post_date_gmt,
			'width'      => is_array( $metadata ) && isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
			'height'     => is_array( $metadata ) && isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
		);
	}
}
