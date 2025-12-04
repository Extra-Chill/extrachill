<?php
/**
 * Homepage Template
 *
 * Generic container for homepage content. Plugins hook into extrachill_homepage_content
 * to provide site-specific homepage content for each network site.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

get_header();

do_action( 'extrachill_before_body_content' );
?>
<div id="mediavine-settings" data-blocklist-all="1"></div>
<?php
do_action( 'extrachill_homepage_content' );

do_action( 'extrachill_after_homepage_content' );

get_footer();
