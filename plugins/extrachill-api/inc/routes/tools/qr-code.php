<?php
/**
 * QR Code Generator REST API Endpoint
 *
 * Generates high-resolution print-ready QR codes for any URL.
 * Uses Endroid QR Code library for PNG generation.
 *
 * @endpoint POST /wp-json/extrachill/v1/tools/qr-code
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_qr_code_route' );

/**
 * Registers the QR code generation endpoint.
 */
function extrachill_api_register_qr_code_route() {
    register_rest_route(
        'extrachill/v1',
        '/tools/qr-code',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'extrachill_api_generate_qr_code',
            'permission_callback' => '__return_true',
            'args'                => array(
                'url'  => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => 'extrachill_api_validate_qr_url',
                    'description'       => 'The URL to encode in the QR code.',
                ),
                'size' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 1000,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'extrachill_api_validate_qr_size',
                    'description'       => 'QR code size in pixels (default: 1000, max: 2000).',
                ),
            ),
        )
    );
}

/**
 * Validates the URL parameter.
 *
 * @param string $url The URL to validate.
 * @return bool|WP_Error True if valid, WP_Error otherwise.
 */
function extrachill_api_validate_qr_url( $url ) {
    if ( empty( $url ) ) {
        return new WP_Error(
            'missing_url',
            'URL is required.',
            array( 'status' => 400 )
        );
    }

    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return new WP_Error(
            'invalid_url',
            'Please provide a valid URL.',
            array( 'status' => 400 )
        );
    }

    return true;
}

/**
 * Validates the size parameter.
 *
 * @param int $size The size to validate.
 * @return bool|WP_Error True if valid, WP_Error otherwise.
 */
function extrachill_api_validate_qr_size( $size ) {
    $size = absint( $size );

    if ( $size < 100 ) {
        return new WP_Error(
            'size_too_small',
            'Size must be at least 100 pixels.',
            array( 'status' => 400 )
        );
    }

    if ( $size > 2000 ) {
        return new WP_Error(
            'size_too_large',
            'Size cannot exceed 2000 pixels.',
            array( 'status' => 400 )
        );
    }

    return true;
}

/**
 * Generates a QR code for the provided URL via Abilities API primitive.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with QR code data URI or error.
 */
function extrachill_api_generate_qr_code( $request ) {
    $ability = wp_get_ability( 'extrachill/generate-qr-code' );
    if ( ! $ability ) {
        return new WP_Error(
            'ability_missing',
            'QR code ability is not available.',
            array( 'status' => 500 )
        );
    }

    $url  = $request->get_param( 'url' );
    $size = $request->get_param( 'size' );

    $input = array(
        'url' => $url,
    );

    if ( null !== $size ) {
        $input['size'] = absint( $size );
    }

    $result = $ability->execute( $input );
    if ( is_wp_error( $result ) ) {
        $result->add_data( array( 'status' => 500 ) );
        return $result;
    }

    if ( empty( $result['image'] ) || ! is_string( $result['image'] ) ) {
        return new WP_Error(
            'generation_failed',
            'QR generation returned invalid image payload.',
            array( 'status' => 500 )
        );
    }

    $data_uri = 'data:image/png;base64,' . $result['image'];

    return rest_ensure_response( array(
        'image_url' => $data_uri,
        'url'       => $result['url'] ?? $url,
        'size'      => $result['size'] ?? ( $size ?: 1000 ),
    ) );
}
