<?php
/**
 * REST route: POST /wp-json/extrachill/v1/blog/ai-adventure
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_blog_ai_adventure_route' );

function extrachill_api_register_blog_ai_adventure_route() {
    register_rest_route( 'extrachill/v1', '/blog/ai-adventure', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => array( 'ExtraChill_API_Blog_AI_Adventure', 'handle_request' ),
        'permission_callback' => '__return_true',
    ) );
}

class ExtraChill_API_Blog_AI_Adventure {
    public static function handle_request( WP_REST_Request $request ) {
        $dependency_check = self::ensure_prompt_builder_available();

        if ( is_wp_error( $dependency_check ) ) {
            return $dependency_check;
        }

        $params = $request->get_json_params();

        $game_params = self::extract_and_sanitize_params( $params );
        $progression_section = ExtraChill_Blog_Prompt_Builder::build_progression_section( $game_params['progression_history'] );

        if ( ! empty( $game_params['is_introduction'] ) ) {
            return self::handle_introduction_request( $game_params );
        }

        return self::handle_conversation_turn( $game_params, $progression_section );
    }

    private static function ensure_prompt_builder_available() {
        if ( ! defined( 'EXTRACHILL_BLOG_PLUGIN_DIR' ) ) {
            return new WP_Error( 'extrachill_blog_missing', 'ExtraChill Blog plugin is required for AI adventure.', array( 'status' => 500 ) );
        }

        $builder_path = EXTRACHILL_BLOG_PLUGIN_DIR . 'src/blocks/ai-adventure/includes/prompt-builder.php';

        if ( ! file_exists( $builder_path ) ) {
            return new WP_Error( 'prompt_builder_missing', 'AI adventure prompt builder not found.', array( 'status' => 500 ) );
        }

        require_once $builder_path;

        if ( ! class_exists( 'ExtraChill_Blog_Prompt_Builder' ) ) {
            return new WP_Error( 'prompt_builder_unavailable', 'AI adventure prompt builder class unavailable.', array( 'status' => 500 ) );
        }

        return true;
    }

    private static function extract_and_sanitize_params( $params ) {
        return array(
            'is_introduction'      => ! empty( $params['isIntroduction'] ),
            'character_name'       => sanitize_text_field( $params['characterName'] ?? '' ),
            'adventure_title'      => sanitize_text_field( $params['adventureTitle'] ?? '' ),
            'adventure_prompt'     => sanitize_textarea_field( $params['adventurePrompt'] ?? '' ),
            'path_prompt'          => sanitize_textarea_field( $params['pathPrompt'] ?? '' ),
            'step_prompt'          => sanitize_textarea_field( $params['stepPrompt'] ?? '' ),
            'persona'              => sanitize_textarea_field( $params['gameMasterPersona'] ?? '' ),
            'progression_history'  => ( isset( $params['storyProgression'] ) && is_array( $params['storyProgression'] ) ) ? $params['storyProgression'] : array(),
            'player_input'         => sanitize_text_field( $params['playerInput'] ?? '' ),
            'triggers'             => ( isset( $params['triggers'] ) && is_array( $params['triggers'] ) ) ? $params['triggers'] : array(),
            'conversation_history' => ( isset( $params['conversationHistory'] ) && is_array( $params['conversationHistory'] ) ) ? $params['conversationHistory'] : array(),
            'transition_context'   => ( isset( $params['transitionContext'] ) && is_array( $params['transitionContext'] ) ) ? $params['transitionContext'] : array(),
        );
    }

    private static function handle_introduction_request( $params ) {
        $messages = ExtraChill_Blog_Prompt_Builder::build_introduction_messages( $params );

        $response = apply_filters( 'chubes_ai_request', array(
            'messages' => $messages,
            'model'    => 'gpt-5-nano',
        ), 'openai' );

        if ( empty( $response['success'] ) ) {
            return new WP_Error( 'chubes_ai_request_failed', $response['error'] ?? 'Unknown AI error.', array( 'status' => 500 ) );
        }

        $narrative = $response['data']['choices'][0]['message']['content'] ?? '';

        return new WP_REST_Response( array( 'narrative' => $narrative ), 200 );
    }

    private static function handle_conversation_turn( $params, $progression_section ) {
        $conversation_messages = ExtraChill_Blog_Prompt_Builder::build_conversation_messages( $params, $progression_section );

        $response = apply_filters( 'chubes_ai_request', array(
            'messages' => $conversation_messages,
            'model'    => 'gpt-5-nano',
        ), 'openai' );

        if ( empty( $response['success'] ) ) {
            return new WP_Error( 'chubes_ai_request_failed', $response['error'] ?? 'Unknown AI error.', array( 'status' => 500 ) );
        }

        $narrative_response = $response['data']['choices'][0]['message']['content'] ?? '';

        $next_step_id = null;
        if ( ! empty( $params['triggers'] ) ) {
            $next_step_id = self::analyze_progression( $params, $progression_section );
        }

        $final_narrative = $next_step_id ? '' : $narrative_response;

        return new WP_REST_Response( array(
            'narrative'  => $final_narrative,
            'nextStepId' => $next_step_id,
        ), 200 );
    }

    private static function analyze_progression( $params, $progression_section ) {
        $progression_messages = ExtraChill_Blog_Prompt_Builder::build_progression_messages( $params, $progression_section, $params['triggers'] );

        $response = apply_filters( 'chubes_ai_request', array(
            'messages' => $progression_messages,
            'model'    => 'gpt-5-nano',
        ), 'openai' );

        if ( empty( $response['success'] ) ) {
            return null;
        }

        $progression_response = $response['data']['choices'][0]['message']['content'] ?? '';

        $json_start = strpos( $progression_response, '{' );
        if ( false === $json_start ) {
            return null;
        }

        $json_string = substr( $progression_response, $json_start );
        $progression_data = json_decode( $json_string, true );

        if ( empty( $progression_data['shouldProgress'] ) || empty( $progression_data['triggerId'] ) ) {
            return null;
        }

        foreach ( $params['triggers'] as $trigger ) {
            if ( isset( $trigger['id'] ) && $trigger['id'] === $progression_data['triggerId'] ) {
                return $trigger['destination'] ?? null;
            }
        }

        return null;
    }
}
