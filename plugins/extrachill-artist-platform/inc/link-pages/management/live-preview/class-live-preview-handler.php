<?php
/**
 * Live Preview Handler Class
 * 
 * Handles AJAX template rendering for live preview functionality.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ExtraChill_Live_Preview_Handler {

    /**
     * Single instance of the class
     * 
     * @var ExtraChill_Live_Preview_Handler|null
     * @since 1.0.0
     */
    private static $instance = null;

    /**
     * Get single instance
     * 
     * @since 1.0.0
     * @return ExtraChill_Live_Preview_Handler The handler instance
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     * 
     * @since 1.0.0
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     * 
     * @since 1.0.0
     */
    private function init_hooks() {
        add_action( 'wp_ajax_render_subscribe_template', array( $this, 'render_subscribe_template' ) );
    }

    /**
     * AJAX handler for rendering subscription templates
     *
     * Returns HTML for subscription modal or inline form using unified template system.
     * Used by live preview to dynamically render subscribe components.
     *
     * @since 1.0.0
     */
    public function render_subscribe_template() {
        try {
            $template_type = wp_unslash( sanitize_text_field( $_POST['template_type'] ?? '' ) );
            $artist_id     = isset( $_POST['artist_id'] ) ? (int) $_POST['artist_id'] : 0;
            $artist_name   = wp_unslash( sanitize_text_field( $_POST['artist_name'] ?? '' ) );
            $description   = wp_unslash( sanitize_textarea_field( $_POST['description'] ?? '' ) );

            if ( ! in_array( $template_type, array( 'inline_form', 'modal' ), true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid template type' ) );
                return;
            }

            if ( ! $artist_id ) {
                wp_send_json_error( array( 'message' => 'Artist ID required' ) );
                return;
            }

            $template_args = array(
                'artist_id'   => $artist_id,
                'artist_name' => $artist_name,
                'data'        => array(
                    'display_title'                   => $artist_name,
                    '_link_page_subscribe_description' => $description,
                ),
            );

            $template_name = $template_type === 'modal' ? 'subscribe-modal' : 'subscribe-inline-form';
            $html          = ec_render_template( $template_name, $template_args );

            wp_send_json_success( array( 'html' => $html ) );

        } catch ( Exception $e ) {
            error_log( 'Subscribe template rendering error: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => 'Subscribe template rendering failed' ) );
        }
    }
}

// Initialize the handler
ExtraChill_Live_Preview_Handler::instance();
