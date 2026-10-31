<?php
/** Keep the disposable migration fixture routed to its pre-cutover source. */

add_filter(
	'ec_link_page_storage_blog_id',
	static function () {
		return 4;
	},
	PHP_INT_MAX
);
