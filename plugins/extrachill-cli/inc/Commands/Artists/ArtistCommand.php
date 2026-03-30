<?php
/**
 * Artist CLI Commands
 *
 * Delegates to artist platform abilities for profile and link page management.
 *
 * @package ExtraChill\CLI\Commands\Artists
 */

namespace ExtraChill\CLI\Commands\Artists;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtistCommand {

	/**
	 * Get artist profile data.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists get 13610
	 *     wp extrachill artists get 13610 --format=json
	 *
	 * @subcommand get
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {


		$artist_id = absint( $args[0] );
		$ability   = $this->get_ability( 'extrachill/get-artist-data' );
		$result    = $ability->execute( array( 'artist_id' => $artist_id ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} else {
			$display = array();
			foreach ( $result as $key => $value ) {
				$display[] = array(
					'Field' => $key,
					'Value' => is_null( $value ) ? '(null)' : (string) $value,
				);
			}
			Utils\format_items( 'table', $display, array( 'Field', 'Value' ) );
		}
	}

	/**
	 * Create a new artist profile.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Artist name.
	 *
	 * [--bio=<bio>]
	 * : Artist bio (HTML allowed).
	 *
	 * [--local-city=<city>]
	 * : Local city/scene.
	 *
	 * [--genre=<genre>]
	 * : Genre.
	 *
	 * [--user-id=<user_id>]
	 * : User ID to link. Defaults to current user (1 in CLI).
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
	 *     wp extrachill artists create "The Grateful Dead" --genre=Rock --local-city="San Francisco"
	 *
	 * @subcommand create
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ) {


		$input = array( 'name' => $args[0] );

		if ( isset( $assoc_args['bio'] ) ) {
			$input['bio'] = $assoc_args['bio'];
		}
		if ( isset( $assoc_args['local-city'] ) ) {
			$input['local_city'] = $assoc_args['local-city'];
		}
		if ( isset( $assoc_args['genre'] ) ) {
			$input['genre'] = $assoc_args['genre'];
		}
		if ( isset( $assoc_args['user-id'] ) ) {
			$input['user_id'] = absint( $assoc_args['user-id'] );
		}

		$ability = $this->get_ability( 'extrachill/create-artist' );
		$result  = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Artist created: %s (ID: %d)', $result['name'], $result['id'] ) );

		$format = $assoc_args['format'] ?? 'table';
		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Update an existing artist profile.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * [--name=<name>]
	 * : New artist name.
	 *
	 * [--bio=<bio>]
	 * : New artist bio.
	 *
	 * [--local-city=<city>]
	 * : Local city/scene. Empty string to clear.
	 *
	 * [--genre=<genre>]
	 * : Genre. Empty string to clear.
	 *
	 * [--profile-image-id=<id>]
	 * : Profile image attachment ID. 0 to remove.
	 *
	 * [--header-image-id=<id>]
	 * : Header image attachment ID. 0 to remove.
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
	 *     wp extrachill artists update 13610 --name="New Name" --genre=Jazz
	 *     wp extrachill artists update 13610 --local-city="" --format=json
	 *
	 * @subcommand update
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ) {


		$input = array( 'artist_id' => absint( $args[0] ) );

		$field_map = array(
			'name'             => 'name',
			'bio'              => 'bio',
			'local-city'       => 'local_city',
			'genre'            => 'genre',
			'profile-image-id' => 'profile_image_id',
			'header-image-id'  => 'header_image_id',
		);

		foreach ( $field_map as $cli_key => $input_key ) {
			if ( isset( $assoc_args[ $cli_key ] ) ) {
				$input[ $input_key ] = $assoc_args[ $cli_key ];
			}
		}

		if ( count( $input ) < 2 ) {
			WP_CLI::error( 'At least one field to update is required.' );
		}

		$ability = $this->get_ability( 'extrachill/update-artist' );
		$result  = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Artist updated: %s (ID: %d)', $result['name'], $result['id'] ) );

		$format = $assoc_args['format'] ?? 'table';
		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Get link page data for an artist.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists link-page 13610
	 *     wp extrachill artists link-page 13610 --format=yaml
	 *
	 * @subcommand link-page
	 * @when after_wp_load
	 */
	public function link_page( $args, $assoc_args ) {


		$artist_id = absint( $args[0] );
		$ability   = $this->get_ability( 'extrachill/get-link-page-data' );
		$result    = $ability->execute( array( 'artist_id' => $artist_id ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'json';

		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} elseif ( $format === 'yaml' ) {
			WP_CLI::log( \Spyc::YAMLDump( $result, false, false, true ) );
		}
	}

	/**
	 * Save social links for an artist.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * <json>
	 * : JSON array of social link objects, each with "type" and "url".
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists save-socials 13610 '[{"type":"instagram","url":"https://instagram.com/moosch"}]'
	 *
	 * @subcommand save-socials
	 * @when after_wp_load
	 */
	public function save_socials( $args, $assoc_args ) {


		$artist_id    = absint( $args[0] );
		$social_links = json_decode( $args[1], true );

		if ( ! is_array( $social_links ) ) {
			WP_CLI::error( 'Second argument must be a valid JSON array of social link objects.' );
		}

		$ability = $this->get_ability( 'extrachill/save-social-links' );
		$result  = $ability->execute(
			array(
				'artist_id'    => $artist_id,
				'social_links' => $social_links,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$count = count( $result['social_links'] ?? array() );
		WP_CLI::success( sprintf( 'Saved %d social link(s) for artist %d.', $count, $artist_id ) );

		$format = $assoc_args['format'] ?? 'json';
		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Save link page links (full replacement).
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * <json>
	 * : JSON array of link sections. Each section: { section_title, links: [{ link_text, link_url }] }.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists save-links 12180 '[{"section_title":"","links":[{"link_text":"Home","link_url":"https://example.com"}]}]'
	 *
	 * @subcommand save-links
	 * @when after_wp_load
	 */
	public function save_links( $args, $assoc_args ) {


		$artist_id = absint( $args[0] );
		$links     = json_decode( $args[1], true );

		if ( ! is_array( $links ) ) {
			WP_CLI::error( 'Second argument must be a valid JSON array of link sections.' );
		}

		$ability = $this->get_ability( 'extrachill/save-link-page-links' );
		$result  = $ability->execute(
			array(
				'artist_id' => $artist_id,
				'links'     => $links,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$total_links = 0;
		foreach ( $result['links'] ?? array() as $section ) {
			$total_links += count( $section['links'] ?? array() );
		}

		WP_CLI::success( sprintf( 'Saved %d link(s) for artist %d.', $total_links, $artist_id ) );
		WP_CLI::log( wp_json_encode( $result['links'], JSON_PRETTY_PRINT ) );
	}

	/**
	 * Add a single link to an artist's link page.
	 *
	 * Reads existing links, appends the new one to the specified section
	 * (default: first section), and saves.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * <url>
	 * : Link URL.
	 *
	 * <text>
	 * : Link display text.
	 *
	 * [--section=<index>]
	 * : Section index to add to (0-based). Default: 0.
	 *
	 * [--section-title=<title>]
	 * : If section doesn't exist, create it with this title.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists add-link 12180 "https://extrachill.com/grateful-dead-trivia" "Grateful Dead Trivia"
	 *     wp extrachill artists add-link 12180 "https://extrachill.com/page" "Page Title" --section=1 --section-title="More Reading"
	 *
	 * @subcommand add-link
	 * @when after_wp_load
	 */
	public function add_link( $args, $assoc_args ) {


		$artist_id = absint( $args[0] );
		$url       = $args[1];
		$text      = $args[2];
		$section   = absint( $assoc_args['section'] ?? 0 );

		// Read current links.
		$read_ability = $this->get_ability( 'extrachill/get-link-page-data' );
		$current      = $read_ability->execute( array( 'artist_id' => $artist_id ) );

		if ( is_wp_error( $current ) ) {
			WP_CLI::error( $current->get_error_message() );
		}

		$links = $current['links'] ?? array();

		// Ensure the target section exists.
		while ( count( $links ) <= $section ) {
			$links[] = array(
				'section_title' => $assoc_args['section-title'] ?? '',
				'links'         => array(),
			);
		}

		// Check for duplicate URL in the target section.
		foreach ( $links[ $section ]['links'] as $existing ) {
			if ( $existing['link_url'] === $url ) {
				WP_CLI::warning( sprintf( 'Link already exists in section %d: %s', $section, $url ) );
				return;
			}
		}

		// Append the new link.
		$links[ $section ]['links'][] = array(
			'link_text' => $text,
			'link_url'  => $url,
		);

		// Save.
		$save_ability = $this->get_ability( 'extrachill/save-link-page-links' );
		$result       = $save_ability->execute(
			array(
				'artist_id' => $artist_id,
				'links'     => $links,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$total_links = 0;
		foreach ( $result['links'] ?? array() as $s ) {
			$total_links += count( $s['links'] ?? array() );
		}

		WP_CLI::success( sprintf( 'Added "%s" → %s (section %d, %d total links).', $text, $url, $section, $total_links ) );
	}

	/**
	 * Remove a link from an artist's link page by link ID or URL.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * <identifier>
	 * : Link ID (e.g. "link_123_abc") or URL to remove.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists remove-link 12180 "link_1756700193_EoSCFg6vB"
	 *     wp extrachill artists remove-link 12180 "https://extrachill.com/some-page/"
	 *
	 * @subcommand remove-link
	 * @when after_wp_load
	 */
	public function remove_link( $args, $assoc_args ) {


		$artist_id  = absint( $args[0] );
		$identifier = $args[1];

		// Read current links.
		$read_ability = $this->get_ability( 'extrachill/get-link-page-data' );
		$current      = $read_ability->execute( array( 'artist_id' => $artist_id ) );

		if ( is_wp_error( $current ) ) {
			WP_CLI::error( $current->get_error_message() );
		}

		$links = $current['links'] ?? array();
		$found = false;

		foreach ( $links as $si => $section ) {
			foreach ( $section['links'] as $li => $link ) {
				$match = ( isset( $link['id'] ) && $link['id'] === $identifier )
					|| $link['link_url'] === $identifier;

				if ( $match ) {
					$removed_text = $link['link_text'];
					array_splice( $links[ $si ]['links'], $li, 1 );
					$found = true;
					break 2;
				}
			}
		}

		if ( ! $found ) {
			WP_CLI::error( sprintf( 'No link found matching "%s".', $identifier ) );
		}

		// Save.
		$save_ability = $this->get_ability( 'extrachill/save-link-page-links' );
		$result       = $save_ability->execute(
			array(
				'artist_id' => $artist_id,
				'links'     => $links,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Removed link: "%s".', $removed_text ) );
	}

	/**
	 * Move a link to a different position within or across sections.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * <identifier>
	 * : Link ID (e.g. "link_123_abc") or URL to move.
	 *
	 * [--to-section=<index>]
	 * : Target section index (0-based). Default: same section.
	 *
	 * [--to-position=<index>]
	 * : Target position within section (0-based). Default: end.
	 *
	 * ## EXAMPLES
	 *
	 *     # Move a link to the top of its section
	 *     wp extrachill artists move-link 12180 "https://extrachill.com/page/" --to-position=0
	 *
	 *     # Move a link to a different section
	 *     wp extrachill artists move-link 12180 "link_123_abc" --to-section=1 --to-position=0
	 *
	 * @subcommand move-link
	 * @when after_wp_load
	 */
	public function move_link( $args, $assoc_args ) {


		$artist_id  = absint( $args[0] );
		$identifier = $args[1];

		// Read current links.
		$read_ability = $this->get_ability( 'extrachill/get-link-page-data' );
		$current      = $read_ability->execute( array( 'artist_id' => $artist_id ) );

		if ( is_wp_error( $current ) ) {
			WP_CLI::error( $current->get_error_message() );
		}

		$links      = $current['links'] ?? array();
		$found      = false;
		$found_link = null;
		$from_si    = null;

		// Find and remove the link from its current position.
		foreach ( $links as $si => $section ) {
			foreach ( $section['links'] as $li => $link ) {
				$match = ( isset( $link['id'] ) && $link['id'] === $identifier )
					|| $link['link_url'] === $identifier;

				if ( $match ) {
					$found_link = $link;
					$from_si    = $si;
					array_splice( $links[ $si ]['links'], $li, 1 );
					$found = true;
					break 2;
				}
			}
		}

		if ( ! $found ) {
			WP_CLI::error( sprintf( 'No link found matching "%s".', $identifier ) );
		}

		$to_section = isset( $assoc_args['to-section'] ) ? absint( $assoc_args['to-section'] ) : $from_si;

		// Ensure target section exists.
		while ( count( $links ) <= $to_section ) {
			$links[] = array(
				'section_title' => '',
				'links'         => array(),
			);
		}

		$target_links = &$links[ $to_section ]['links'];

		if ( isset( $assoc_args['to-position'] ) ) {
			$to_pos = absint( $assoc_args['to-position'] );
			$to_pos = min( $to_pos, count( $target_links ) );
			array_splice( $target_links, $to_pos, 0, array( $found_link ) );
		} else {
			$target_links[] = $found_link;
		}

		// Save.
		$save_ability = $this->get_ability( 'extrachill/save-link-page-links' );
		$result       = $save_ability->execute(
			array(
				'artist_id' => $artist_id,
				'links'     => $links,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf(
			'Moved "%s" to section %d, position %d.',
			$found_link['link_text'],
			$to_section,
			isset( $assoc_args['to-position'] ) ? $to_pos : count( $target_links ) - 1
		) );
	}

	/**
	 * Save link page styles (CSS variables).
	 *
	 * Pass individual CSS variables as flags. Use --list to see all current values.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * [--list]
	 * : Show current CSS variables instead of saving.
	 *
	 * [--button-bg=<color>]
	 * : Button background color.
	 *
	 * [--button-hover-bg=<color>]
	 * : Button hover background color.
	 *
	 * [--button-text=<color>]
	 * : Button text color (--link-page-link-text-color).
	 *
	 * [--button-hover-text=<color>]
	 * : Button hover text color.
	 *
	 * [--button-border=<color>]
	 * : Button border color.
	 *
	 * [--button-radius=<value>]
	 * : Button border radius (e.g. "8px").
	 *
	 * [--bg-color=<color>]
	 * : Page background color.
	 *
	 * [--text-color=<color>]
	 * : Page text color.
	 *
	 * [--css-var=<value>]
	 * : Set any CSS variable directly (key=value format, repeatable). E.g. --css-var="--link-page-accent=#ff0000"
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists save-styles 12180 --list
	 *     wp extrachill artists save-styles 12180 --button-bg="#0044cc" --button-hover-bg="#0033aa"
	 *     wp extrachill artists save-styles 12180 --css-var="--link-page-overlay-color=rgba(0,0,0,0.7)"
	 *
	 * @subcommand save-styles
	 * @when after_wp_load
	 */
	public function save_styles( $args, $assoc_args ) {


		$artist_id = absint( $args[0] );

		// --list mode: show current CSS vars.
		if ( isset( $assoc_args['list'] ) ) {
			$read_ability = $this->get_ability( 'extrachill/get-link-page-data' );
			$current      = $read_ability->execute( array( 'artist_id' => $artist_id ) );

			if ( is_wp_error( $current ) ) {
				WP_CLI::error( $current->get_error_message() );
			}

			$display = array();
			foreach ( $current['css_vars'] ?? array() as $key => $value ) {
				$display[] = array( 'Variable' => $key, 'Value' => $value );
			}
			Utils\format_items( 'table', $display, array( 'Variable', 'Value' ) );
			return;
		}

		// Map friendly flags to CSS variable names.
		$flag_map = array(
			'button-bg'         => '--link-page-button-bg-color',
			'button-hover-bg'   => '--link-page-button-hover-bg-color',
			'button-text'       => '--link-page-link-text-color',
			'button-hover-text' => '--link-page-button-hover-text-color',
			'button-border'     => '--link-page-button-border-color',
			'button-radius'     => '--link-page-button-radius',
			'bg-color'          => '--link-page-background-color',
			'text-color'        => '--link-page-text-color',
		);

		$css_vars = array();

		foreach ( $flag_map as $flag => $var_name ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$css_vars[ $var_name ] = $assoc_args[ $flag ];
			}
		}

		// Handle raw --css-var flags.
		if ( isset( $assoc_args['css-var'] ) ) {
			$raw_vars = is_array( $assoc_args['css-var'] ) ? $assoc_args['css-var'] : array( $assoc_args['css-var'] );
			foreach ( $raw_vars as $pair ) {
				$eq_pos = strpos( $pair, '=' );
				if ( $eq_pos !== false ) {
					$key            = substr( $pair, 0, $eq_pos );
					$value          = substr( $pair, $eq_pos + 1 );
					$css_vars[ $key ] = $value;
				}
			}
		}

		if ( empty( $css_vars ) ) {
			WP_CLI::error( 'No style changes specified. Use --list to see current values, or pass flags like --button-bg="#0044cc".' );
		}

		$ability = $this->get_ability( 'extrachill/save-link-page-styles' );
		$result  = $ability->execute(
			array(
				'artist_id' => $artist_id,
				'css_vars'  => $css_vars,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		foreach ( $css_vars as $var => $val ) {
			WP_CLI::log( sprintf( '  %s: %s', $var, $val ) );
		}
		WP_CLI::success( sprintf( 'Updated %d style(s) for artist %d.', count( $css_vars ), $artist_id ) );
	}

	/**
	 * Save link page settings.
	 *
	 * ## OPTIONS
	 *
	 * <artist_id>
	 * : Artist profile post ID.
	 *
	 * [--list]
	 * : Show current settings instead of saving.
	 *
	 * [--<field>=<value>]
	 * : Any settings field (e.g. --redirect_enabled=1, --subscribe_display_mode=icon_modal).
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists save-settings 12180 --list
	 *     wp extrachill artists save-settings 12180 --subscribe_display_mode=button
	 *
	 * @subcommand save-settings
	 * @when after_wp_load
	 */
	public function save_settings( $args, $assoc_args ) {


		$artist_id = absint( $args[0] );

		// --list mode.
		if ( isset( $assoc_args['list'] ) ) {
			$read_ability = $this->get_ability( 'extrachill/get-link-page-data' );
			$current      = $read_ability->execute( array( 'artist_id' => $artist_id ) );

			if ( is_wp_error( $current ) ) {
				WP_CLI::error( $current->get_error_message() );
			}

			$display = array();
			foreach ( $current['settings'] ?? array() as $key => $value ) {
				$display[] = array(
					'Setting' => $key,
					'Value'   => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value,
				);
			}
			Utils\format_items( 'table', $display, array( 'Setting', 'Value' ) );
			return;
		}

		// Strip WP-CLI meta flags.
		$skip_keys = array( 'path', 'url', 'user', 'format', 'list', 'allow-root', 'skip-plugins', 'skip-themes', 'require', 'exec', 'color', 'no-color', 'debug', 'quiet', 'prompt', 'context', 'ssh', 'http', 'skip-packages' );
		$settings  = array();

		foreach ( $assoc_args as $key => $value ) {
			if ( ! in_array( $key, $skip_keys, true ) ) {
				$settings[ $key ] = $value;
			}
		}

		if ( empty( $settings ) ) {
			WP_CLI::error( 'No settings specified. Use --list to see current values.' );
		}

		$ability = $this->get_ability( 'extrachill/save-link-page-settings' );
		$result  = $ability->execute(
			array(
				'artist_id' => $artist_id,
				'settings'  => $settings,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		foreach ( $settings as $key => $val ) {
			WP_CLI::log( sprintf( '  %s: %s', $key, $val ) );
		}
		WP_CLI::success( sprintf( 'Updated %d setting(s) for artist %d.', count( $settings ), $artist_id ) );
	}

	/**
	 * Get an ability or exit with error.
	 *
	 * @param string $slug Ability slug.
	 * @return \WP_Ability
	 */
	private function get_ability( $slug ) {
		$ability = wp_get_ability( $slug );
		if ( ! $ability ) {
			WP_CLI::error( sprintf( 'Ability %s is not registered. Ensure extrachill-artist-platform is active.', $slug ) );
		}
		return $ability;
	}
}
