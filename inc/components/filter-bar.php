<?php
/**
 * Universal Filter Bar Component
 *
 * Extensible filter/sort bar for archives, shop, community forums.
 * Plugins register items via extrachill_filter_bar_items filter.
 *
 * Item types: dropdown, search
 *
 * @package ExtraChill
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the filter bar.
 *
 * Enqueues CSS on first call, then renders form with registered items.
 * Plugins hook into extrachill_filter_bar_items to add dropdowns/search.
 */
function extrachill_filter_bar() {
	static $css_enqueued = false;

	if ( ! $css_enqueued ) {
		$css_file = get_template_directory() . '/assets/css/filter-bar.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'extrachill-filter-bar',
				get_template_directory_uri() . '/assets/css/filter-bar.css',
				array(),
				filemtime( $css_file )
			);
		}
		$css_enqueued = true;
	}

	$override = apply_filters( 'extrachill_filter_bar_override', '' );
	if ( ! empty( $override ) ) {
		echo $override;
		return;
	}

	$items = apply_filters( 'extrachill_filter_bar_items', array() );

	if ( empty( $items ) ) {
		return;
	}

	$form_action = is_search() ? home_url( '/' ) : strtok( $_SERVER['REQUEST_URI'], '?' );

	echo '<form method="get" action="' . esc_url( $form_action ) . '" class="extrachill-filter-bar">';

	do_action( 'extrachill_filter_bar_start' );

	// Separate dropdowns (left) from search (right)
	$dropdowns = array();
	$searches = array();

	foreach ( $items as $item ) {
		$type = $item['type'] ?? '';
		if ( 'dropdown' === $type ) {
			$dropdowns[] = $item;
		} elseif ( 'search' === $type ) {
			$searches[] = $item;
		}
	}

	// Render dropdowns in left wrapper
	if ( ! empty( $dropdowns ) ) {
		echo '<div class="filter-bar-dropdowns">';
		foreach ( $dropdowns as $dropdown ) {
			extrachill_render_filter_dropdown( $dropdown );
		}
		echo '</div>';
	}

	// Render search items on the right
	foreach ( $searches as $search ) {
		extrachill_render_filter_search( $search );
	}

	do_action( 'extrachill_filter_bar_end' );

	echo '</form>';
}

/**
 * Render a dropdown item.
 *
 * Supports two modes:
 * - Standard: Form submission with name/value params
 * - Redirect: Direct URL navigation (for category/term links)
 *
 * @param array $item Dropdown configuration.
 */
function extrachill_render_filter_dropdown( $item ) {
	$id       = $item['id'] ?? '';
	$name     = $item['name'] ?? '';
	$options  = $item['options'] ?? array();
	$current  = $item['current'] ?? '';
	$redirect = $item['redirect'] ?? false;

	if ( empty( $options ) || empty( $name ) ) {
		return;
	}

	$onchange = $redirect
		? 'if(this.value)window.location.href=this.value;'
		: 'this.form.submit();';

	echo '<select';
	if ( ! empty( $id ) ) {
		echo ' id="' . esc_attr( $id ) . '"';
	}
	if ( ! $redirect ) {
		echo ' name="' . esc_attr( $name ) . '"';
	}
	echo ' onchange="' . esc_attr( $onchange ) . '">';

	foreach ( $options as $value => $label ) {
		$selected = ( (string) $value === (string) $current ) ? ' selected' : '';
		echo '<option value="' . esc_attr( $value ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
	}

	echo '</select>';
}

/**
 * Render a search item.
 *
 * @param array $item Search configuration.
 */
function extrachill_render_filter_search( $item ) {
	$id          = $item['id'] ?? '';
	$name        = $item['name'] ?? 's';
	$placeholder = $item['placeholder'] ?? __( 'Search...', 'extrachill' );
	$current     = $item['current'] ?? '';
	$button_icon = $item['button_icon'] ?? '';

	echo '<div class="filter-bar-search">';

	echo '<input type="text"';
	if ( ! empty( $id ) ) {
		echo ' id="' . esc_attr( $id ) . '"';
	}
	echo ' name="' . esc_attr( $name ) . '"';
	echo ' placeholder="' . esc_attr( $placeholder ) . '"';
	echo ' value="' . esc_attr( $current ) . '"';
	echo '>';

	echo '<button type="submit" aria-label="' . esc_attr__( 'Search', 'extrachill' ) . '">';
	if ( ! empty( $button_icon ) ) {
		echo $button_icon;
	} elseif ( function_exists( 'ec_icon' ) ) {
		echo ec_icon( 'search' );
	} else {
		echo esc_html__( 'Search', 'extrachill' );
	}
	echo '</button>';

	echo '</div>';
}

add_action( 'extrachill_archive_above_posts', 'extrachill_filter_bar' );
