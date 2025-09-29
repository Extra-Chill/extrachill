<?php
/**
 * Admin-related functionality for the ExtraChill Blocks plugin.
 *
 * @package ExtraChillBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// --- Settings Page ---

/**
 * Adds the admin menu item for the settings page.
 */
function extrachill_blocks_add_admin_menu() {
    add_options_page(
        __( 'ExtraChill Blocks Settings', 'extrachill-blocks' ),
        __( 'ExtraChill Blocks', 'extrachill-blocks' ),
        'manage_options',
        'extrachill-blocks',
        'extrachill_blocks_options_page_html'
    );
}
add_action( 'admin_menu', 'extrachill_blocks_add_admin_menu' );

/**
 * Initializes the settings page, registers settings, sections, and fields.
 */
function extrachill_blocks_settings_init() {
    register_setting( 'extrachill_blocks_options', 'extrachill_blocks_options', 'extrachill_blocks_options_validate' );

    add_settings_section(
        'extrachill_blocks_section_api_keys',
        __( 'API Keys', 'extrachill-blocks' ),
        'extrachill_blocks_section_api_keys_callback',
        'extrachill-blocks'
    );

    add_settings_field(
        'extrachill_blocks_openai_api_key',
        __( 'OpenAI API Key', 'extrachill-blocks' ),
        'extrachill_blocks_field_openai_api_key_html',
        'extrachill-blocks',
        'extrachill_blocks_section_api_keys',
        [ 'label_for' => 'extrachill_blocks_openai_api_key' ]
    );
}
add_action( 'admin_init', 'extrachill_blocks_settings_init' );

/**
 * Renders the description for the API Keys section.
 */
function extrachill_blocks_section_api_keys_callback() {
    echo '<p>' . __( 'Enter your OpenAI API key for AI-powered blocks like AI Adventure.', 'extrachill-blocks' ) . '</p>';
}

/**
 * Renders the HTML for the OpenAI API Key input field.
 */
function extrachill_blocks_field_openai_api_key_html() {
    $options = get_option( 'extrachill_blocks_options' );
    $api_key = isset( $options['openai_api_key'] ) ? $options['openai_api_key'] : '';
    ?>
    <input type="password" id="extrachill_blocks_openai_api_key" name="extrachill_blocks_options[openai_api_key]" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
    <p class="description"><?php _e( 'Your secret API key for OpenAI services.', 'extrachill-blocks' ); ?></p>
    <?php
}

/**
 * Sanitizes and validates the options before saving.
 *
 * @param array $input The input options.
 * @return array The sanitized options.
 */
function extrachill_blocks_options_validate( $input ) {
    $new_input = [];
    if ( isset( $input['openai_api_key'] ) ) {
        $new_input['openai_api_key'] = sanitize_text_field( $input['openai_api_key'] );
    }
    return $new_input;
}

/**
 * Renders the main HTML wrapper for the settings page.
 */
function extrachill_blocks_options_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'extrachill_blocks_options' );
            do_settings_sections( 'extrachill-blocks' );
            submit_button( __( 'Save Settings', 'extrachill-blocks' ) );
            ?>
        </form>
    </div>
    <?php
}