<?php
/**
 * Plugin Name: ExtraChill Search
 * Plugin URI: https://extrachill.com
 * Description: Network-wide search across all nine sites using domain-based resolution
 * Version: 0.3.4
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Network: true
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Text Domain: extrachill-search
 * Domain Path: /languages
 *
 * @package ExtraChill\Search
 * @version 0.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExtraChill_Search_Plugin {

    const VERSION = '0.2.1';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->includes();
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_filter( 'extrachill_template_search', array( $this, 'override_search_template' ), 10 );
        add_action( 'template_redirect', array( $this, 'fix_search_404' ), 1 );
        add_action( 'wp_footer', array( $this, 'inject_search_source_tracking' ) );
		add_action( 'extrachill_search_performed', array( $this, 'track_search_analytics' ), 10, 3 );
		add_action( 'pre_get_posts', 'extrachill_route_frontend_search', 20 );
		add_filter( 'posts_pre_query', 'extrachill_short_circuit_frontend_search', 10, 2 );
		add_action( 'wp_initialize_site', array( $this, 'initialize_site_search_index' ), 10, 1 );
		add_filter( 'posts_search', 'extrachill_fulltext_posts_search', 10, 2 );
		add_filter( 'posts_orderby', 'extrachill_fulltext_posts_orderby', 10, 2 );
		add_filter( 'site_status_tests', 'extrachill_register_search_site_health_test' );
    }

	/**
	 * Write a `search` analytics event for genuine frontend human searches only.
	 *
	 * Listens to the `extrachill_search_performed` action fired by the core
	 * retrieval function `extrachill_network_search()`. That primitive is
	 * also called by programmatic callers (the `extrachill/multisite-search`
	 * ability, the events pipeline's artist-matching lookups, and any
	 * REST/CLI/agent search), so the analytics write must NOT live inside it.
	 *
	 * This listener gates on real-request context — not admin, not cron, not
	 * a REST request, not WP-CLI — so only a human search on the frontend
	 * results page produces an analytics event.
	 *
	 * @param string $search_term       The search query.
	 * @param int    $total_results     Total number of results found.
	 * @param string $search_source_url The page the user was on before searching.
	 * @return void
	 */
	public function track_search_analytics( $search_term, $total_results, $search_source_url ) {
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( '' === trim( (string) $search_term ) ) {
			return;
		}

		$ability = wp_get_ability( 'extrachill/track-analytics-event' );
		if ( ! $ability ) {
			return;
		}

		$ability->execute(
			array(
				'event_type' => 'search',
				'event_data' => array(
					'search_term'  => $search_term,
					'result_count' => (int) $total_results,
				),
				'source_url' => $search_source_url ? $search_source_url : '',
			)
		);
	}

    /**
     * Inject inline JS that adds source_page hidden field to all search forms.
     *
     * Captures the current page URL so analytics can reliably track where
     * searches originate, instead of relying on wp_get_referer().
     */
    public function inject_search_source_tracking() {
        ?>
        <script>
        document.querySelectorAll('form[role="search"], form.search-form').forEach(function(form) {
            if (!form.querySelector('input[name="source_page"]')) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'source_page';
                input.value = window.location.href;
                form.appendChild(input);
            }
        });
        </script>
        <?php
    }

    private function includes() {
        $includes_dir = plugin_dir_path( __FILE__ ) . 'inc/';
        $templates_dir = plugin_dir_path( __FILE__ ) . 'templates/';

		require_once $includes_dir . 'core/search-functions.php';
		require_once $includes_dir . 'core/search-scope.php';
		require_once $includes_dir . 'core/index-health.php';
		require_once $includes_dir . 'core/search-algorithm.php';
		require_once $includes_dir . 'core/taxonomy-functions.php';

        if ( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) ) {
            require_once $includes_dir . 'core/abilities.php';
        }

        require_once $templates_dir . 'template-functions.php';
        require_once $templates_dir . 'site-badge.php';
    }

	public function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
				$this->ensure_site_search_index( (int) $site->blog_id );
			}
		} else {
			$this->ensure_site_search_index( get_current_blog_id() );
		}

		flush_rewrite_rules();
	}

	/**
	 * Install the index when a new multisite site is initialized.
	 *
	 * @param WP_Site $site New site object.
	 * @return void
	 */
	public function initialize_site_search_index( $site ) {
		$this->ensure_site_search_index( (int) $site->blog_id );
	}

	/**
	 * Apply and verify one site's idempotent index setup.
	 *
	 * @param int $blog_id Site ID.
	 * @return void
	 */
	private function ensure_site_search_index( $blog_id ) {
		$status = extrachill_ensure_fulltext_index( $blog_id, true );
		if ( ! $status['ready'] ) {
			extrachill_report_fulltext_index_failure( $status );
		}
	}

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'extrachill-search', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    public function override_search_template( $template ) {
        $plugin_template = plugin_dir_path( __FILE__ ) . 'templates/search.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
        return $template;
    }

    /**
     * Prevent 404 errors on paginated multisite search when current site has no results
     */
    public function fix_search_404() {
        if ( ! is_404() ) {
            return;
        }

        $search_term = get_query_var( 's' );
        if ( empty( $search_term ) || ! function_exists( 'extrachill_network_search' ) ) {
            return;
        }

        $paged = max( 1, get_query_var( 'paged', 1 ) );
        $posts_per_page = (int) get_option( 'posts_per_page', 10 );
        $offset = ( $paged - 1 ) * $posts_per_page;

		$site_urls = function_exists( 'extrachill_search_scope_site_urls' )
			? extrachill_search_scope_site_urls()
			: array();

		$search_data = extrachill_network_search(
			$search_term,
			$site_urls,
			array(
				'limit'        => $posts_per_page,
				'offset'       => $offset,
				'return_count' => true,
			)
		);

        if ( ! empty( $search_data['results'] ) ) {
            global $wp_query;
            $wp_query->is_404 = false;
            $wp_query->is_search = true;
            status_header( 200 );
        }
    }
}

ExtraChill_Search_Plugin::get_instance();
