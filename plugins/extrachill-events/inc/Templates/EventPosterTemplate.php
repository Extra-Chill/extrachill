<?php
/**
 * Event Poster Template
 *
 * Single-event adapter over Data Machine's generic `poster` template.
 * Reads the event's own data (title, date/time, venue and address,
 * price, RSVP perk, permalink) and maps it onto poster fields; all
 * layout, branding and QR rendering live in Data Machine core, with the
 * QR image supplied by the network's QR provider filter.
 *
 * Required data fields:
 *   - event_id (int)
 *
 * Optional data fields:
 *   - title (string)             — poster headline; defaults to the event title
 *   - tagline, subline (string)   — short body lines under the title
 *   - rsvp_text (string)          — footer RSVP line; defaults to the event host
 *   - footer_note (string)        — small line under the RSVP line
 *   - cobrand_logos (string[])    — PNG paths drawn beside the footer note
 *   - perk_text (string)          — overrides `_extrachill_event_perk_text`
 *   - kicker (string)             — defaults to "<BRAND> PRESENTS"
 *
 * @package ExtraChillEvents\Templates
 */

namespace ExtraChillEvents\Templates;

use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\PlatformPresets;
use DataMachine\Abilities\Media\TemplateInterface;
use DataMachine\Abilities\Media\Templates\PosterTemplate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventPosterTemplate implements TemplateInterface {

	/** Landscape QR label. */
	public const LANDSCAPE_QR_LABEL = 'SCAN TO RSVP';

	public function get_id(): string {
		return 'event_poster';
	}

	public function get_name(): string {
		return 'Event Poster';
	}

	public function get_description(): string {
		return 'Single promotional poster for one event (feed, story, print_tabloid or landscape_16_9). Reads title, date, venue, price and RSVP perk from the event and renders them through the core poster template, with a QR code to the event page.';
	}

	public function get_fields(): array {
		return array(
			'event_id'      => array(
				'label'    => 'Event ID',
				'type'     => 'integer',
				'required' => true,
			),
			'title'         => array(
				'label'    => 'Title',
				'type'     => 'string',
				'required' => false,
			),
			'tagline'       => array(
				'label'    => 'Tagline',
				'type'     => 'string',
				'required' => false,
			),
			'subline'       => array(
				'label'    => 'Subline',
				'type'     => 'string',
				'required' => false,
			),
			'rsvp_text'     => array(
				'label'    => 'RSVP Text',
				'type'     => 'string',
				'required' => false,
			),
			'footer_note'   => array(
				'label'    => 'Footer Note',
				'type'     => 'string',
				'required' => false,
			),
			'cobrand_logos' => array(
				'label'    => 'Co-brand Logo Paths',
				'type'     => 'array',
				'required' => false,
			),
			'perk_text'     => array(
				'label'    => 'Perk Text',
				'type'     => 'string',
				'required' => false,
			),
			'kicker'        => array(
				'label'    => 'Kicker',
				'type'     => 'string',
				'required' => false,
			),
		);
	}

	public function get_default_preset(): string {
		return 'instagram_feed_portrait';
	}

	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$post = get_post( (int) ( $data['event_id'] ?? 0 ) );
		if ( ! $post || ! class_exists( PosterTemplate::class ) ) {
			return array();
		}

		$preset = (string) ( $options['preset'] ?? $this->get_default_preset() );
		$format = (string) ( $options['format'] ?? 'png' );
		$dims   = class_exists( PlatformPresets::class ) ? PlatformPresets::dimensions( $preset ) : null;

		$attrs  = self::event_details_attrs( (string) $post->post_content );
		$perk   = isset( $data['perk_text'] ) ? (string) $data['perk_text'] : (string) get_post_meta( $post->ID, '_extrachill_event_perk_text', true );
		$fields = self::event_fields( $attrs, (string) $post->post_title, (string) get_permalink( $post ), $perk, (string) ( $data['title'] ?? '' ) );

		$fields['address'] = self::venue_address( (string) ( $attrs['venue'] ?? '' ) );

		$landscape = is_array( $dims ) && $dims['width'] > $dims['height'];

		return ( new PosterTemplate() )->render(
			self::poster_fields( $fields, $data, $landscape ),
			$renderer,
			array_merge(
				$options,
				array(
					'brand_template_id' => $this->get_id(),
					'filename'          => sprintf( 'event-poster-%d-%s.%s', $post->ID, $preset, 'jpeg' === $format ? 'jpg' : 'png' ),
				)
			)
		);
	}

	/**
	 * Map normalized event copy onto Data Machine poster fields.
	 *
	 * Portrait posters carry the full event block (date, venue, address)
	 * and an RSVP line beside the QR. Landscape posters keep the left
	 * column to tagline, date and venue and label the QR instead.
	 *
	 * @param array $fields    Output of event_fields() plus address.
	 * @param array $data      Template input.
	 * @param bool  $landscape Whether the canvas is wider than tall.
	 * @return array Poster template input.
	 */
	public static function poster_fields( array $fields, array $data, bool $landscape ): array {
		$str  = static fn( string $key ): string => (string) ( $data[ $key ] ?? '' );
		$rsvp = $str( 'rsvp_text' );

		return array(
			'kicker'        => $str( 'kicker' ),
			'title'         => $fields['title'],
			'tagline'       => $str( 'tagline' ),
			'subline'       => $landscape ? '' : $str( 'subline' ),
			'detail_lines'  => '' !== $fields['date_line'] ? array( $fields['date_line'] ) : array(),
			'accent_line'   => $fields['venue'],
			'detail_note'   => $landscape ? '' : $fields['address'],
			'banner_text'   => $fields['banner'],
			'qr_url'        => $fields['qr_url'],
			'qr_label'      => $landscape ? self::LANDSCAPE_QR_LABEL : '',
			'cta_text'      => $landscape ? '' : ( '' !== $rsvp ? $rsvp : self::default_rsvp_text( $fields['qr_url'] ) ),
			'footer_note'   => $str( 'footer_note' ),
			'cobrand_logos' => (array) ( $data['cobrand_logos'] ?? array() ),
		);
	}

	/**
	 * Normalize event data into the strings drawn on the poster.
	 *
	 * @param array  $attrs     event-details block attributes.
	 * @param string $title     Event post title (may contain entities).
	 * @param string $permalink Event permalink; also the QR payload.
	 * @param string $perk      RSVP perk text.
	 * @param string $headline  Optional poster headline overriding the title.
	 * @return array{title: string, date_line: string, venue: string, banner: string, qr_url: string, address: string}
	 */
	public static function event_fields( array $attrs, string $title, string $permalink, string $perk, string $headline = '' ): array {
		return array(
			'title'     => mb_strtoupper( html_entity_decode( '' !== trim( $headline ) ? $headline : $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			'date_line' => self::date_line( $attrs ),
			'venue'     => mb_strtoupper( html_entity_decode( (string) ( $attrs['venue'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			'banner'    => self::banner_text( (string) ( $attrs['price'] ?? '' ), $perk ),
			'qr_url'    => $permalink,
			'address'   => '',
		);
	}

	/**
	 * "WED, OCT 21 / 6:30–9PM" from block attributes.
	 *
	 * @param array $attrs event-details block attributes.
	 */
	public static function date_line( array $attrs ): string {
		$date = (string) ( $attrs['startDate'] ?? '' );
		$ts   = '' !== $date ? strtotime( $date . ' 12:00:00 UTC' ) : false;
		if ( false === $ts ) {
			return '';
		}
		$line = strtoupper( gmdate( 'D, M j', $ts ) );
		$time = self::time_range( (string) ( $attrs['startTime'] ?? '' ), (string) ( $attrs['endTime'] ?? '' ) );
		return '' !== $time ? $line . ' / ' . $time : $line;
	}

	/**
	 * "6:30–9PM" (shared meridiem collapsed), "11AM–1PM", or "8PM".
	 *
	 * @param string $start HH:MM[:SS].
	 * @param string $end   HH:MM[:SS] or empty.
	 */
	public static function time_range( string $start, string $end ): string {
		$fmt                     = static function ( string $t ): array {
			$ts = strtotime( '2000-01-01 ' . $t . ' UTC' );
			if ( false === $ts || '' === $t ) {
				return array( '', '' );
			}
			$clock = '00' === gmdate( 'i', $ts ) ? gmdate( 'g', $ts ) : gmdate( 'g:i', $ts );
			return array( $clock, gmdate( 'A', $ts ) );
		};
		list( $s_clock, $s_mer ) = $fmt( $start );
		list( $e_clock, $e_mer ) = $fmt( $end );
		if ( '' === $s_clock ) {
			return '';
		}
		if ( '' === $e_clock ) {
			return $s_clock . $s_mer;
		}
		return ( $s_mer === $e_mer ? $s_clock : $s_clock . $s_mer ) . "\u{2013}" . $e_clock . $e_mer;
	}

	/**
	 * Banner copy: price and perk, uppercase, each ending in a period.
	 *
	 * @param string $price Price attribute (e.g. "Free", "$10").
	 * @param string $perk  Perk text.
	 */
	public static function banner_text( string $price, string $perk ): string {
		$parts = array();
		foreach ( array( $price, $perk ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$parts[] = rtrim( $part, '.!' ) . '.';
			}
		}
		return mb_strtoupper( implode( ' ', $parts ) );
	}

	/**
	 * Default RSVP line: "RSVP at <host><path>".
	 *
	 * @param string $url Event permalink.
	 */
	public static function default_rsvp_text( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' !== $host ? 'RSVP at ' . $host : '';
	}

	/**
	 * Attributes of the first data-machine-events/event-details block.
	 *
	 * @param string $content Post content.
	 */
	public static function event_details_attrs( string $content ): array {
		foreach ( parse_blocks( $content ) as $block ) {
			if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
				return (array) $block['attrs'];
			}
		}
		return array();
	}

	/**
	 * "2038 Meeting Street Rd, Charleston" from the venue term's meta.
	 *
	 * @param string $venue Venue name from the event block.
	 */
	private static function venue_address( string $venue ): string {
		if ( '' === $venue || ! taxonomy_exists( 'venue' ) ) {
			return '';
		}
		$term = get_term_by( 'name', $venue, 'venue' );
		if ( ! $term ) {
			return '';
		}
		$parts = array_filter(
			array(
				trim( (string) get_term_meta( $term->term_id, '_venue_address', true ) ),
				trim( (string) get_term_meta( $term->term_id, '_venue_city', true ) ),
			)
		);
		return implode( ', ', $parts );
	}
}
