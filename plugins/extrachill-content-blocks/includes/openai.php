<?php
/**
 * OpenAI API Client and Key Management.
 *
 * @package ExtraChillBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Hooks into a custom filter to provide the OpenAI API key from settings.
 *
 * This function is now centralized in the main OpenAI include file.
 *
 * @param string|null $key The default key value.
 * @return string|null The API key from options, or null if not found.
 */
function extrachill_blocks_provide_openai_key( $key ) {
    $options = get_option( 'extrachill_blocks_options' );
    if ( isset( $options['openai_api_key'] ) && ! empty( $options['openai_api_key'] ) ) {
        return $options['openai_api_key'];
    }
    return $key; // Return original value (null) if not found.
}
add_filter( 'extrachill_blocks_get_openai_key', 'extrachill_blocks_provide_openai_key' );


/**
 * Main class for handling OpenAI API communication.
 */
class ExtraChill_Blocks_OpenAI_Client {

    /**
     * Makes a request to the OpenAI API.
     *
     * @param array $messages The array of messages for the chat completion.
     * @param float $temperature The creativity of the response.
     * @return string|WP_Error The message content from the AI or a WP_Error on failure.
     */
    public static function call_openai( $messages, $temperature = 0.2 ) {
        // Get the API key from the plugin settings via our filter.
        $api_key = apply_filters( 'extrachill_blocks_get_openai_key', null );

        if ( empty( $api_key ) ) {
            return new WP_Error( 'api_key_missing', 'OpenAI API key is not configured.', array( 'status' => 500 ) );
        }

        $api_url = 'https://api.openai.com/v1/chat/completions';

        $body = array(
            'model'       => 'gpt-4',
            'messages'    => $messages,
            'temperature' => $temperature,
        );

        $response = wp_remote_post( $api_url, array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => json_encode( $body ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'openai_request_failed', 'Failed to connect to OpenAI.', array( 'status' => 500 ) );
        }

        $response_body = wp_remote_retrieve_body( $response );
        $response_data = json_decode( $response_body, true );

        if ( isset( $response_data['error'] ) ) {
            return new WP_Error( 'openai_api_error', $response_data['error']['message'], array( 'status' => 500 ) );
        }

        if ( ! isset( $response_data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'openai_unexpected_response', 'Unexpected response format from OpenAI.', array( 'status' => 500 ) );
        }

        return $response_data['choices'][0]['message']['content'];
    }
}