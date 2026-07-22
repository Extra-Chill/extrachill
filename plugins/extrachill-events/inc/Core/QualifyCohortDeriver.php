<?php
/**
 * Derive stable remediation cohorts from persisted qualify fingerprints.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts stored fingerprint evidence into bounded remediation summaries.
 */
final class QualifyCohortDeriver {

	private const REPRESENTATIVE_URL_LIMIT   = 3;
	private const UNSUPPORTED_SOURCE_VERDICT = 'unsupported_source';

	/**
	 * Group latest-verdict rows into deterministic, bounded cohorts.
	 *
	 * @param array<int,array<string,mixed>> $rows Latest verdict rows.
	 * @param int                            $limit Maximum cohorts to return. Zero means all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function group( array $rows, int $limit = 0 ): array {
		$groups = self::start();
		self::accumulate( $groups, $rows );
		return self::finish( $groups, $limit );
	}

	/**
	 * Start an incremental cohort accumulator.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function start(): array {
		return array();
	}

	/**
	 * Add one bounded page of latest-verdict rows to an accumulator.
	 *
	 * Only the lexically first representative URLs are retained, so memory use
	 * does not grow with the number of URLs in a cohort.
	 *
	 * @param array<string,array<string,mixed>> $groups Accumulator, passed by reference.
	 * @param array<int,array<string,mixed>>    $rows   Latest verdict rows.
	 */
	public static function accumulate( array &$groups, array $rows ): void {

		foreach ( $rows as $row ) {
			if ( QualifyVerdict::is_qualified( (string) ( $row['verdict'] ?? '' ) ) ) {
				continue;
			}
			$fingerprint = self::decode_fingerprint( $row['fingerprint'] ?? array() );
			$cohort      = self::derive( $fingerprint, (string) ( $row['verdict'] ?? '' ), (string) ( $row['url'] ?? '' ) );
			$key         = $cohort['cohort'];

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ]                        = $cohort;
				$groups[ $key ]['count']               = 0;
				$groups[ $key ]['representative_urls'] = array();
			}

			++$groups[ $key ]['count'];
			$url = (string) ( $row['url'] ?? '' );
			if ( '' !== $url && ! in_array( $url, $groups[ $key ]['representative_urls'], true ) ) {
				$groups[ $key ]['representative_urls'][] = $url;
				sort( $groups[ $key ]['representative_urls'], SORT_STRING );
				$groups[ $key ]['representative_urls'] = array_slice( $groups[ $key ]['representative_urls'], 0, self::REPRESENTATIVE_URL_LIMIT );
			}
		}
	}

	/**
	 * Sort and limit an incremental accumulator for output.
	 *
	 * @param array<string,array<string,mixed>> $groups Completed accumulator.
	 * @param int                               $limit  Maximum cohorts to return. Zero means all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function finish( array $groups, int $limit = 0 ): array {
		$groups = array_values( $groups );
		usort(
			$groups,
			static function ( array $a, array $b ): int {
				$count_order = (int) $b['count'] <=> (int) $a['count'];
				return 0 !== $count_order ? $count_order : strcmp( (string) $a['cohort'], (string) $b['cohort'] );
			}
		);

		return $limit > 0 ? array_slice( $groups, 0, $limit ) : $groups;
	}

	/**
	 * Maximum representative URLs retained and emitted for each cohort.
	 */
	public static function representative_url_limit(): int {
		return self::REPRESENTATIVE_URL_LIMIT;
	}

	/**
	 * Derive one safe summary without exposing the stored fingerprint blob.
	 *
	 * The verdict is only used for explicit operational/non-actionable outcomes.
	 * Fingerprint evidence drives extractor cohorts so future verdict taxonomy
	 * corrections do not change their identity.
	 *
	 * @param array  $fingerprint Persisted qualify fingerprint.
	 * @param string $verdict    Current verdict for explicit terminal outcomes.
	 * @param string $source_url Canonical source URL.
	 * @return array<string,string>
	 */
	public static function derive( array $fingerprint, string $verdict = '', string $source_url = '' ): array {
		$platforms  = self::string_list( $fingerprint['platforms_detected'] ?? array() );
		$platform   = empty( $platforms ) ? 'unknown' : implode( '+', $platforms );
		$structured = is_array( $fingerprint['structured_data'] ?? null ) ? $fingerprint['structured_data'] : array();
		$signal     = self::structured_signal( $structured );
		$shape      = self::page_shape( $structured );
		$attempt    = self::attempt_summary( $fingerprint['extractor_attempts'] ?? array() );
		$status     = (int) ( $fingerprint['http_status'] ?? 0 );

		if ( QualifyVerdict::COVERED_ELSEWHERE === $verdict || ! empty( $fingerprint['ticketmaster_precheck']['disqualified'] ) ) {
			return self::cohort( 'non_actionable', $platform, $signal, $shape, $attempt['extractor'], 'covered_elsewhere' );
		}

		$reservation = array_intersect( $platforms, array( 'opentable', 'resy', 'tock' ) );
		if ( QualifyVerdict::RESERVATION_ONLY === $verdict || ( ! empty( $reservation ) && count( $reservation ) === count( $platforms ) ) ) {
			return self::cohort( 'non_actionable', $platform, $signal, $shape, $attempt['extractor'], 'reservation_only' );
		}

		$final_url   = (string) ( $fingerprint['final_url'] ?? '' );
		$source_host = self::url_host( $source_url );
		if ( self::UNSUPPORTED_SOURCE_VERDICT === $verdict ) {
			if ( self::is_login_wall( $final_url ) ) {
				return self::cohort( 'non_actionable', self::url_host( $final_url ), $signal, $shape, $attempt['extractor'], 'login_wall' );
			}
			if ( self::is_social_host( $source_host ) ) {
				return self::cohort( 'non_actionable', $source_host, $signal, $shape, $attempt['extractor'], 'unsupported_social_source' );
			}
			return self::cohort( 'non_actionable', $platform, $signal, $shape, $attempt['extractor'], 'unsupported_source' );
		}

		if ( ! empty( $fingerprint['timeout'] ) || 0 === $status || $status >= 500 ) {
			return self::cohort( 'operational', $platform, $signal, $shape, $attempt['extractor'], ! empty( $fingerprint['timeout'] ) ? 'timeout' : 'http_' . $status );
		}

		if ( ! empty( $fingerprint['cloudflare_challenge'] ) || 403 === $status || 429 === $status ) {
			$reason = ! empty( $fingerprint['cloudflare_challenge'] ) ? 'cloudflare' : 'http_' . $status;
			return self::cohort( 'operational', $platform, $signal, $shape, $attempt['extractor'], $reason );
		}

		if ( self::is_login_wall( $final_url ) ) {
			return self::cohort( 'non_actionable', self::url_host( $final_url ), $signal, $shape, $attempt['extractor'], 'login_wall' );
		}

		if ( self::is_social_host( $source_host ) ) {
			return self::cohort( 'non_actionable', $source_host, $signal, $shape, $attempt['extractor'], 'unsupported_social_source' );
		}

		if ( QualifyVerdict::BOT_BLOCKED === $verdict ) {
			return self::cohort( 'operational', $platform, $signal, $shape, $attempt['extractor'], 'bot_blocked' );
		}

		if ( QualifyVerdict::UNREACHABLE === $verdict ) {
			return self::cohort( 'operational', $platform, $signal, $shape, $attempt['extractor'], 'unreachable' );
		}

		if ( 'none' !== $signal ) {
			return self::cohort( 'extractor', $platform, $signal, $shape, $attempt['extractor'], $attempt['failure'] );
		}

		if ( 'unknown' !== $platform ) {
			return self::cohort( 'extractor', $platform, $signal, $shape, $attempt['extractor'], $attempt['failure'] );
		}

		$reason = self::cross_host_redirect( $source_url, $final_url ) ? 'redirect_no_event_evidence' : 'no_event_evidence';
		return self::cohort( 'non_actionable', $platform, $signal, $shape, $attempt['extractor'], $reason );
	}

	/**
	 * Build a stable cohort key and its safe output fields.
	 *
	 * @param string $category  Remediation category.
	 * @param string $platform  Sorted platform signature.
	 * @param string $signal    Structured-data signal.
	 * @param string $shape     Event page shape.
	 * @param string $extractor Extractor signature.
	 * @param string $reason    Failure or disposition reason.
	 * @return array<string,string>
	 */
	private static function cohort( string $category, string $platform, string $signal, string $shape, string $extractor, string $reason ): array {
		$key = implode(
			'|',
			array(
				$category,
				'platform=' . $platform,
				'signal=' . $signal,
				'shape=' . $shape,
				'extractor=' . $extractor,
				'reason=' . $reason,
			)
		);

		return array(
			'cohort'            => $key,
			'category'          => $category,
			'platform'          => $platform,
			'structured_signal' => $signal,
			'page_shape'        => $shape,
			'extractor'         => $extractor,
			'reason'            => $reason,
		);
	}

	/**
	 * Decode a persisted fingerprint value.
	 *
	 * @param mixed $fingerprint JSON string or decoded fingerprint.
	 * @return array<string,mixed>
	 */
	private static function decode_fingerprint( $fingerprint ): array {
		if ( is_array( $fingerprint ) ) {
			return $fingerprint;
		}
		$decoded = json_decode( (string) $fingerprint, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Normalize a list to sorted unique non-empty strings.
	 *
	 * @param mixed $values Candidate list.
	 * @return array<int,string>
	 */
	private static function string_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$values = array_values( array_unique( array_filter( array_map( 'strval', $values ) ) ) );
		sort( $values, SORT_STRING );
		return $values;
	}

	/**
	 * Summarize structured Event data signals.
	 *
	 * @param array $structured Structured-data fingerprint section.
	 */
	private static function structured_signal( array $structured ): string {
		$jsonld    = ! empty( $structured['jsonld_event_graph_present'] ) || (int) ( $structured['jsonld_events'] ?? 0 ) > 0;
		$microdata = (int) ( $structured['microdata_events'] ?? 0 ) > 0;
		if ( $jsonld && $microdata ) {
			return 'jsonld+microdata';
		}
		if ( $jsonld ) {
			return 'jsonld';
		}
		return $microdata ? 'microdata' : 'none';
	}

	/**
	 * Normalize the stored page shape.
	 *
	 * @param array $structured Structured-data fingerprint section.
	 */
	private static function page_shape( array $structured ): string {
		$shape = (string) ( $structured['event_page_shape'] ?? QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN );
		return in_array( $shape, array( QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL, QualifyVerdict::EVENT_PAGE_SHAPE_LISTING ), true )
			? $shape
			: QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN;
	}

	/**
	 * Summarize the highest-priority extractor failure evidence.
	 *
	 * @param mixed $attempts Extractor attempt fingerprint section.
	 * @return array{extractor:string,failure:string}
	 */
	private static function attempt_summary( $attempts ): array {
		if ( ! is_array( $attempts ) ) {
			return array(
				'extractor' => 'none',
				'failure'   => 'not_attempted',
			);
		}

		$buckets = array(
			'missing_extractor'   => array(),
			'insufficient_events' => array(),
			'attempt_failed'      => array(),
			'not_attempted'       => array(),
		);
		foreach ( $attempts as $attempt ) {
			if ( ! is_array( $attempt ) ) {
				continue;
			}
			$name = (string) ( $attempt['name'] ?? '' );
			if ( '' === $name || false !== stripos( $name, 'vision' ) ) {
				continue;
			}
			if ( array_key_exists( 'exists', $attempt ) && empty( $attempt['exists'] ) ) {
				$buckets['missing_extractor'][] = $name;
			} elseif ( ! empty( $attempt['ran'] ) && (int) ( $attempt['events'] ?? 0 ) > 0 ) {
				$buckets['insufficient_events'][] = $name;
			} elseif ( ! empty( $attempt['ran'] ) ) {
				$buckets['attempt_failed'][] = $name;
			} else {
				$buckets['not_attempted'][] = $name;
			}
		}

		foreach ( $buckets as $failure => $names ) {
			$names = self::string_list( $names );
			if ( ! empty( $names ) ) {
				return array(
					'extractor' => implode( '+', $names ),
					'failure'   => $failure,
				);
			}
		}

		return array(
			'extractor' => 'none',
			'failure'   => 'not_attempted',
		);
	}

	/**
	 * Whether the final URL visibly targets authentication.
	 *
	 * @param string $url Final URL.
	 */
	private static function is_login_wall( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$target = strtolower( (string) ( $parts['path'] ?? '' ) . '?' . (string) ( $parts['query'] ?? '' ) );
		return (bool) preg_match( '#(?:^|[/_.?=&-])(login|log-in|signin|sign-in|auth)(?:$|[/_.?=&-])#', $target );
	}

	/**
	 * Whether qualification ended on another host.
	 *
	 * @param string $source_url Canonical source URL.
	 * @param string $final_url  Final response URL.
	 */
	private static function cross_host_redirect( string $source_url, string $final_url ): bool {
		$source_host = self::url_host( $source_url );
		$final_host  = self::url_host( $final_url );
		return 'unknown' !== $source_host && 'unknown' !== $final_host && $source_host !== $final_host;
	}

	/**
	 * Whether a host is a social profile source rather than a venue site.
	 *
	 * @param string $host Normalized host.
	 */
	private static function is_social_host( string $host ): bool {
		return in_array(
			$host,
			array( 'facebook.com', 'instagram.com', 'tiktok.com', 'twitter.com', 'x.com' ),
			true
		);
	}

	/**
	 * Extract a normalized host for bounded output.
	 *
	 * @param string $url URL to inspect.
	 */
	private static function url_host( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' === $host ? 'unknown' : (string) preg_replace( '/^www\./', '', $host );
	}
}
