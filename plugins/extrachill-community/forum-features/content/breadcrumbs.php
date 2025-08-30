<?php
// Custom breadcrumb options for bbPress pages.
/**
 * 1) Override the default breadcrumb arguments
 */
function mycustom_breadcrumb_args( $args = array() ) {
    // We DO want the Home URL
    $args['include_home']    = true;  // This is typically your site home
    // We do NOT want the Forum Root crumb
    $args['include_root']    = false;
    // We do want the current page/post link
    $args['include_current'] = true;

    return $args;
}
add_filter( 'bbp_before_get_breadcrumb_parse_args', 'mycustom_breadcrumb_args' );

/**
 * 2) Customize the final crumb array on certain pages
 */
function mycustom_breadcrumb_output( $crumbs, $r = array(), $args = array() ) {

    // For the user edit page, we want:
    // Home [sep] Edit Profile [sep] Profile link (with username)
    if ( bbp_is_single_user_edit() ) {
        $user_id          = bbp_get_displayed_user_id();
        $user_profile_url = bbp_get_user_profile_url( $user_id );
        $username         = bbp_get_displayed_user_field( 'display_name' );

        return array(
            '<a href="' . esc_url( home_url( '/' ) ) . '">Home</a>',
            ' <span class="bbp-breadcrumb-current">Edit Profile</span>',
            ' <span class="bbp-breadcrumb-profile"><a href="' . esc_url( $user_profile_url ) . '">' . esc_html( $username ) . '</a></span>',
        );
    }
    // For a normal user profile page
    // Home [sep] Username
    elseif ( bbp_is_single_user() ) {
        $user_id  = bbp_get_displayed_user_id();
        $username = bbp_get_displayed_user_field( 'display_name' );

        return array(
            '<a href="' . esc_url( home_url( '/' ) ) . '">Home</a>',
            ' <span class="bbp-breadcrumb-current">' . esc_html( $username ) . '</span>',
        );
    }
    // For single topic pages, if there's still an unwanted "Home" or "Forums" crumb, remove it
    elseif ( bbp_is_single_topic() ) {
        foreach ( $crumbs as $i => $crumb ) {
            // If your first crumb is "Home," but you *only* want to remove "Forums," adjust accordingly
            if ( strpos( $crumb, 'Forums' ) !== false ) {
                unset( $crumbs[$i] );
            }
        }
        return array_values( $crumbs );
    }

    // For single artist profiles
    // Home -> Artists Archive -> Band Profile Title
    elseif ( is_singular( 'artist_profile' ) ) {
        $artists_url = site_url( '/artists/' );
        $current_title = get_the_title(); // Get current band profile title

        return array(
            '<a href="' . esc_url( home_url( '/' ) ) . '">Home</a>',
            '<a href="' . esc_url( $artists_url ) . '">Artists</a>',
            '<span class="bbp-breadcrumb-current">' . esc_html( $current_title ) . '</span>',
        );
    }

    // For all other pages, leave the $crumbs as is
    return $crumbs;
}
add_filter( 'bbp_breadcrumbs', 'mycustom_breadcrumb_output', 10, 3 );



// Remove the single topic and forum descriptions.
function extrachill_remove_single_topic_description( $retstr, $r, $args ) {
    return '';
}
add_filter( 'bbp_get_single_topic_description', 'extrachill_remove_single_topic_description', 10, 3 );
add_filter( 'bbp_get_single_forum_description', 'extrachill_remove_single_topic_description', 10, 3 );

/**
 * Display custom breadcrumbs on every non-bbPress post or page.
 *
 * This simple version always outputs:
 *   Forums › [Current Title]
 * on every page outside of bbPress.
 */
function extrachill_breadcrumbs() {
    // 1) If it's the homepage/front page, do nothing and return.
    if ( is_front_page() ) {
        return;
    }
    
    $separator  = '<span class="breadcrumb-sep"> › </span>';
    $home_text  = 'Home';
    $home_url   = home_url( '/' );
    
    $breadcrumb  = '<div class="bbp-breadcrumb">';
    
    if ( is_page_template('page-templates/manage-artist-profiles.php') ) {
        $breadcrumb .= '<a href="' . esc_url( $home_url ) . '">' . esc_html( $home_text ) . '</a>';

        $target_artist_id = isset( $_GET['artist_id'] ) ? absint( $_GET['artist_id'] ) : 0;
        $artist_post_for_breadcrumb = null;
        if ( $target_artist_id > 0 ) {
            $current_post_candidate = get_post( $target_artist_id );
            if ( $current_post_candidate && 'artist_profile' === $current_post_candidate->post_type && 'publish' === $current_post_candidate->post_status ) {
                $artist_post_for_breadcrumb = $current_post_candidate;
            }
        }

        // "Manage Bands" is the conceptual current page or parent context here.
        $breadcrumb .= $separator . '<span class="breadcrumb-current">' . esc_html__( 'Manage Bands', 'extra-chill-community' ) . '</span>';

        if ( $artist_post_for_breadcrumb ) {
            // If editing a specific band, add its name as a link after "Manage Bands"
            $artist_title = get_the_title( $artist_post_for_breadcrumb );
            $artist_permalink = get_permalink( $artist_post_for_breadcrumb );
            $breadcrumb .= $separator . '<a href="' . esc_url( $artist_permalink ) . '">' . esc_html( $artist_title ) . '</a>';
        }
        // If not editing a specific band (create mode or invalid artist_id), "Manage Bands" as current is already set.

    } elseif ( is_singular() ) {
        $breadcrumb .= '<a href="' . esc_url( $home_url ) . '">' . esc_html( $home_text ) . '</a>' . $separator;
        $breadcrumb .= '<span class="breadcrumb-current">' . esc_html( get_the_title() ) . '</span>';
    } elseif ( is_search() ) {
        $breadcrumb .= '<a href="' . esc_url( $home_url ) . '">' . esc_html( $home_text ) . '</a>' . $separator;
        $breadcrumb .= '<span class="breadcrumb-current">' . sprintf( esc_html__( 'Search results for: %s', 'extra-chill-community' ), esc_html( get_search_query() ) ) . '</span>';
    } elseif ( is_archive() ) {
        $breadcrumb .= '<a href="' . esc_url( $home_url ) . '">' . esc_html( $home_text ) . '</a>' . $separator;
        $archive_title = '';
        if ( is_category() ) {
            $archive_title = single_cat_title( '', false );
        } elseif ( is_tag() ) {
            $archive_title = single_tag_title( '', false );
        } elseif ( is_author() ) {
            $archive_title = get_the_author();
        } elseif ( is_date() ) {
            if (is_day()) {
                $archive_title = get_the_date();
            } elseif (is_month()) {
                $archive_title = get_the_date(_x('F Y', 'monthly archives date format', 'extra-chill-community'));
            } elseif (is_year()) {
                $archive_title = get_the_date(_x('Y', 'yearly archives date format', 'extra-chill-community'));
            }
        } elseif ( is_post_type_archive() ) {
            $archive_title = post_type_archive_title( '', false );
        } elseif ( is_tax() ) { // For custom taxonomies
            $archive_title = single_term_title( '', false );
        }
        
        if ( empty( $archive_title ) ) { // A general fallback
            $queried_object = get_queried_object();
            if ( $queried_object && isset( $queried_object->label ) ) {
                $archive_title = $queried_object->label;
            } elseif ( $queried_object && isset( $queried_object->name ) ) {
                $archive_title = $queried_object->name;
            } else {
                $archive_title = __( 'Archives', 'extra-chill-community' );
            }
        }
        $breadcrumb .= '<span class="breadcrumb-current">' . esc_html( $archive_title ) . '</span>';
    } else {
        // Fallback for any other type of page that might not have been caught.
        $current_title_fallback = get_the_title();
        if ($current_title_fallback) {
            $breadcrumb .= '<a href="' . esc_url( $home_url ) . '">' . esc_html( $home_text ) . '</a>' . $separator;
            $breadcrumb .= '<span class="breadcrumb-current">' . esc_html( $current_title_fallback ) . '</span>';
        } else {
            // If no title, just show home as the current item (should be rare as front_page is handled).
             $breadcrumb .= '<span class="breadcrumb-current">' . esc_html( $home_text ) . '</span>';
        }
    }
    
    $breadcrumb .= '</div>';
    
    echo $breadcrumb;
}


