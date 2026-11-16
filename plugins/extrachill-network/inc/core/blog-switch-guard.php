<?php
/**
 * Blog switch leak guard.
 *
 * Every `switch_to_blog()` must be paired with `restore_current_blog()` on all
 * return paths. When one is not (an early return, an exception caught further
 * up the stack, a core helper such as get_blog_option() interrupted mid-switch),
 * the rest of the request renders as the wrong site: issue #1512 showed a
 * Studio (blog 12) 404 with Documentation (blog 10) breadcrumbs, experiment
 * context, and a search form posting to docs.extrachill.com.
 *
 * Nothing on the network legitimately stays switched once a front-end template
 * starts rendering, so this guard:
 *
 *  1. records the caller of the most recent switch away from the request blog
 *     (cheap: one bounded, argument-free backtrace per outbound switch), and
 *  2. at template boundaries, unwinds any leaked switch back to the request
 *     blog and logs the leaked blog plus the recorded caller, so the offending
 *     code is named the next time it happens instead of only the symptom.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The blog the current request was routed to.
 *
 * Captured when this file loads, at plugin load before any switching, with
 * the `$current_blog` global (which `switch_to_blog()` never changes) as the
 * fallback.
 *
 * @return int
 */
function ec_network_request_blog_id() {
	if ( ! empty( $GLOBALS['ec_network_request_blog_id'] ) ) {
		return (int) $GLOBALS['ec_network_request_blog_id'];
	}

	if ( isset( $GLOBALS['current_blog'] ) && is_object( $GLOBALS['current_blog'] ) && ! empty( $GLOBALS['current_blog']->blog_id ) ) {
		return (int) $GLOBALS['current_blog']->blog_id;
	}

	return (int) get_current_blog_id();
}

if ( ! isset( $GLOBALS['ec_network_request_blog_id'] ) && function_exists( 'ms_is_switched' ) && ! ms_is_switched() ) {
	$GLOBALS['ec_network_request_blog_id'] = (int) get_current_blog_id();
}

/**
 * Record the caller of each switch away from the request blog.
 *
 * Hooked to `switch_blog`. Only outbound switches from the request blog are
 * recorded; nested switches and restores are ignored.
 *
 * @param int    $new_blog_id  Blog switched to.
 * @param int    $prev_blog_id Blog switched from.
 * @param string $context      'switch' or 'restore'.
 * @return void
 */
function ec_network_note_blog_switch( $new_blog_id, $prev_blog_id, $context = 'switch' ) {
	if ( 'switch' !== $context || (int) $new_blog_id === (int) $prev_blog_id ) {
		return;
	}

	if ( ec_network_request_blog_id() !== (int) $prev_blog_id ) {
		return;
	}

	$GLOBALS['ec_network_last_outbound_switch'] = array(
		'blog_id' => (int) $new_blog_id,
		'caller'  => ec_network_blog_switch_caller(),
	);
}

/**
 * Describe the first frame that called switch_to_blog().
 *
 * @return string `function < caller < caller (file:line)`, or '' when unknown.
 */
function ec_network_blog_switch_caller() {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Bounded, argument-free frame lookup used only for leak attribution.
	$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );

	foreach ( $frames as $index => $frame ) {
		if ( 'switch_to_blog' !== $frame['function'] ) {
			continue;
		}

		// The switching function plus up to two of its callers, innermost first,
		// so a core helper (get_blog_option) still names the plugin above it.
		$chain = array();
		foreach ( array_slice( $frames, $index + 1, 3 ) as $caller ) {
			$chain[] = isset( $caller['class'] ) ? $caller['class'] . '::' . $caller['function'] : $caller['function'];
		}
		$location = isset( $frame['file'], $frame['line'] )
			? str_replace( ABSPATH, '', (string) $frame['file'] ) . ':' . (int) $frame['line']
			: 'unknown';

		return ( $chain ? implode( ' < ', $chain ) : '{main}' ) . ' (' . $location . ')';
	}

	return '';
}

/**
 * Unwind a leaked blog switch back to the request blog.
 *
 * @param string $surface Where the leak was detected, for the log line.
 * @return array|null Leak details (`leaked_blog_id`, `request_blog_id`,
 *                    `depth`, `caller`, `surface`), or null when the request
 *                    was already on its own blog.
 */
function ec_network_restore_leaked_blog_switch( $surface = '' ) {
	if ( ! is_multisite() ) {
		return null;
	}

	$request_blog_id = ec_network_request_blog_id();
	$leaked_blog_id  = (int) get_current_blog_id();

	if ( $leaked_blog_id === $request_blog_id && ! ms_is_switched() ) {
		return null;
	}

	$depth = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] )
		? count( $GLOBALS['_wp_switched_stack'] )
		: 0;

	$last   = $GLOBALS['ec_network_last_outbound_switch'] ?? array();
	$caller = is_array( $last ) && isset( $last['caller'] ) ? (string) $last['caller'] : '';

	while ( ms_is_switched() ) {
		restore_current_blog();
	}

	// A switch stack popped out of order can leave the wrong blog current with
	// an empty stack. Re-enter the request blog and clear the stack so the
	// rest of the request sees an unswitched state.
	if ( (int) get_current_blog_id() !== $request_blog_id ) {
		switch_to_blog( $request_blog_id );
		$GLOBALS['_wp_switched_stack'] = array();
		$GLOBALS['switched']           = false;
	}

	$leak = array(
		'leaked_blog_id'  => $leaked_blog_id,
		'request_blog_id' => $request_blog_id,
		'depth'           => $depth,
		'caller'          => $caller,
		'surface'         => (string) $surface,
	);

	/**
	 * Whether to write a leaked-blog-switch diagnostic to the PHP error log.
	 *
	 * @param bool  $log  Default true.
	 * @param array $leak Leak details.
	 */
	if ( apply_filters( 'ec_network_log_blog_switch_leak', true, $leak ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Leaked blog switches corrupt rendering; operators need the attribution.
		error_log(
			sprintf(
				'Extra Chill Network: repaired leaked blog switch at %1$s (request blog %2$d, rendered as blog %3$d, stack depth %4$d). Last outbound switch: %5$s',
				'' !== $leak['surface'] ? $leak['surface'] : 'unknown surface',
				$request_blog_id,
				$leaked_blog_id,
				$depth,
				'' !== $caller ? $caller : 'unknown'
			)
		);
	}

	/**
	 * Fires after a leaked blog switch was unwound to the request blog.
	 *
	 * @param array $leak Leak details.
	 */
	do_action( 'ec_network_blog_switch_leak_repaired', $leak );

	return $leak;
}

/**
 * Repair a leaked switch before the front-end template is loaded.
 *
 * @param string $template Template path.
 * @return string Unchanged template path.
 */
function ec_network_guard_blog_switch_before_template( $template ) {
	ec_network_restore_leaked_blog_switch( 'template_include' );
	return $template;
}

/**
 * Repair a leaked switch after the theme header, before body content renders.
 *
 * @return void
 */
function ec_network_guard_blog_switch_before_body_content() {
	ec_network_restore_leaked_blog_switch( 'extrachill_before_body_content' );
}

add_action( 'switch_blog', 'ec_network_note_blog_switch', 10, 3 );
add_filter( 'template_include', 'ec_network_guard_blog_switch_before_template', PHP_INT_MAX );
add_action( 'extrachill_before_body_content', 'ec_network_guard_blog_switch_before_body_content', PHP_INT_MIN );
