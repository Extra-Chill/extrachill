<?php
/**
 * API Handler for AI Adventure
 * 
 * Handles REST API requests for the AI Adventure game.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
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
    
    /**
     * Handles the AI Adventure game logic via REST API.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     */
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
    
    /**
     * Extracts and sanitizes parameters from the request.
     *
     * @param array $params Raw request parameters.
     * @return array Sanitized parameters.
     */
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
    
    /**
     * Handles a request for a step's introductory narrative.
     *
     * @param array $params Game parameters.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     */
    private static function handle_introduction_request( $params ) {
        $messages = ExtraChill_Blocks_Prompt_Builder::build_introduction_messages( $params );
        $narrative = ExtraChill_Blocks_OpenAI_Client::call_openai( $messages, 0.7 );

        if ( is_wp_error( $narrative ) ) {
            return $narrative;
        }

        return new WP_REST_Response( array( 'narrative' => $narrative ), 200 );
    }
    
    /**
     * Handles regular conversation turns.
     *
     * @param array $params Game parameters.
     * @param string $progression_section Formatted progression history.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     */
    private static function handle_conversation_turn( $params, $progression_section ) {
        // Get conversation response
        $conversation_messages = ExtraChill_Blocks_Prompt_Builder::build_conversation_messages( $params, $progression_section );
        $narrative_response = ExtraChill_Blocks_OpenAI_Client::call_openai( $conversation_messages, 0.6 );

        if ( is_wp_error( $narrative_response ) ) {
            return $narrative_response;
        }

        // Check for story progression
        $next_step_id = null;
        if ( ! empty( $params['triggers'] ) && is_array( $params['triggers'] ) ) {
            $next_step_id = self::analyze_progression( $params, $progression_section );
        }

        // If we are progressing, send an empty narrative so we don't have duplicate messages.
        // The frontend will immediately call for the new step's introduction.
        $final_narrative = $next_step_id ? '' : $narrative_response;

        return new WP_REST_Response( array( 
            'narrative' => $final_narrative,
            'nextStepId' => $next_step_id  // null means continue current step, non-null means advance
        ), 200 );
    }
    
    /**
     * Analyzes if the player's action should trigger story progression.
     *
     * @param array $params Game parameters.
     * @param string $progression_section Formatted progression history.
     * @return string|null Next step ID if progression should occur, null otherwise.
     */
    private static function analyze_progression( $params, $progression_section ) {
        $progression_messages = ExtraChill_Blocks_Prompt_Builder::build_progression_messages( $params, $progression_section, $params['triggers'] );
        $progression_response = ExtraChill_Blocks_OpenAI_Client::call_openai( $progression_messages, 0.2 );

        if ( is_wp_error( $progression_response ) ) {
            return null; // Don't progress on error
        }

        // Parse JSON response
        $json_start = strpos( $progression_response, '{' );
        if ( $json_start === false ) {
            return null;
        }

        $json_string = substr( $progression_response, $json_start );
        $progression_data = json_decode( $json_string, true );
        
        if ( ! isset( $progression_data['shouldProgress'] ) || ! $progression_data['shouldProgress'] || ! isset( $progression_data['triggerId'] ) ) {
            return null;
        }

        // Find the destination for this trigger
        foreach ( $params['triggers'] as $trigger ) {
            if ( $trigger['id'] == $progression_data['triggerId'] ) {
                return $trigger['destination'];
            }
        }

        return null;
    }
} 