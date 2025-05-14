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

    // For single band profiles
    // Home -> Forum ID (5432) -> Band Profile Title
    elseif ( is_singular( 'band_profile' ) ) {
        $band_directory_forum_id = 5432;
        $forum_url  = function_exists('bbp_get_forum_permalink') ? bbp_get_forum_permalink( $band_directory_forum_id ) : '#';
        $forum_title = function_exists('bbp_get_forum_title') ? bbp_get_forum_title( $band_directory_forum_id ) : 'Bands'; // Fallback title
        $current_title = get_the_title(); // Get current band profile title

        return array(
            '<a href="' . esc_url( home_url( '/' ) ) . '">Home</a>', // Assuming 'Home' is desired, based on other conditions
            '<a href="' . esc_url( $forum_url ) . '">' . esc_html( $forum_title ) . '</a>',
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
    $home_text  = 'Community';
    $home_url   = home_url( '/' );
    
    // 2) Start building the breadcrumb output.
    $breadcrumb  = '<div class="bbp-breadcrumb">';
    $breadcrumb .= '<a href="' . esc_url( $home_url ) . '">' . esc_html( $home_text ) . '</a>' . $separator;
    
    if ( is_singular() ) {
        $breadcrumb .= '<span class="breadcrumb-current">' . esc_html( get_the_title() ) . '</span>';
    } elseif ( is_search() ) {
        $breadcrumb .= '<span class="breadcrumb-current">Search results for: ' . esc_html( get_search_query() ) . '</span>';
    } elseif ( is_archive() ) {
        $breadcrumb .= '<span class="breadcrumb-current">' . esc_html( post_type_archive_title( '', false ) ) . '</span>';
    } else {
        // Fallback for other pages.
        $breadcrumb .= '<span class="breadcrumb-current">' . esc_html( get_the_title() ) . '</span>';
    }
    
    $breadcrumb .= '</div>';
    
    echo $breadcrumb;
}


