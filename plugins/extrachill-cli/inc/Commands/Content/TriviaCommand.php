<?php
/**
 * Trivia CLI Commands
 *
 * Thin wrappers over the extrachill/trivia-create and extrachill/trivia-list
 * abilities (registered by extrachill-content-blocks). These commands carry NO
 * business logic and NO question generation — they parse arguments, read a
 * JSON payload of authored questions, and delegate to the abilities, which own
 * serialization and persistence.
 *
 * The research-and-author loop happens upstream of this command: a human or
 * agent assembles the questions JSON, and this command serializes it into
 * trivia blocks deterministically.
 *
 * @package ExtraChill\CLI\Commands\Content
 */

namespace ExtraChill\CLI\Commands\Content;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TriviaCommand {

	/**
	 * Create a trivia quiz, or append questions to an existing post.
	 *
	 * Reads a JSON array of questions (from a file, or stdin with `-`) and
	 * delegates to the extrachill/trivia-create ability, which serializes each
	 * question into an extrachill/trivia block and writes it to a post.
	 *
	 * Each question object supports:
	 *   - question (string, required)
	 *   - options (string[], required, 2+)
	 *   - correctAnswer (int, required, zero-based index into options)
	 *   - answerJustification (string, optional)
	 *   - resultMessages (object, optional: excellent/good/okay/poor)
	 *   - scoreRanges (object, optional: excellent/good/okay)
	 *
	 * ## OPTIONS
	 *
	 * --questions=<file>
	 * : Path to a JSON file containing an array of question objects, or `-`
	 * to read the JSON from stdin.
	 *
	 * [--post-id=<post_id>]
	 * : Existing post ID to append the trivia blocks to. Omit to create a new
	 * post (then --title is required).
	 *
	 * [--title=<title>]
	 * : Title for a newly created post. Required when --post-id is omitted.
	 *
	 * [--status=<status>]
	 * : Status for a newly created post.
	 * ---
	 * default: draft
	 * options:
	 *   - draft
	 *   - pending
	 *   - publish
	 *   - private
	 * ---
	 *
	 * [--post-type=<post_type>]
	 * : Post type for a newly created post.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--result-messages=<json>]
	 * : Quiz-wide default score-tier messages as a JSON object
	 * (excellent/good/okay/poor), applied to every question that lacks its own.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a new draft quiz from a JSON file
	 *     wp extrachill content quiz --questions=gd-quiz.json --title="Grateful Dead Trivia"
	 *
	 *     # Append questions to the existing Grateful Dead quiz post
	 *     wp extrachill content quiz --questions=more.json --post-id=96350
	 *
	 *     # Pipe questions in from stdin
	 *     cat questions.json | wp extrachill content quiz --questions=- --title="Music Trivia"
	 *
	 * @subcommand quiz
	 * @when after_wp_load
	 */
	public function quiz( $args, $assoc_args ) {
		$questions = $this->read_questions_json( $assoc_args['questions'] ?? '' );

		$input = array( 'questions' => $questions );

		if ( isset( $assoc_args['post-id'] ) ) {
			$input['post_id'] = absint( $assoc_args['post-id'] );
		}
		if ( isset( $assoc_args['title'] ) ) {
			$input['post_title'] = $assoc_args['title'];
		}
		if ( isset( $assoc_args['status'] ) ) {
			$input['post_status'] = $assoc_args['status'];
		}
		if ( isset( $assoc_args['post-type'] ) ) {
			$input['post_type'] = $assoc_args['post-type'];
		}
		if ( isset( $assoc_args['result-messages'] ) ) {
			$input['result_messages'] = $this->decode_json_object(
				$assoc_args['result-messages'],
				'--result-messages'
			);
		}

		$ability = wp_get_ability( 'extrachill/trivia-create' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/trivia-create ability not available — is extrachill-content-blocks active?' );
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$verb = ! empty( $result['created'] ) ? 'created' : 'updated';
		WP_CLI::success(
			sprintf(
				'Quiz %s: post %d (%d question%s added). %s',
				$verb,
				$result['post_id'],
				$result['questions_added'],
				1 === (int) $result['questions_added'] ? '' : 's',
				$result['post_url']
			)
		);

		if ( ( $assoc_args['format'] ?? 'table' ) === 'json' ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * List the trivia questions stored in a post.
	 *
	 * Thin wrapper over the extrachill/trivia-list ability. Reads the
	 * extrachill/trivia blocks out of a post and prints their parsed
	 * attributes in document order — useful for auditing a quiz before
	 * appending to it.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post ID to read trivia blocks from.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill content quiz-list 96350
	 *     wp extrachill content quiz-list 96350 --format=json
	 *     wp extrachill content quiz-list 96350 --format=count
	 *
	 * @subcommand quiz-list
	 * @when after_wp_load
	 */
	public function quiz_list( $args, $assoc_args ) {
		$post_id = absint( $args[0] ?? 0 );
		if ( $post_id <= 0 ) {
			WP_CLI::error( 'A valid post ID is required.' );
		}

		$ability = wp_get_ability( 'extrachill/trivia-list' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/trivia-list ability not available — is extrachill-content-blocks active?' );
		}

		$result = $ability->execute( array( 'post_id' => $post_id ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'count' === $format ) {
			WP_CLI::log( (string) $result['count'] );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 0 === (int) $result['count'] ) {
			WP_CLI::log( sprintf( 'No trivia blocks found in post %d.', $post_id ) );
			return;
		}

		$rows = array();
		foreach ( $result['questions'] as $i => $q ) {
			$options = isset( $q['options'] ) && is_array( $q['options'] ) ? $q['options'] : array();
			$correct = isset( $q['correctAnswer'] ) ? (int) $q['correctAnswer'] : 0;
			$rows[]  = array(
				'#'        => $i + 1,
				'question' => isset( $q['question'] ) ? wp_strip_all_tags( (string) $q['question'] ) : '',
				'options'  => count( $options ),
				'answer'   => $options[ $correct ] ?? '',
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( '#', 'question', 'options', 'answer' ) );
	}

	/**
	 * Read and decode the --questions JSON payload into an array of questions.
	 *
	 * Accepts a file path, or `-` to read from stdin. Validates that the
	 * decoded value is a non-empty JSON array.
	 *
	 * @param string $source File path, or '-' for stdin.
	 * @return array<int, array<string, mixed>>
	 */
	private function read_questions_json( $source ) {
		if ( '' === $source ) {
			WP_CLI::error( '--questions is required (a JSON file path, or `-` for stdin).' );
		}

		if ( '-' === $source ) {
			$raw = file_get_contents( 'php://stdin' );
		} else {
			if ( ! is_readable( $source ) ) {
				WP_CLI::error( sprintf( 'Cannot read questions file: %s', $source ) );
			}
			$raw = file_get_contents( $source );
		}

		if ( false === $raw || '' === trim( (string) $raw ) ) {
			WP_CLI::error( 'No JSON content found in --questions input.' );
		}

		$decoded = json_decode( $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			WP_CLI::error( sprintf( 'Invalid JSON in --questions: %s', json_last_error_msg() ) );
		}

		if ( ! is_array( $decoded ) || empty( $decoded ) || array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) {
			WP_CLI::error( '--questions must be a non-empty JSON array of question objects.' );
		}

		return $decoded;
	}

	/**
	 * Decode a JSON object argument (e.g. --result-messages).
	 *
	 * @param string $raw  Raw JSON string.
	 * @param string $flag Flag name for error messages.
	 * @return array<string, mixed>
	 */
	private function decode_json_object( $raw, $flag ) {
		$decoded = json_decode( (string) $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			WP_CLI::error( sprintf( 'Invalid JSON in %s: %s', $flag, json_last_error_msg() ) );
		}
		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( sprintf( '%s must be a JSON object.', $flag ) );
		}
		return $decoded;
	}
}
