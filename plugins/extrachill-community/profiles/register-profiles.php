<?php
// Custom Post Type for 3 Different Types of Community Profiles

// Register Custom Post Type for Fan Profiles
function extrachill_create_fan_profile_cpt() {
$labels = array(
    'name'                  => _x('Fan Profiles', 'Post Type General Name', 'extrachill'),
    'singular_name'         => _x('Fan Profile', 'Post Type Singular Name', 'extrachill'),
    'menu_name'             => __('Fan Profiles', 'extrachill'),
    'name_admin_bar'        => __('Fan Profile', 'extrachill'),
    'archives'              => __('Fan Profile Archives', 'extrachill'),
    'attributes'            => __('Fan Profile Attributes', 'extrachill'),
    'parent_item_colon'     => __('Parent Fan Profile:', 'extrachill'),
    'all_items'             => __('All Fan Profiles', 'extrachill'),
    'add_new_item'          => __('Add New Fan Profile', 'extrachill'),
    'add_new'               => __('Add New', 'extrachill'),
    'new_item'              => __('New Fan Profile', 'extrachill'),
    'edit_item'             => __('Edit Fan Profile', 'extrachill'),
    'update_item'           => __('Update Fan Profile', 'extrachill'),
    'view_item'             => __('View Fan Profile', 'extrachill'),
    'view_items'            => __('View Fan Profiles', 'extrachill'),
    'search_items'          => __('Search Fan Profiles', 'extrachill'),
    'not_found'             => __('Not found', 'extrachill'),
    'not_found_in_trash'    => __('Not found in Trash', 'extrachill'),
    'featured_image'        => __('Profile Picture', 'extrachill'),
    'set_featured_image'    => __('Set profile picture', 'extrachill'),
    'remove_featured_image' => __('Remove profile picture', 'extrachill'),
    'use_featured_image'    => __('Use as profile picture', 'extrachill'),
    'insert_into_item'      => __('Insert into profile', 'extrachill'),
    'uploaded_to_this_item' => __('Uploaded to this profile', 'extrachill'),
    'items_list'            => __('Fan Profiles list', 'extrachill'),
    'items_list_navigation' => __('Fan Profiles list navigation', 'extrachill'),
    'filter_items_list'     => __('Filter fan profiles list', 'extrachill'),
);

    $args = array(
        'label'                 => __('Fan Profile', 'extrachill'),
        'description'           => __('Profile for Fans', 'extrachill'),
        'labels'                => $labels,
'supports' => array('title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes', 'author'),
        'taxonomies'            => array(),
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 5,
        'menu_icon'             => 'dashicons-groups',
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => true,
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'capability_type'       => 'post',
        'show_in_rest'          => true,
        'rewrite' => array('slug' => 'fan'),

    );
    register_post_type('fan_profile', $args);
}

// Register Custom Post Type for Artist Profiles
function extrachill_create_artist_profile_cpt() {
$labels = array(
    'name'                  => _x('Artist Profiles', 'Post Type General Name', 'extrachill'),
    'singular_name'         => _x('Artist Profile', 'Post Type Singular Name', 'extrachill'),
    'menu_name'             => __('Artist Profiles', 'extrachill'),
    'name_admin_bar'        => __('Artist Profile', 'extrachill'),
    'archives'              => __('Artist Profile Archives', 'extrachill'),
    'attributes'            => __('Artist Profile Attributes', 'extrachill'),
    'parent_item_colon'     => __('Parent Artist Profile:', 'extrachill'),
    'all_items'             => __('All Artist Profiles', 'extrachill'),
    'add_new_item'          => __('Add New Artist Profile', 'extrachill'),
    'add_new'               => __('Add New', 'extrachill'),
    'new_item'              => __('New Artist Profile', 'extrachill'),
    'edit_item'             => __('Edit Artist Profile', 'extrachill'),
    'update_item'           => __('Update Artist Profile', 'extrachill'),
    'view_item'             => __('View Artist Profile', 'extrachill'),
    'view_items'            => __('View Artist Profiles', 'extrachill'),
    'search_items'          => __('Search Artist Profiles', 'extrachill'),
    'not_found'             => __('Not found', 'extrachill'),
    'not_found_in_trash'    => __('Not found in Trash', 'extrachill'),
    'featured_image'        => __('Profile Picture', 'extrachill'),
    'set_featured_image'    => __('Set profile picture', 'extrachill'),
    'remove_featured_image' => __('Remove profile picture', 'extrachill'),
    'use_featured_image'    => __('Use as profile picture', 'extrachill'),
    'insert_into_item'      => __('Insert into profile', 'extrachill'),
    'uploaded_to_this_item' => __('Uploaded to this profile', 'extrachill'),
    'items_list'            => __('Artist Profiles list', 'extrachill'),
    'items_list_navigation' => __('Artist Profiles list navigation', 'extrachill'),
    'filter_items_list'     => __('Filter artist profiles list', 'extrachill'),
);

    $args = array(
        'label'                 => __('Artist Profile', 'extrachill'),
        'description'           => __('Profile for Artists', 'extrachill'),
        'labels'                => $labels,
'supports' => array('title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes', 'author'),
        'taxonomies'            => array(),
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 6,
        'menu_icon'             => 'dashicons-art',
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => true,
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'capability_type'       => 'post',
        'show_in_rest'          => true,
        'rewrite' => array('slug' => 'artist'),

    );
    register_post_type('artist_profile', $args);
}

// Register Custom Post Type for Music Industry Professional Profiles
function extrachill_create_professional_profile_cpt() {
$labels = array(
    'name'                  => _x('Professional Profiles', 'Post Type General Name', 'extrachill'),
    'singular_name'         => _x('Professional Profile', 'Post Type Singular Name', 'extrachill'),
    'menu_name'             => __('Professional Profiles', 'extrachill'),
    'name_admin_bar'        => __('Professional Profile', 'extrachill'),
    'archives'              => __('Professional Profile Archives', 'extrachill'),
    'attributes'            => __('Professional Profile Attributes', 'extrachill'),
    'parent_item_colon'     => __('Parent Professional Profile:', 'extrachill'),
    'all_items'             => __('All Professional Profiles', 'extrachill'),
    'add_new_item'          => __('Add New Professional Profile', 'extrachill'),
    'add_new'               => __('Add New', 'extrachill'),
    'new_item'              => __('New Professional Profile', 'extrachill'),
    'edit_item'             => __('Edit Professional Profile', 'extrachill'),
    'update_item'           => __('Update Professional Profile', 'extrachill'),
    'view_item'             => __('View Professional Profile', 'extrachill'),
    'view_items'            => __('View Professional Profiles', 'extrachill'),
    'search_items'          => __('Search Professional Profiles', 'extrachill'),
    'not_found'             => __('Not found', 'extrachill'),
    'not_found_in_trash'    => __('Not found in Trash', 'extrachill'),
    'featured_image'        => __('Profile Picture', 'extrachill'),
    'set_featured_image'    => __('Set profile picture', 'extrachill'),
    'remove_featured_image' => __('Remove profile picture', 'extrachill'),
    'use_featured_image'    => __('Use as profile picture', 'extrachill'),
    'insert_into_item'      => __('Insert into profile', 'extrachill'),
    'uploaded_to_this_item' => __('Uploaded to this profile', 'extrachill'),
    'items_list'            => __('Professional Profiles list', 'extrachill'),
    'items_list_navigation' => __('Professional Profiles list navigation', 'extrachill'),
    'filter_items_list'     => __('Filter professional profiles list', 'extrachill'),
);
    $args = array(
        'label'                 => __('Professional Profile', 'extrachill'),
        'description'           => __('Profile for Music Industry Professionals', 'extrachill'),
        'labels'                => $labels,
'supports' => array('title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes', 'author'),
        'taxonomies'            => array(),
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 7,
        'menu_icon'             => 'dashicons-businessman',
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => true,
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'capability_type'       => 'post',
        'show_in_rest'          => true,
        'rewrite' => array('slug' => 'pro'),

    );
    register_post_type('professional_profile', $args);
}

function create_bandlink_post_type() {
    $labels = array(
        'name'                  => _x('BandLinks', 'Post type general name', 'textdomain'),
        'singular_name'         => _x('BandLink', 'Post type singular name', 'textdomain'),
        'menu_name'             => _x('BandLinks', 'Admin Menu text', 'textdomain'),
        'name_admin_bar'        => _x('BandLink', 'Add New on Toolbar', 'textdomain'),
        'add_new'               => __('Add New', 'textdomain'),
        'add_new_item'          => __('Add New BandLink', 'textdomain'),
        'new_item'              => __('New BandLink', 'textdomain'),
        'edit_item'             => __('Edit BandLink', 'textdomain'),
        'view_item'             => __('View BandLink', 'textdomain'),
        'all_items'             => __('All BandLinks', 'textdomain'),
        'search_items'          => __('Search BandLinks', 'textdomain'),
        'parent_item_colon'     => __('Parent BandLinks:', 'textdomain'),
        'not_found'             => __('No BandLinks found.', 'textdomain'),
        'not_found_in_trash'    => __('No BandLinks found in Trash.', 'textdomain'),
        'featured_image'        => _x('BandLink Cover Image', 'Overrides the “Featured Image” phrase', 'textdomain'),
        'set_featured_image'    => _x('Set cover image', 'Overrides the “Set featured image” phrase', 'textdomain'),
        'remove_featured_image' => _x('Remove cover image', 'Overrides the “Remove featured image” phrase', 'textdomain'),
        'use_featured_image'    => _x('Use as cover image', 'Overrides the “Use as featured image” phrase', 'textdomain'),
        'archives'              => _x('BandLink archives', 'The post type archive label used in nav menus', 'textdomain'),
        'insert_into_item'      => _x('Insert into BandLink', 'Overrides the “Insert into post”/“Insert into page” phrase', 'textdomain'),
        'uploaded_to_this_item' => _x('Uploaded to this BandLink', 'Overrides the “Uploaded to this post”/“Uploaded to this page” phrase', 'textdomain'),
        'filter_items_list'     => _x('Filter BandLinks list', 'Screen reader text for the filter links heading', 'textdomain'),
        'items_list_navigation' => _x('BandLinks list navigation', 'Screen reader text for the pagination heading', 'textdomain'),
        'items_list'            => _x('BandLinks list', 'Screen reader text for the items list heading', 'textdomain'),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'band'),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => null,
        'supports'           => array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'),
    );

    register_post_type('bandlink', $args);
}

add_action('init', 'create_bandlink_post_type', 0);


// Hook into the 'init' action
add_action('init', 'extrachill_create_fan_profile_cpt', 0);
add_action('init', 'extrachill_create_artist_profile_cpt', 0);
add_action('init', 'extrachill_create_professional_profile_cpt', 0);

?>
