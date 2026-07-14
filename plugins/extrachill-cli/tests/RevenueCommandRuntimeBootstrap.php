<?php
/**
 * Registers the worktree plugin after WordPress has loaded.
 */

WP_CLI::add_hook(
	'after_wp_load',
	static function () {
		require dirname( __DIR__ ) . '/extrachill-cli.php';
	}
);
