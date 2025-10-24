<?php
/**
 * AI Adventure REST API handler
 * Endpoint: /wp-json/extrachill-blocks/v1/adventure
 * Uses ai_request filter from ExtraChill AI Client plugin
 * Provider: openai, Model: gpt-5-nano (hardcoded lines 83, 106, 143)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'prompt-builder.php';

add_action( 'rest_api_init', function () {
    register_rest_route( 'extrachill-blocks/v1', '/adventure', array(
        'methods'  => 'POST',
        'callback' => array( 'ExtraChill_Blocks_AI_Adventure_API', 'handle_adventure_request' ),
        'permission_callback' => '__return_true',
    ) );
} );

class ExtraChill_Blocks_AI_Adventure_API {

    public static function handle_adventure_request( $request ) {
        $params = $request->get_json_params();
        
        // Extract and sanitize parameters
        $game_params = self::extract_and_sanitize_params( $params );
        
        // Build progression section
        $progression_section = ExtraChill_Blocks_Prompt_Builder::build_progression_section( $game_params['progression_history'] );
        
        // Route to appropriate handler based on game state
        if ( ! empty( $game_params['is_introduction'] ) ) {
            return self::handle_introduction_request( $game_params );
        } else {
            return self::handle_conversation_turn( $game_params, $progression_section );
        }
    }
    
    private static function extract_and_sanitize_params( $params ) {
        return array(
            'is_introduction' => isset( $params['isIntroduction'] ) && $params['isIntroduction'],
            'character_name' => sanitize_text_field( $params['characterName'] ?? '' ),
            'adventure_title' => sanitize_text_field( $params['adventureTitle'] ?? '' ),
            'adventure_prompt' => sanitize_textarea_field( $params['adventurePrompt'] ?? '' ),
            'path_prompt' => sanitize_textarea_field( $params['pathPrompt'] ?? '' ),
            'step_prompt' => sanitize_textarea_field( $params['stepPrompt'] ?? '' ),
            'persona' => sanitize_textarea_field( $params['gameMasterPersona'] ?? '' ),
            'progression_history' => isset( $params['storyProgression'] ) && is_array( $params['storyProgression'] ) ? $params['storyProgression'] : array(),
            'player_input' => sanitize_text_field( $params['playerInput'] ?? '' ),
            'triggers' => $params['triggers'] ?? array(),
            'conversation_history' => isset( $params['conversationHistory'] ) && is_array( $params['conversationHistory'] ) ? $params['conversationHistory'] : array(),
            'transition_context' => isset( $params['transitionContext'] ) && is_array( $params['transitionContext'] ) ? $params['transitionContext'] : array(),
        );
    }
    
    private static function handle_introduction_request( $params ) {
        $messages = ExtraChill_Blocks_Prompt_Builder::build_introduction_messages( $params );

        $response = apply_filters( 'ai_request', array(
            'messages' => $messages,
            'model' => 'gpt-5-nano'
        ), 'openai' );

        if ( ! $response['success'] ) {
            return new WP_Error( 'ai_request_failed', $response['error'], array( 'status' => 500 ) );
        }

        $narrative = $response['data']['choices'][0]['message']['content'];

        return new WP_REST_Response( array( 'narrative' => $narrative ), 200 );
    }
    
    private static function handle_conversation_turn( $params, $progression_section ) {
        $conversation_messages = ExtraChill_Blocks_Prompt_Builder::build_conversation_messages( $params, $progression_section );

        $response = apply_filters( 'ai_request', array(
            'messages' => $conversation_messages,
            'model' => 'gpt-5-nano'
        ), 'openai' );

        if ( ! $response['success'] ) {
            return new WP_Error( 'ai_request_failed', $response['error'], array( 'status' => 500 ) );
        }

        $narrative_response = $response['data']['choices'][0]['message']['content'];

        $next_step_id = null;
        if ( ! empty( $params['triggers'] ) && is_array( $params['triggers'] ) ) {
            $next_step_id = self::analyze_progression( $params, $progression_section );
        }

        // Empty narrative on progression prevents duplicate messages (frontend requests new step introduction)
        $final_narrative = $next_step_id ? '' : $narrative_response;

        return new WP_REST_Response( array(
            'narrative' => $final_narrative,
            'nextStepId' => $next_step_id
        ), 200 );
    }
    
    private static function analyze_progression( $params, $progression_section ) {
        $progression_messages = ExtraChill_Blocks_Prompt_Builder::build_progression_messages( $params, $progression_section, $params['triggers'] );

        $response = apply_filters( 'ai_request', array(
            'messages' => $progression_messages,
            'model' => 'gpt-5-nano'
        ), 'openai' );

        if ( ! $response['success'] ) {
            return null;
        }

        $progression_response = $response['data']['choices'][0]['message']['content'];

        $json_start = strpos( $progression_response, '{' );
        if ( $json_start === false ) {
            return null;
        }

        $json_string = substr( $progression_response, $json_start );
        $progression_data = json_decode( $json_string, true );

        if ( ! isset( $progression_data['shouldProgress'] ) || ! $progression_data['shouldProgress'] || ! isset( $progression_data['triggerId'] ) ) {
            return null;
        }

        foreach ( $params['triggers'] as $trigger ) {
            if ( $trigger['id'] == $progression_data['triggerId'] ) {
                return $trigger['destination'];
            }
        }

        return null;
    }
} 