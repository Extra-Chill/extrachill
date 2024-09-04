<?php
function mycustom_breadcrumb_options() {
	// Home - default = true
	$args['include_home']    = true;
	// Forum root - default = true
	$args['include_root']    = false;
	// Current - default = true
	$args['include_current'] = true;

	return $args;
}

add_filter('bbp_before_get_breadcrumb_parse_args', 'mycustom_breadcrumb_options' );

// Remove the single topic description
function extrachill_remove_single_topic_description($retstr, $r, $args) {
    return '';
}
add_filter('bbp_get_single_topic_description', 'extrachill_remove_single_topic_description', 10, 3);

