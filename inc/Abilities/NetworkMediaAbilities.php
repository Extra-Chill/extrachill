<?php
/**
 * Network Media Abilities
 *
 * Cross-site media library access for the network. Phase 1 of the network-wide
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
				'permission_callback' => array( $this, 'permissionCallback' ),
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
				'description'         => __( 'Upload a file to the main editorial media library and return the resulting attachment.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'tmp_name', 'name', 'type', 'size' ),
					'properties' => array(
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
	 * Permission check.
	 *
	 * Phase 1 requires `upload_files` on blog 1 (the main editorial site).
	 * This naturally restricts access to team members who already have edit
	 * rights on the main site — Studio team membership inherits from there.
	 *
	 * @return bool
	 */
	public function permissionCallback(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		switch_to_blog( self::MAIN_BLOG_ID );
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
		$file_descriptor = array(
			'tmp_name' => (string) $input['tmp_name'],
			'name'     => (string) $input['name'],
			'type'     => (string) $input['type'],
			'size'     => (int) $input['size'],
			'error'    => UPLOAD_ERR_OK,
		);

		$title = isset( $input['title'] ) ? (string) $input['title'] : '';
		$alt   = isset( $input['alt'] ) ? (string) $input['alt'] : '';

		// Validate the uploaded file before crossing the blog boundary so
		// any rejection short-circuits cleanly.
		$check = wp_check_filetype_and_ext( $file_descriptor['tmp_name'], $file_descriptor['name'] );
		if ( empty( $check['type'] ) ) {
			return new \WP_Error(
				'invalid_file_type',
				__( 'Unsupported file type.', 'extrachill-network' ),
				array( 'status' => 400 )
			);
		}

		switch_to_blog( self::MAIN_BLOG_ID );
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
