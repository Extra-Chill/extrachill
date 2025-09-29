<?php
/**
 * Main Plugin Class
 *
 * @package TriviaBlock
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class for Trivia Block
 */
class Trivia_Block_Plugin {

	/**
	 * Plugin instance
	 *
	 * @var Trivia_Block_Plugin
	 */
	private static $instance = null;

	/**
	 * Get plugin instance
	 *
	 * @return Trivia_Block_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin
	 */
	public function init() {
		// Hook into WordPress
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the trivia block
	 */
	public function register_block() {
		// Register the block type
		register_block_type(
			TRIVIA_BLOCK_PLUGIN_DIR . 'build/trivia-block',
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Render the block on the frontend
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Block content.
	 * @return string Block HTML.
	 */
	public function render_block( $attributes, $content ) {
		// Extract block attributes with defaults
		$question = isset( $attributes['question'] ) ? $attributes['question'] : '';
		$options = isset( $attributes['options'] ) ? $attributes['options'] : array( '', '' );
		$correct_answer = isset( $attributes['correctAnswer'] ) ? $attributes['correctAnswer'] : 0;
		$answer_justification = isset( $attributes['answerJustification'] ) ? $attributes['answerJustification'] : '';
		$block_id = isset( $attributes['blockId'] ) ? $attributes['blockId'] : uniqid( 'trivia_' );
		$result_messages = isset( $attributes['resultMessages'] ) ? $attributes['resultMessages'] : array(
			'excellent' => '🏆 Trivia Master!',
			'good' => '🎉 Great Job!',
			'okay' => '👍 Not Bad!',
			'poor' => '🤔 Keep Trying!'
		);
		$score_ranges = isset( $attributes['scoreRanges'] ) ? $attributes['scoreRanges'] : array(
			'excellent' => 90,
			'good' => 70,
			'okay' => 50
		);

		// Sanitize data
		$question = wp_kses_post( $question );
		$options = array_map( 'sanitize_text_field', $options );
		$correct_answer = intval( $correct_answer );
		$answer_justification = wp_kses_post( $answer_justification );

		// Don't render if question is empty
		if ( empty( $question ) || empty( array_filter( $options ) ) ) {
			return '';
		}

		// Build the HTML
		ob_start();
		?>
		<div class="trivia-block" 
			data-block-id="<?php echo esc_attr( $block_id ); ?>" 
			data-correct-answer="<?php echo esc_attr( $correct_answer ); ?>"
			data-answer-justification="<?php echo esc_attr( $answer_justification ); ?>"
			data-result-messages="<?php echo esc_attr( wp_json_encode( $result_messages ) ); ?>"
			data-score-ranges="<?php echo esc_attr( wp_json_encode( $score_ranges ) ); ?>">
			<div class="trivia-block__question">
				<h3><?php echo $question; ?></h3>
			</div>
			<div class="trivia-block__options">
				<?php foreach ( $options as $index => $option ) : ?>
					<?php if ( ! empty( $option ) ) : ?>
						<button 
							class="trivia-block__option" 
							data-option-index="<?php echo esc_attr( $index ); ?>"
							type="button"
						>
							<?php echo esc_html( $option ); ?>
						</button>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<div class="trivia-block__feedback" style="display: none;"></div>
			<?php if ( ! empty( $answer_justification ) ) : ?>
				<div class="trivia-block__justification" style="display: none;">
					<div class="trivia-block__justification-content">
						<?php echo $answer_justification; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue frontend assets
	 */
	public function enqueue_frontend_assets() {
		// Only enqueue if we have trivia blocks on the page
		if ( ! $this->has_trivia_blocks() ) {
			return;
		}

		$js_file = TRIVIA_BLOCK_PLUGIN_URL . 'assets/js/trivia-block-frontend.js';
		$css_file = TRIVIA_BLOCK_PLUGIN_URL . 'assets/css/trivia-block-frontend.css';

		// Enqueue frontend JavaScript
		if ( file_exists( TRIVIA_BLOCK_PLUGIN_DIR . 'assets/js/trivia-block-frontend.js' ) ) {
			wp_enqueue_script(
				'trivia-block-frontend',
				$js_file,
				array(),
				filemtime( TRIVIA_BLOCK_PLUGIN_DIR . 'assets/js/trivia-block-frontend.js' ),
				true
			);

			// Localize script with AJAX URL and nonce
			wp_localize_script(
				'trivia-block-frontend',
				'triviaBlockAjax',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'restUrl' => rest_url( 'trivia-block/v1/' ),
					'nonce'   => wp_create_nonce( 'trivia_block_nonce' ),
				)
			);
		}

		// Enqueue frontend CSS
		if ( file_exists( TRIVIA_BLOCK_PLUGIN_DIR . 'assets/css/trivia-block-frontend.css' ) ) {
			wp_enqueue_style(
				'trivia-block-frontend',
				$css_file,
				array(),
				filemtime( TRIVIA_BLOCK_PLUGIN_DIR . 'assets/css/trivia-block-frontend.css' )
			);
		}
	}

	/**
	 * Check if the current page has trivia blocks
	 *
	 * @return bool
	 */
	private function has_trivia_blocks() {
		global $post;
		
		if ( ! $post || ! $post->post_content ) {
			return false;
		}

		return has_block( 'trivia-block/trivia', $post );
	}

	/**
	 * Register REST API routes
	 */
	public function register_rest_routes() {
		register_rest_route(
			'trivia-block/v1',
			'/log-attempt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'log_attempt' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'block_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'selected_option' => array(
						'required'          => true,
						'sanitize_callback' => 'intval',
					),
					'is_correct' => array(
						'required'          => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
	}

	/**
	 * Log a trivia attempt via REST API
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function log_attempt( $request ) {
		// Verify nonce
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'trivia_block_nonce' ) ) {
			return new WP_Error(
				'invalid_nonce',
				'Invalid security token',
				array( 'status' => 403 )
			);
		}

		$block_id = $request->get_param( 'block_id' );
		$selected_option = $request->get_param( 'selected_option' );
		$is_correct = $request->get_param( 'is_correct' );

		// Log the attempt (for now, just return success)
		// In the future, we can store this in a custom table for analytics
		
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'Attempt logged successfully',
				'data'    => array(
					'block_id'        => $block_id,
					'selected_option' => $selected_option,
					'is_correct'      => $is_correct,
					'timestamp'       => current_time( 'mysql' ),
				),
			)
		);
	}
} 