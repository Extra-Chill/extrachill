<?php
/**
 * Band Profile Loop - Custom template for displaying band profiles in Forum 5432.
 *
 * This template is loaded via the bp_custom_band_directory_loop_template filter
 * in forum-features/band-platform/band-directory.php
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() ) {
    $current_user_id = get_current_user_id();
    $user_band_ids = get_user_meta( $current_user_id, '_band_profile_ids', true );
    $user_band_ids = !empty($user_band_ids) && is_array($user_band_ids) ? $user_band_ids : array();

    $is_artist_or_pro = ( get_user_meta( $current_user_id, 'user_is_artist', true ) === '1' || 
                          get_user_meta( $current_user_id, 'user_is_professional', true ) === '1' );

    $latest_band_id = 0;
    if ( !empty($user_band_ids) ) {
        $latest_modified_timestamp = 0;
        foreach ( $user_band_ids as $band_id ) {
            $band_id_int = absint($band_id);
            if ( $band_id_int > 0 ) {
                $post_modified_gmt = get_post_field( 'post_modified_gmt', $band_id_int, 'raw' );
                if ( $post_modified_gmt ) {
                    $current_timestamp = strtotime( $post_modified_gmt );
                    if ( $current_timestamp > $latest_modified_timestamp ) {
                        $latest_modified_timestamp = $current_timestamp;
                        $latest_band_id = $band_id_int;
                    }
                }
            }
        }
    }

    $show_manage_bands_button = false;
    $manage_bands_url = '';
    $manage_bands_text = '';

    if ( !empty($user_band_ids) ) {
        $show_manage_bands_button = true;
        $manage_bands_url = home_url( '/manage-band-profiles/' );
        if ( $latest_band_id > 0 ) {
            $manage_bands_url = add_query_arg( 'band_id', $latest_band_id, $manage_bands_url );
        }
        $manage_bands_text = __( 'Manage Band(s)', 'generatepress_child' );
    } elseif ( $is_artist_or_pro ) {
        $show_manage_bands_button = true;
        $manage_bands_url = home_url( '/manage-band-profiles/' );
        $manage_bands_text = __( 'Create Band Profile', 'generatepress_child' );
    }

    $show_manage_links_button = false;
    $manage_links_url = '';
    $manage_links_text = '';

    if ( !empty($user_band_ids) ) { // Only show if user has bands
        $show_manage_links_button = true;
        $manage_links_url = home_url( '/manage-link-page/' );
        if ( $latest_band_id > 0 ) {
            $manage_links_url = add_query_arg( 'band_id', $latest_band_id, $manage_links_url );
        }
        $manage_links_text = __( 'Manage Link Page(s)', 'generatepress_child' );
    }

    if ( $show_manage_bands_button || $show_manage_links_button ) {
        echo '<div class="band-directory-manage-buttons">';
        if ( $show_manage_bands_button ) {
            echo '<a href="' . esc_url( $manage_bands_url ) . '" class="button">' . esc_html( $manage_bands_text ) . '</a>';
        }
        if ( $show_manage_links_button ) {
            echo '<a href="' . esc_url( $manage_links_url ) . '" class="button">' . esc_html( $manage_links_text ) . '</a>';
        }
        echo '</div>';
    }
}

// Helper function to get current URL for form actions
if (!function_exists('getCurrentUrl')) {
    function getCurrentUrl() {
        $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $url = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $url = remove_query_arg(array('sort_bands', 'band_search', 'paged'), $url);
        return $url;
    }
}

// --- Get current sort and search selections ---
$current_sort = isset($_GET['sort_bands']) ? sanitize_key($_GET['sort_bands']) : 'default';
$current_search = isset($_GET['band_search']) ? sanitize_text_field($_GET['band_search']) : '';

// --- Pagination Parameters ---
$posts_per_page = get_option('_bbp_topics_per_page', 15);
$paged = bbp_get_paged();

// --- Prepare WP_Query Arguments ---
$band_profiles_args = array(
    'post_type'      => 'band_profile',
    'post_status'    => 'publish',
    'posts_per_page' => $posts_per_page,
    'paged'          => $paged,
);

if ( !empty($current_search) ) {
    $band_profiles_args['s'] = $current_search;
}

if ( $current_sort === 'alphabetical' ) {
    $band_profiles_args['orderby'] = 'title';
    $band_profiles_args['order'] = 'ASC';
} elseif ( $current_sort === 'views' ) {
    $band_profiles_args['meta_key'] = '_band_profile_view_count';
    $band_profiles_args['orderby'] = 'meta_value_num';
    $band_profiles_args['order'] = 'DESC';
} elseif ( $current_sort === 'default' ) {
    $all_band_ids_query_args = array(
        'post_type'      => 'band_profile',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    );
    if ( !empty($current_search) ) {
        $all_band_ids_query_args['s'] = $current_search;
    }
    $all_band_ids_query = new WP_Query( $all_band_ids_query_args );
    $bands_with_activity = [];
    if ( $all_band_ids_query->have_posts() ) {
        foreach ( $all_band_ids_query->posts as $band_id ) {
            $last_activity = function_exists('bp_get_band_profile_last_activity_timestamp') 
                             ? bp_get_band_profile_last_activity_timestamp( $band_id ) 
                             : get_post_modified_time('U', false, $band_id);
            if ($last_activity) {
                $bands_with_activity[] = (object) array( 'id' => $band_id, 'last_activity' => $last_activity );
            }
        }
        if ( ! empty( $bands_with_activity ) ) {
            uasort( $bands_with_activity, function( $a, $b ) { return $b->last_activity <=> $a->last_activity; });
            $sorted_band_ids = wp_list_pluck( $bands_with_activity, 'id' );
            $band_profiles_args['post__in'] = empty($sorted_band_ids) ? array(0) : $sorted_band_ids;
            $band_profiles_args['orderby'] = 'post__in';
        } else {
             $band_profiles_args['post__in'] = array(0);
        }
    } else {
        $band_profiles_args['post__in'] = array(0);
    }
    wp_reset_postdata();
}

$band_profiles_query = new WP_Query( $band_profiles_args );

?>

<?php // --- Sorting & Search UI --- ?>
<div class="sorting-search bbp-band-profile-sorting-search">
    <div class="bbp-sorting-form">
        <form id="bandSortingForm" method="get" action="<?php echo esc_url( getCurrentUrl() ); ?>">
            <label for="sortBandsSelect" class="screen-reader-text"><?php esc_html_e( 'Sort Bands By:', 'generatepress_child' ); ?></label>
            <select name="sort_bands" id="sortBandsSelect">
                <option value="default" <?php selected($current_sort, 'default'); ?>><?php esc_html_e( 'Sort by Recent', 'generatepress_child' ); ?></option>
                <option value="alphabetical" <?php selected($current_sort, 'alphabetical'); ?>><?php esc_html_e( 'Sort by A-Z', 'generatepress_child' ); ?></option>
                <option value="views" <?php selected($current_sort, 'views'); ?>><?php esc_html_e( 'Sort by Popular', 'generatepress_child' ); ?></option>
            </select>
            <?php if (!empty($current_search)): ?><input type="hidden" name="band_search" value="<?php echo esc_attr($current_search); ?>"><?php endif; ?>
            <?php foreach ($_GET as $key => $value) { if ($key !== 'sort_bands' && $key !== 'band_search' && $key !== 'paged') { echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(stripslashes_deep($value)) . '">'; } } ?>
            <noscript><button type="submit" class="button"><?php esc_html_e( 'Sort', 'generatepress_child' ); ?></button></noscript>
        </form>
    </div>
    <div class="bbp-search-form">
        <form method="get" id="bandSearchForm" action="<?php echo esc_url( getCurrentUrl() ); ?>">
            <label for="bandSearchInput" class="screen-reader-text"><?php esc_html_e( 'Search Bands:', 'generatepress_child' ); ?></label>
            <input type="text" name="band_search" id="bandSearchInput" placeholder="<?php esc_attr_e( 'Search Bands...', 'generatepress_child' ); ?>" value="<?php echo esc_attr($current_search); ?>">
            <?php if (!empty($current_sort) && $current_sort !== 'default'): ?><input type="hidden" name="sort_bands" value="<?php echo esc_attr($current_sort); ?>"><?php endif; ?>
            <?php foreach ($_GET as $key => $value) { if ($key !== 'sort_bands' && $key !== 'band_search' && $key !== 'paged') { echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(stripslashes_deep($value)) . '">'; } } ?>
            <button type="submit" class="button"><?php esc_html_e( 'Search', 'generatepress_child' ); ?></button>
        </form>
    </div>
</div>

<?php // --- Display Section (the rest of the file) --- ?>
<div id="bbp-band-profiles" class="bbp-band-profiles">
    <?php if ( $band_profiles_query->have_posts() ) : ?>
        <ul class="bbp-bands band-cards-container">
            <?php while ( $band_profiles_query->have_posts() ) : $band_profiles_query->the_post(); ?>
                <?php $band_id = get_the_ID(); ?>
                <?php get_template_part('bbpress/loop', 'single-band-card', ['band_id' => $band_id]); ?>
            <?php endwhile; ?>
        </ul>
        <?php 
        global $wp_query; $temp_query = $wp_query; $wp_query = $band_profiles_query;
        bbp_get_template_part( 'pagination', 'topics' );
        $wp_query = $temp_query; wp_reset_postdata();
        ?>
    <?php else : ?>
        <ul class="bbp-bands"><li class="bbp-body"><div class="bbp-band-content"><?php esc_html_e( 'Oh bother! No bands found here yet.', 'generatepress_child' ); ?></div></li></ul>
    <?php endif; ?>
</div>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', () => {
    const sortSelect = document.getElementById('sortBandsSelect');
    if (sortSelect) { sortSelect.addEventListener('change', () => { sortSelect.form.submit(); }); }
});
</script> 