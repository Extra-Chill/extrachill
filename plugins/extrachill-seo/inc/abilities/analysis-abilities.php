<?php
/**
 * Analysis Ability Callbacks
 *
 * Execute callbacks for URL analysis and IndexNow abilities.
 *
 * @package ExtraChill\SEO\Abilities
 */

namespace ExtraChill\SEO\Abilities;

use function ExtraChill\SEO\Audit\Checks\ec_seo_check_url_redirect;
use function ExtraChill\SEO\Core\ec_seo_get_indexnow_key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute callback for analyze-url ability.
 *
 * @param array $input Input parameters.
 * @return array|\WP_Error Analysis results.
 */
function extrachill_seo_ability_analyze_url( $input = array() ) {
	if ( empty( $input['url'] ) ) {
		return new \WP_Error(
			'missing_url',
			__( 'URL is required.', 'extrachill-seo' ),
			array( 'status' => 400 )
		);
	}

	$url    = esc_url_raw( $input['url'] );
	$checks = isset( $input['checks'] ) ? $input['checks'] : array( 'redirect', 'meta', 'schema', 'robots', 'social' );

	$analysis        = array();
	$final_url       = $url;
	$recommendations = array();
	$score           = 100;

	if ( in_array( 'redirect', $checks, true ) ) {
		$redirect_info = ec_seo_check_url_redirect( $url );

		$analysis['redirect'] = array(
			'redirects'   => $redirect_info['redirects'],
			'status_code' => $redirect_info['status_code'],
			'chain'       => $redirect_info['chain'],
			'hops'        => $redirect_info['hops'],
		);

		$final_url = $redirect_info['final_url'];

		if ( $redirect_info['redirects'] ) {
			$score -= 10;
			$recommendations[] = sprintf(
				/* translators: 1: Original URL, 2: Final URL */
				__( 'Update link from %1$s to %2$s to avoid redirect.', 'extrachill-seo' ),
				$url,
				$final_url
			);
		}

		if ( $redirect_info['hops'] > 1 ) {
			$score -= 5 * ( $redirect_info['hops'] - 1 );
			$recommendations[] = sprintf(
				/* translators: %d: Number of redirect hops */
				__( 'Redirect chain has %d hops. Reduce to a single redirect.', 'extrachill-seo' ),
				$redirect_info['hops']
			);
		}
	}

	if ( in_array( 'meta', $checks, true ) ) {
		$meta_analysis = extrachill_seo_analyze_meta( $final_url );
		$analysis['meta'] = $meta_analysis['data'];

		if ( ! empty( $meta_analysis['issues'] ) ) {
			$score -= count( $meta_analysis['issues'] ) * 5;
			$recommendations = array_merge( $recommendations, $meta_analysis['issues'] );
		}
	}

	if ( in_array( 'schema', $checks, true ) ) {
		$schema_analysis = extrachill_seo_analyze_schema( $final_url );
		$analysis['schema'] = $schema_analysis['data'];

		if ( ! empty( $schema_analysis['issues'] ) ) {
			$score -= count( $schema_analysis['issues'] ) * 5;
			$recommendations = array_merge( $recommendations, $schema_analysis['issues'] );
		}
	}

	if ( in_array( 'robots', $checks, true ) ) {
		$robots_analysis = extrachill_seo_analyze_robots( $final_url );
		$analysis['robots'] = $robots_analysis['data'];

		if ( ! empty( $robots_analysis['issues'] ) ) {
			$score -= count( $robots_analysis['issues'] ) * 10;
			$recommendations = array_merge( $recommendations, $robots_analysis['issues'] );
		}
	}

	if ( in_array( 'social', $checks, true ) ) {
		$social_analysis = extrachill_seo_analyze_social( $final_url );
		$analysis['social'] = $social_analysis['data'];

		if ( ! empty( $social_analysis['issues'] ) ) {
			$score -= count( $social_analysis['issues'] ) * 3;
			$recommendations = array_merge( $recommendations, $social_analysis['issues'] );
		}
	}

	$score = max( 0, min( 100, $score ) );

	return array(
		'url'             => $url,
		'final_url'       => $final_url,
		'analysis'        => $analysis,
		'score'           => $score,
		'recommendations' => $recommendations,
	);
}

/**
 * Analyze meta tags for a URL.
 *
 * @param string $url URL to analyze.
 * @return array Analysis data with 'data' and 'issues' keys.
 */
function extrachill_seo_analyze_meta( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 10,
			'sslverify'   => false,
			'redirection' => 0,
		)
	);

	$data   = array(
		'title'       => '',
		'description' => '',
		'issues'      => array(),
	);
	$issues = array();

	if ( is_wp_error( $response ) ) {
		$issues[] = __( 'Could not fetch URL to analyze meta tags.', 'extrachill-seo' );
		return array( 'data' => $data, 'issues' => $issues );
	}

	$body = wp_remote_retrieve_body( $response );

	if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $body, $matches ) ) {
		$data['title'] = trim( $matches[1] );
	}

	if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $matches ) ) {
		$data['description'] = trim( $matches[1] );
	} elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\'][^>]*>/i', $body, $matches ) ) {
		$data['description'] = trim( $matches[1] );
	}

	if ( empty( $data['title'] ) ) {
		$issues[] = __( 'Missing title tag.', 'extrachill-seo' );
	} elseif ( strlen( $data['title'] ) < 30 ) {
		$issues[] = __( 'Title tag is too short (under 30 characters).', 'extrachill-seo' );
	} elseif ( strlen( $data['title'] ) > 60 ) {
		$issues[] = __( 'Title tag is too long (over 60 characters).', 'extrachill-seo' );
	}

	if ( empty( $data['description'] ) ) {
		$issues[] = __( 'Missing meta description.', 'extrachill-seo' );
	} elseif ( strlen( $data['description'] ) < 120 ) {
		$issues[] = __( 'Meta description is too short (under 120 characters).', 'extrachill-seo' );
	} elseif ( strlen( $data['description'] ) > 160 ) {
		$issues[] = __( 'Meta description is too long (over 160 characters).', 'extrachill-seo' );
	}

	$data['issues'] = $issues;

	return array( 'data' => $data, 'issues' => $issues );
}

/**
 * Analyze schema markup for a URL.
 *
 * @param string $url URL to analyze.
 * @return array Analysis data with 'data' and 'issues' keys.
 */
function extrachill_seo_analyze_schema( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 10,
			'sslverify'   => false,
			'redirection' => 0,
		)
	);

	$data   = array(
		'types_found' => array(),
		'valid'       => false,
		'issues'      => array(),
	);
	$issues = array();

	if ( is_wp_error( $response ) ) {
		$issues[] = __( 'Could not fetch URL to analyze schema.', 'extrachill-seo' );
		return array( 'data' => $data, 'issues' => $issues );
	}

	$body = wp_remote_retrieve_body( $response );

	if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>([^<]+)<\/script>/i', $body, $matches ) ) {
		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( $json, true );
			if ( $decoded ) {
				$data['valid'] = true;
				if ( isset( $decoded['@type'] ) ) {
					$data['types_found'][] = $decoded['@type'];
				}
				if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
					foreach ( $decoded['@graph'] as $item ) {
						if ( isset( $item['@type'] ) ) {
							$data['types_found'][] = $item['@type'];
						}
					}
				}
			}
		}
	}

	$data['types_found'] = array_unique( $data['types_found'] );

	if ( empty( $data['types_found'] ) ) {
		$issues[] = __( 'No structured data (JSON-LD) found.', 'extrachill-seo' );
	}

	$data['issues'] = $issues;

	return array( 'data' => $data, 'issues' => $issues );
}

/**
 * Analyze robots directives for a URL.
 *
 * @param string $url URL to analyze.
 * @return array Analysis data with 'data' and 'issues' keys.
 */
function extrachill_seo_analyze_robots( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 10,
			'sslverify'   => false,
			'redirection' => 0,
		)
	);

	$data   = array(
		'indexable'  => true,
		'directives' => array(),
	);
	$issues = array();

	if ( is_wp_error( $response ) ) {
		$issues[] = __( 'Could not fetch URL to analyze robots.', 'extrachill-seo' );
		return array( 'data' => $data, 'issues' => $issues );
	}

	$body = wp_remote_retrieve_body( $response );

	if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $matches ) ) {
		$directives = array_map( 'trim', explode( ',', strtolower( $matches[1] ) ) );
		$data['directives'] = $directives;

		if ( in_array( 'noindex', $directives, true ) ) {
			$data['indexable'] = false;
			$issues[] = __( 'Page has noindex directive - it will not appear in search results.', 'extrachill-seo' );
		}
	} elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']robots["\'][^>]*>/i', $body, $matches ) ) {
		$directives = array_map( 'trim', explode( ',', strtolower( $matches[1] ) ) );
		$data['directives'] = $directives;

		if ( in_array( 'noindex', $directives, true ) ) {
			$data['indexable'] = false;
			$issues[] = __( 'Page has noindex directive - it will not appear in search results.', 'extrachill-seo' );
		}
	}

	$headers        = wp_remote_retrieve_headers( $response );
	$x_robots_tag   = isset( $headers['x-robots-tag'] ) ? $headers['x-robots-tag'] : '';
	if ( ! empty( $x_robots_tag ) ) {
		$header_directives = array_map( 'trim', explode( ',', strtolower( $x_robots_tag ) ) );
		$data['directives'] = array_merge( $data['directives'], $header_directives );

		if ( in_array( 'noindex', $header_directives, true ) ) {
			$data['indexable'] = false;
			$issues[] = __( 'X-Robots-Tag header contains noindex.', 'extrachill-seo' );
		}
	}

	return array( 'data' => $data, 'issues' => $issues );
}

/**
 * Analyze social meta tags for a URL.
 *
 * @param string $url URL to analyze.
 * @return array Analysis data with 'data' and 'issues' keys.
 */
function extrachill_seo_analyze_social( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 10,
			'sslverify'   => false,
			'redirection' => 0,
		)
	);

	$data   = array(
		'og_tags'       => array(),
		'twitter_cards' => array(),
		'issues'        => array(),
	);
	$issues = array();

	if ( is_wp_error( $response ) ) {
		$issues[] = __( 'Could not fetch URL to analyze social tags.', 'extrachill-seo' );
		return array( 'data' => $data, 'issues' => $issues );
	}

	$body = wp_remote_retrieve_body( $response );

	if ( preg_match_all( '/<meta[^>]+property=["\']og:([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$data['og_tags'][ 'og:' . $match[1] ] = $match[2];
		}
	}
	if ( preg_match_all( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']og:([^"\']+)["\'][^>]*>/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$data['og_tags'][ 'og:' . $match[2] ] = $match[1];
		}
	}

	if ( preg_match_all( '/<meta[^>]+name=["\']twitter:([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$data['twitter_cards'][ 'twitter:' . $match[1] ] = $match[2];
		}
	}
	if ( preg_match_all( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']twitter:([^"\']+)["\'][^>]*>/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$data['twitter_cards'][ 'twitter:' . $match[2] ] = $match[1];
		}
	}

	if ( empty( $data['og_tags']['og:title'] ) ) {
		$issues[] = __( 'Missing og:title meta tag.', 'extrachill-seo' );
	}

	if ( empty( $data['og_tags']['og:description'] ) ) {
		$issues[] = __( 'Missing og:description meta tag.', 'extrachill-seo' );
	}

	if ( empty( $data['og_tags']['og:image'] ) ) {
		$issues[] = __( 'Missing og:image meta tag.', 'extrachill-seo' );
	}

	if ( empty( $data['twitter_cards']['twitter:card'] ) ) {
		$issues[] = __( 'Missing twitter:card meta tag.', 'extrachill-seo' );
	}

	$data['issues'] = $issues;

	return array( 'data' => $data, 'issues' => $issues );
}

/**
 * Execute callback for ping-indexnow ability.
 *
 * @param array $input Input parameters.
 * @return array|\WP_Error Result.
 */
function extrachill_seo_ability_ping_indexnow( $input = array() ) {
	$indexnow_key = ec_seo_get_indexnow_key();

	if ( empty( $indexnow_key ) ) {
		return new \WP_Error(
			'indexnow_not_configured',
			__( 'IndexNow is not configured. Set an IndexNow key in SEO settings.', 'extrachill-seo' ),
			array( 'status' => 400 )
		);
	}

	$urls = array();

	if ( ! empty( $input['urls'] ) && is_array( $input['urls'] ) ) {
		foreach ( $input['urls'] as $url ) {
			$urls[] = esc_url_raw( $url );
		}
	}

	if ( ! empty( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
		foreach ( $input['post_ids'] as $post_id ) {
			$permalink = get_permalink( (int) $post_id );
			if ( $permalink ) {
				$urls[] = $permalink;
			}
		}
	}

	$urls = array_unique( array_filter( $urls ) );

	if ( empty( $urls ) ) {
		return new \WP_Error(
			'no_urls',
			__( 'No valid URLs to submit. Provide urls or post_ids.', 'extrachill-seo' ),
			array( 'status' => 400 )
		);
	}

	$payload = array(
		'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
		'key'         => $indexnow_key,
		'keyLocation' => home_url( '/' . rawurlencode( $indexnow_key ) . '.txt' ),
		'urlList'     => array_values( $urls ),
	);

	$response = wp_remote_post(
		'https://api.indexnow.org/indexnow',
		array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( $payload ),
		)
	);

	$response_code = 0;
	$message       = '';

	if ( is_wp_error( $response ) ) {
		$message = $response->get_error_message();
	} else {
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $response_code || 202 === $response_code ) {
			$message = __( 'URLs successfully submitted to IndexNow.', 'extrachill-seo' );
		} else {
			$message = sprintf(
				/* translators: %d: HTTP response code */
				__( 'IndexNow returned status code %d.', 'extrachill-seo' ),
				$response_code
			);
		}
	}

	return array(
		'success'       => ( 200 === $response_code || 202 === $response_code ),
		'submitted'     => count( $urls ),
		'urls'          => $urls,
		'response_code' => $response_code,
		'message'       => $message,
	);
}
