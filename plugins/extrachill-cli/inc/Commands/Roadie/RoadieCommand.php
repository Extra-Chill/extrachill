<?php
/**
 * Roadie CLI Command
 *
 * Talk to Roadie — the Extra Chill platform chat agent — from the terminal.
 *
 * This is a thin wrapper over the canonical `agents/chat` ability, the exact
 * same turn-runner the frontend chat widget uses. It does NOT reimplement the
 * agent loop, SOUL injection, mode composition, or tool dispatch — it composes
 * the same inputs the Roadie frontend composes (`modes = [chat, roadie]`, the
 * `roadie` agent, the calling user) and prints the reply. Real agent, real
 * session, real tool surface.
 *
 * The `roadie` mode is what activates the EC platform context + tool surface,
 * and `chat` is the conversational base; together they drive Roadie's SOUL
 * identity and role-aware guidance. Session continuity works by passing the
 * returned `--session` back in on the next turn.
 *
 * @package ExtraChill\CLI\Commands\Roadie
 */

namespace ExtraChill\CLI\Commands\Roadie;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoadieCommand {

	/**
	 * Canonical Roadie agent slug.
	 */
	private const AGENT_SLUG = 'roadie';

	/**
	 * Modes the frontend widget composes for Roadie: `chat` base + `roadie`
	 * platform context/tools. Mirrors extrachill-roadie's
	 * extrachill_roadie_compose_modes() so the CLI turn matches the UI turn.
	 */
	private const MODES = array( 'chat', 'roadie' );

	/**
	 * Send a message to Roadie and print the reply.
	 *
	 * Runs one real agent turn through the canonical `agents/chat` ability —
	 * the same path the frontend widget uses — so Roadie answers with its full
	 * SOUL, role-aware guidance, and tool surface. Pass `--session` to continue
	 * an existing conversation; omit it to start a new one (the new session ID
	 * is printed so you can continue it).
	 *
	 * ## OPTIONS
	 *
	 * <message>
	 * : The message to send to Roadie.
	 *
	 * [--session=<id>]
	 * : Existing chat session ID to continue. Omit to start a new session.
	 *
	 * [--user=<id>]
	 * : WordPress user ID to act as (the calling user Roadie operates on behalf
	 * of). Determines Roadie's role tier (public/team/admin) and which
	 * user-scoped data its tools touch. Defaults to the current CLI user.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: pretty
	 * options:
	 *   - pretty
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # One-off question (new session)
	 *     wp extrachill roadie chat "What can you help me with?" --user=1 --url=extrachill.com
	 *
	 *     # Continue a session
	 *     wp extrachill roadie chat "Now list my drafts" --session=57886a62-... --user=1 --url=extrachill.com
	 *
	 *     # Machine-readable output
	 *     wp extrachill roadie chat "hi" --user=1 --format=json --url=extrachill.com
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args: [0] => message.
	 * @param array $assoc_args Flags: session, user, format.
	 */
	public function chat( $args, $assoc_args ) {
		$message = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
		if ( '' === $message ) {
			WP_CLI::error( 'A message is required: wp extrachill roadie chat "<message>"' );
		}

		$ability = wp_get_ability( 'agents/chat' );
		if ( ! $ability ) {
			WP_CLI::error( 'agents/chat ability not available. Is Data Machine (agents-api) active on this site?' );
		}

		$user_id = $this->resolve_user_id( $assoc_args );
		if ( $user_id <= 0 ) {
			WP_CLI::error( 'Could not resolve a calling user. Pass --user=<id>.' );
		}

		$input = array(
			'agent'   => self::AGENT_SLUG,
			'message' => $message,
			'modes'   => self::MODES,
			'user_id' => $user_id,
		);

		$session_id = isset( $assoc_args['session'] ) ? trim( (string) $assoc_args['session'] ) : '';
		if ( '' !== $session_id ) {
			$input['session_id'] = $session_id;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( sprintf( '[%s] %s', $result->get_error_code(), $result->get_error_message() ) );
		}

		$reply       = (string) ( $result['reply'] ?? '' );
		$out_session = (string) ( $result['session_id'] ?? '' );
		$completed   = (bool) ( $result['completed'] ?? true );

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'pretty';
		if ( 'json' === $format ) {
			WP_CLI::print_value(
				array(
					'session_id' => $out_session,
					'reply'      => $reply,
					'completed'  => $completed,
				),
				array( 'format' => 'json' )
			);
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%CRoadie:%n ' ) . $reply );
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( sprintf( '%%y(session: %s)%%n', $out_session ) ) );
		if ( ! $completed ) {
			WP_CLI::warning( 'Turn did not complete (max turns / interrupted). Send another message to continue.' );
		}
	}

	/**
	 * List Roadie chat sessions for a user.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : User whose sessions to list. Defaults to the current CLI user.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill roadie sessions --user=1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Flags: user, format.
	 */
	public function sessions( $args, $assoc_args ) {
		unset( $args );

		$ability = wp_get_ability( 'datamachine/list-chat-sessions' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/list-chat-sessions ability not available. Is Data Machine active on this site?' );
		}

		$user_id = $this->resolve_user_id( $assoc_args );
		if ( $user_id <= 0 ) {
			WP_CLI::error( 'Could not resolve a user. Pass --user=<id>.' );
		}

		$result = $ability->execute( array( 'user_id' => $user_id ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$sessions = is_array( $result['sessions'] ?? null ) ? $result['sessions'] : array();
		if ( empty( $sessions ) ) {
			WP_CLI::log( 'No chat sessions found.' );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$items  = array();
		foreach ( $sessions as $session ) {
			$items[] = array(
				'session_id' => (string) ( $session['session_id'] ?? $session['id'] ?? '' ),
				'title'      => (string) ( $session['title'] ?? '' ),
				'updated'    => (string) ( $session['updated_at'] ?? $session['updated'] ?? '' ),
			);
		}

		Utils\format_items( $format, $items, array( 'session_id', 'title', 'updated' ) );
	}

	/**
	 * Read a Roadie chat session transcript.
	 *
	 * ## OPTIONS
	 *
	 * <session>
	 * : The chat session ID to read.
	 *
	 * [--user=<id>]
	 * : Session owner. Defaults to the current CLI user.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: pretty
	 * options:
	 *   - pretty
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill roadie read 57886a62-... --user=1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args: [0] => session id.
	 * @param array $assoc_args Flags: user, format.
	 */
	public function read( $args, $assoc_args ) {
		$session_id = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
		if ( '' === $session_id ) {
			WP_CLI::error( 'A session ID is required: wp extrachill roadie read <session>' );
		}

		$ability = wp_get_ability( 'datamachine/get-chat-session' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/get-chat-session ability not available. Is Data Machine active on this site?' );
		}

		$user_id = $this->resolve_user_id( $assoc_args );

		$result = $ability->execute(
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$conversation = is_array( $result['conversation'] ?? null ) ? $result['conversation'] : array();

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'pretty';
		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}

		if ( empty( $conversation ) ) {
			WP_CLI::log( 'Session has no readable messages.' );
			return;
		}

		foreach ( $conversation as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$role = (string) ( $message['role'] ?? '' );
			$type = (string) ( $message['type'] ?? '' );
			if ( in_array( $type, array( 'tool_call', 'tool_result' ), true ) ) {
				continue;
			}
			$content = $message['content'] ?? '';
			if ( ! is_string( $content ) || '' === trim( $content ) ) {
				continue;
			}
			$label = 'assistant' === $role
				? WP_CLI::colorize( '%CRoadie:%n ' )
				: WP_CLI::colorize( '%G' . ucfirst( $role ) . ':%n ' );
			WP_CLI::log( $label . $content );
			WP_CLI::log( '' );
		}
	}

	/**
	 * Resolve the calling user ID from --user or the current CLI user.
	 *
	 * @param array $assoc_args Flags.
	 * @return int Resolved user ID (0 if none).
	 */
	private function resolve_user_id( array $assoc_args ): int {
		if ( isset( $assoc_args['user'] ) && is_numeric( $assoc_args['user'] ) ) {
			return (int) $assoc_args['user'];
		}

		$current = get_current_user_id();
		return $current > 0 ? (int) $current : 0;
	}
}
