<?php
/**
 * Roundup Publish Handler
 *
 * Creates WordPress posts with generated carousel images from engine data.
 * Uploads images to media library and inserts them as image blocks.
 *
 * @package ExtraChillEvents\Handlers\WeeklyRoundup
 */

namespace ExtraChillEvents\Handlers\WeeklyRoundup;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\EngineData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoundupPublishHandler extends PublishHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'roundup' );

		self::registerHandler(
			'roundup_publish',
			'publish',
			self::class,
			__( 'Event Roundup Images', 'extrachill-events' ),
			__( 'Create a WordPress post with generated carousel images', 'extrachill-events' ),
			false,
			null,
			RoundupPublishSettings::class,
			function($tools, $handler_slug, $handler_config) {
				if ($handler_slug === 'roundup_publish') {
					$tools['roundup_publish'] = [
						'class' => self::class,
						'method' => 'handle_tool_call',
						'handler' => 'roundup_publish',
						'description' => 'Create a WordPress post with Instagram carousel images and caption.',
						'parameters' => [
							'type' => 'object',
							'properties' => [
								'instagram_caption' => [
									'type' => 'string',
									'description' => 'The Instagram caption text to place at the top of the post'
								]
							],
							'required' => ['instagram_caption']
						]
					];
				}
				return $tools;
			}
		);
	}

	/**
	 * Execute roundup publishing.
	 *
	 * Retrieves image_file_paths from engine data, uploads to media library,
	 * and creates a post with image blocks.
	 *
	 * @param array $parameters Tool call parameters including job_id.
	 * @param array $handler_config Handler configuration.
	 * @return array Success status with post data or error information.
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			return $this->errorResponse( 'Engine data not available' );
		}

		$engine_data = $engine->all();

		$image_paths = $engine_data['image_file_paths'] ?? array();
		if ( empty( $image_paths ) ) {
			return $this->errorResponse(
				'No images found in engine data',
				array( 'engine_data_keys' => array_keys( $engine_data ) )
			);
		}

		$instagram_caption = $parameters['instagram_caption'] ?? '';
		if ( empty( $instagram_caption ) ) {
			return $this->errorResponse( 'Instagram caption is required for roundup publish' );
		}

		$location_name = $engine_data['location_name'] ?? 'Events';
		$date_range    = $engine_data['date_range'] ?? '';
		$total_events  = $engine_data['total_events'] ?? 0;

		$title = sprintf( '%s: %s', $location_name, $date_range );

		$this->log(
			'info',
			'Starting roundup publish',
			array(
				'image_count'   => count( $image_paths ),
				'location_name' => $location_name,
				'date_range'    => $date_range,
				'caption_length' => strlen( $instagram_caption ),
			)
		);

		$attachment_ids = array();
		foreach ( $image_paths as $index => $image_path ) {
			$attachment_id = $this->upload_image_to_media_library( $image_path, $title, $index + 1 );
			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			} else {
				$this->log(
					'warning',
					'Failed to upload image',
					array(
						'image_path' => $image_path,
						'index'      => $index,
					)
				);
			}
		}

		if ( empty( $attachment_ids ) ) {
			return $this->errorResponse( 'Failed to upload any images to media library' );
		}

		$post_content = $this->build_post_content( $attachment_ids, $engine_data, $instagram_caption );

		$post_status = $handler_config['post_status'] ?? 'draft';

		$post_data = array(
			'post_title'   => \sanitize_text_field( $title ),
			'post_content' => $post_content,
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'post_author'  => \get_current_user_id() ? \get_current_user_id() : 1,
		);

		$post_id = \wp_insert_post( $post_data );

		if ( \is_wp_error( $post_id ) ) {
			return $this->errorResponse(
				'WordPress post creation failed: ' . $post_id->get_error_message(),
				array( 'post_data' => $post_data )
			);
		}

		if ( ! empty( $attachment_ids[0] ) ) {
			\set_post_thumbnail( $post_id, $attachment_ids[0] );
		}

		$job_id = $parameters['job_id'] ?? null;
		if ( $job_id ) {
			\datamachine_merge_engine_data(
				(int) $job_id,
				array(
					'post_id'       => $post_id,
					'published_url' => \get_permalink( $post_id ),
				)
			);
		}

		$this->log(
			'info',
			'Roundup post created',
			array(
				'post_id'          => $post_id,
				'attachment_count' => count( $attachment_ids ),
				'post_url'         => \get_permalink( $post_id ),
			)
		);

		return $this->successResponse(
			array(
				'post_id'        => $post_id,
				'post_title'     => $title,
				'post_url'       => \get_permalink( $post_id ),
				'image_count'    => count( $attachment_ids ),
				'attachment_ids' => $attachment_ids,
			)
		);
	}

	/**
	 * Upload an image file to the WordPress media library.
	 *
	 * @param string $file_path Full path to the image file.
	 * @param string $title Base title for the attachment.
	 * @param int    $slide_number Slide number for naming.
	 * @return int|null Attachment ID on success, null on failure.
	 */
	private function upload_image_to_media_library( string $file_path, string $title, int $slide_number ): ?int {
		if ( ! file_exists( $file_path ) ) {
			$this->log( 'error', 'Image file not found', array( 'file_path' => $file_path ) );
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$file_array = array(
			'name'     => sprintf( 'roundup-slide-%d.png', $slide_number ),
			'tmp_name' => $file_path,
		);

		$attachment_id = \media_handle_sideload( $file_array, 0, sprintf( '%s - Slide %d', $title, $slide_number ) );

		if ( \is_wp_error( $attachment_id ) ) {
			$this->log(
				'error',
				'Media sideload failed',
				array(
					'error' => $attachment_id->get_error_message(),
				)
			);
			return null;
		}

		return $attachment_id;
	}

	/**
	 * Build post content with caption and image blocks.
	 *
	 * @param array $attachment_ids Array of attachment IDs.
	 * @param array $engine_data Engine data with event summary.
	 * @param string $instagram_caption The Instagram caption text.
	 * @return string Post content with Gutenberg blocks.
	 */
	private function build_post_content( array $attachment_ids, array $engine_data, string $instagram_caption ): string {
		$blocks = array();

		// Add caption as a paragraph block at the top
		if ( ! empty( $instagram_caption ) ) {
			$blocks[] = '<!-- wp:paragraph -->';
			$blocks[] = '<p>' . \esc_html( $instagram_caption ) . '</p>';
			$blocks[] = '<!-- /wp:paragraph -->';
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$image_url = \wp_get_attachment_url( $attachment_id );
			if ( ! $image_url ) {
				continue;
			}

			$blocks[] = sprintf(
				'<!-- wp:image {"id":%d,"sizeSlug":"full","linkDestination":"none"} -->' .
				'<figure class="wp-block-image size-full"><img src="%s" alt="" class="wp-image-%d"/></figure>' .
				'<!-- /wp:image -->',
				$attachment_id,
				\esc_url( $image_url ),
				$attachment_id
			);
		}

		$event_summary = $engine_data['event_summary'] ?? '';
		if ( $event_summary ) {
			$blocks[] = '<!-- wp:heading {"level":3} -->';
			$blocks[] = '<h3 class="wp-block-heading">Event Summary</h3>';
			$blocks[] = '<!-- /wp:heading -->';

			$blocks[] = '<!-- wp:preformatted -->';
			$blocks[] = '<pre class="wp-block-preformatted">' . \esc_html( $event_summary ) . '</pre>';
			$blocks[] = '<!-- /wp:preformatted -->';
		}

		return implode( "\n\n", $blocks );
	}
}
