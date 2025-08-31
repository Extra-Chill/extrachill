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
     * @since 1.0.0
     * @param int $link_page_id The link page ID
     * @param int $artist_id The artist ID
     * @param array $form_data The form data
     * @return array Preview data
     */
    private function prepare_preview_data( $link_page_id, $artist_id, $form_data ) {
        // Load the data via centralized filter function with overrides support
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
     * @since 1.0.0
     * @param array $preview_data The preview data
     * @return string Generated HTML
     */
    private function generate_preview_html( $preview_data ) {
        return ec_render_template('link-page-live-preview', array(
            'preview_data' => $preview_data
        ));
    }
}

// Initialize the handler
ExtraChill_Live_Preview_Handler::instance();