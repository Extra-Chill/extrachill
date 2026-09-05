<?php
/**
 * Site Health: frozen host->blog_id map staleness detector.
 *
 * The advanced-cache.php drop-in's host->blog_id map (see
 * inc/dropin-installer.php) is baked to disk once, at plugin (re)activation.
 * WordPress core provides no mechanism to notice when that snapshot drifts
 * from the live network: a new site added via get_sites(), a blog's primary
 * domain changed, or a new sunrise-style alias declared through the
 * `extrachill_cache_dropin_alias_hosts` filter all silently fall back to
 * $ec_cache_blog_id = 0 in the drop-in with no warning anywhere. This module
 * closes that gap with a WordPress Site Health check that recomputes the
 * live map and diffs it against what is actually baked into the drop-in.
 *
 * Modeled on the per-blog Site Health check pattern in
 * extrachill-search/inc/core/index-health.php.
 *
 * @package ExtraChillCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the host->blog_id map currently baked into the installed drop-in.
 *
 * Advanced-cache.php is included by WordPress core from wp-settings.php on
 * every request where WP_CACHE is true — including wp-admin requests, which
 * is where Site Health runs — so EXTRACHILL_CACHE_DROPIN_BLOG_MAP is always
 * defined by the time this check runs if a drop-in owned by this plugin is
 * installed and being loaded. We deliberately do not attempt to parse the
 * map out of the raw drop-in file as a fallback: doing so would require
 * reconstituting a var_export()-quoted PHP string outside of PHP's own
 * parser, which is unsafe. If the constant is undefined but a drop-in file
 * exists, we report "installed" with an empty baked map, which correctly
 * surfaces as drift against any live map.
 *
 * @return array<string,int>|null Null if no drop-in file exists at all.
 */
function extrachill_cache_get_baked_blog_map() {
	if ( defined( 'EXTRACHILL_CACHE_DROPIN_BLOG_MAP' ) ) {
		$decoded = json_decode( EXTRACHILL_CACHE_DROPIN_BLOG_MAP, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	$dropin = extrachill_cache_dropin_path();
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Defensive existence check; a filesystem warning must not surface in a Site Health test.
	if ( ! @is_file( $dropin ) ) {
		return null;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the installed drop-in directly for a Site Health diagnostic; WP_Filesystem is not required for a read-only diagnostic check.
	$contents = @file_get_contents( $dropin );
	if ( false === $contents || false === strpos( $contents, 'Extra Chill Cache' ) ) {
		return null;
	}

	// A drop-in file owned by this plugin exists but the constant never got
	// defined (WP_CACHE off, or this ran outside normal WP bootstrap). Report
	// an empty baked map rather than guessing its contents.
	return array();
}

/**
 * Compare the baked drop-in map against what the generator would produce
 * right now (live get_sites() + declared aliases).
 *
 * @return array {
 *     Drift details.
 *
 *     @type bool             $installed Whether a drop-in is installed at all.
 *     @type bool             $stale     True if baked and live maps differ.
 *     @type array<string,int> $baked     Map baked into the installed drop-in.
 *     @type array<string,int> $live      Map the generator would produce now.
 *     @type string[]         $missing   Hosts present live but absent baked.
 *     @type string[]         $changed   Hosts present in both with a different blog_id.
 *     @type string[]         $removed   Hosts present baked but absent live.
 * }
 */
function extrachill_cache_get_blog_map_drift() {
	$baked = extrachill_cache_get_baked_blog_map();

	if ( null === $baked ) {
		return array(
			'installed' => false,
			'stale'     => false,
			'baked'     => array(),
			'live'      => array(),
			'missing'   => array(),
			'changed'   => array(),
			'removed'   => array(),
		);
	}

	$live = extrachill_cache_build_blog_map();

	$missing = array();
	$changed = array();
	foreach ( $live as $host => $blog_id ) {
		if ( ! array_key_exists( $host, $baked ) ) {
			$missing[] = $host;
		} elseif ( (int) $baked[ $host ] !== (int) $blog_id ) {
			$changed[] = $host;
		}
	}

	$removed = array();
	foreach ( $baked as $host => $blog_id ) {
		if ( ! array_key_exists( $host, $live ) ) {
			$removed[] = $host;
		}
	}

	return array(
		'installed' => true,
		'stale'     => ! empty( $missing ) || ! empty( $changed ) || ! empty( $removed ),
		'baked'     => $baked,
		'live'      => $live,
		'missing'   => $missing,
		'changed'   => $changed,
		'removed'   => $removed,
	);
}

/**
 * Report host->blog_id map freshness through WordPress Site Health.
 *
 * @return array Site Health test result.
 */
function extrachill_cache_blog_map_site_health_test() {
	$drift = extrachill_cache_get_blog_map_drift();

	$result = array(
		'label'       => __( 'Extra Chill Cache host map is up to date', 'extrachill-cache' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Performance', 'extrachill-cache' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'The host->blog_id map baked into advanced-cache.php matches the live network and its declared aliases.', 'extrachill-cache' ) . '</p>',
		'test'        => 'extrachill_cache_blog_map_freshness',
	);

	if ( ! $drift['installed'] ) {
		$result['label']       = __( 'Extra Chill Cache drop-in is not installed', 'extrachill-cache' );
		$result['status']      = 'recommended';
		$result['description'] = '<p>' . esc_html__( 'No advanced-cache.php owned by Extra Chill Cache was found. This is expected if the plugin has not been activated yet.', 'extrachill-cache' ) . '</p>';
		return $result;
	}

	if ( $drift['stale'] ) {
		$details = array();
		if ( ! empty( $drift['missing'] ) ) {
			$details[] = sprintf(
				/* translators: %s: comma-separated list of hostnames. */
				__( 'Missing from the baked map (would resolve to blog 0): %s.', 'extrachill-cache' ),
				implode( ', ', $drift['missing'] )
			);
		}
		if ( ! empty( $drift['changed'] ) ) {
			$details[] = sprintf(
				/* translators: %s: comma-separated list of hostnames. */
				__( 'Mapped to a different blog than the current live map: %s.', 'extrachill-cache' ),
				implode( ', ', $drift['changed'] )
			);
		}
		if ( ! empty( $drift['removed'] ) ) {
			$details[] = sprintf(
				/* translators: %s: comma-separated list of hostnames. */
				__( 'Baked in but no longer live (stale/removed site or alias): %s.', 'extrachill-cache' ),
				implode( ', ', $drift['removed'] )
			);
		}

		$result['label']       = __( 'Extra Chill Cache host map is stale', 'extrachill-cache' );
		$result['status']      = 'critical';
		$result['description'] = '<p>' . esc_html__( 'The host->blog_id map baked into advanced-cache.php no longer matches the live network. Affected hosts fall back to a shared blog_id 0 cache partition, which is invalidated only by TTL/GC, not by content-change purges.', 'extrachill-cache' ) . '</p><p>' . esc_html( implode( ' ', $details ) ) . '</p>';
		$result['actions']     = '<p>' . esc_html__( 'Reactivate Extra Chill Cache (network-wide) to regenerate advanced-cache.php from the current live map.', 'extrachill-cache' ) . '</p>';
	}

	return $result;
}

/**
 * Add the host-map freshness test to the existing Site Health surface.
 *
 * @param array $tests Registered Site Health tests.
 * @return array Filtered tests.
 */
function extrachill_cache_register_blog_map_site_health_test( $tests ) {
	$tests['direct']['extrachill_cache_blog_map_freshness'] = array(
		'label' => __( 'Is the Extra Chill Cache host map fresh?', 'extrachill-cache' ),
		'test'  => 'extrachill_cache_blog_map_site_health_test',
	);

	return $tests;
}
add_filter( 'site_status_tests', 'extrachill_cache_register_blog_map_site_health_test' );
