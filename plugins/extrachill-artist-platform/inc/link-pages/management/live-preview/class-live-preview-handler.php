<?php
/**
 * Live Preview Handler Class
 * 
 * Handles backend processing for live preview functionality, including data preparation,
 * AJAX response generation, and preview state management.
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
        add_action( 'wp_ajax_update_live_preview', array( $this, 'handle_preview_update' ) );
        add_action( 'wp_ajax_nopriv_update_live_preview', array( $this, 'handle_preview_update' ) );
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

    /**
     * Handle live preview update AJAX request
     * 
     * @since 1.0.0
     */
    public function handle_preview_update() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'], 'extrch_link_page_ajax_nonce' ) ) {
            wp_die( 'Security check failed' );
        }

        $link_page_id = apply_filters('ec_get_link_page_id', $_POST);
        $artist_id = apply_filters('ec_get_artist_id', $_POST);

        if ( ! $link_page_id || ! $artist_id ) {
            wp_send_json_error( 'Missing required parameters' );
        }

        // Get updated data
        $preview_data = $this->prepare_preview_data( $link_page_id, $artist_id, $_POST );

        // Generate preview HTML
        $preview_html = $this->generate_preview_html( $preview_data );

        wp_send_json_success( array(
            'html' => $preview_html,
            'data' => $preview_data
        ) );
    }

    /**
     * Prepare preview data from form inputs
     * 
     * Uses the centralized ec_get_link_page_data() function with override support
     * for real-time preview functionality without database updates.
     * 
     * @since 1.0.0
     * @param int $link_page_id The link page ID
     * @param int $artist_id The artist ID
     * @param array $form_data The form data containing preview overrides
     * @return array Preview data with form overrides applied
     */
    private function prepare_preview_data( $link_page_id, $artist_id, $form_data ) {
        // Load the data via centralized data provider function with overrides support
        if ( function_exists( 'ec_get_link_page_data' ) ) {
            // Pass form_data as overrides parameter for real-time preview
            return ec_get_link_page_data( $artist_id, $link_page_id, $form_data );
        }

        // Fallback data preparation
        return array(
            'display_title' => sanitize_text_field( $form_data['display_title'] ?? '' ),
            'bio' => sanitize_textarea_field( $form_data['bio'] ?? '' ),
            'profile_img_url' => esc_url_raw( $form_data['profile_img_url'] ?? '' ),
            'social_links' => array(),
            'link_sections' => array(),
            'background_style' => '',
            'css_vars' => array()
        );
    }

    /**
     * Generate preview HTML from data
     * 
     * Uses the unified template system to render preview HTML.
     * 
     * @since 1.0.0
     * @param array $preview_data The preview data from ec_get_link_page_data()
     * @return string Generated HTML for preview iframe
     */
    private function generate_preview_html( $preview_data ) {
        return ec_render_template('link-page-live-preview', array(
            'preview_data' => $preview_data
        ));
    }
}

// Initialize the handler
ExtraChill_Live_Preview_Handler::instance();