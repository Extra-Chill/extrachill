<?php
/**
 * Artist Switcher Template Component
 * 
 * Shared template for artist switcher dropdown used across management pages.
 * Eliminates code duplication between link page and artist profile management.
 * 
 * @param array $args Template arguments
 *   - switcher_id: HTML element ID (default: 'artist-switcher-select')
 *   - base_url: URL to redirect to when switching artists
 *   - current_artist_id: Currently selected artist ID
 *   - user_id: User ID to get artist list for (default: current user)
 *   - css_class: Additional CSS classes (default: '')
 *   - label_text: Select option label (default: '-- Select an Artist --')
 *   - artist_ids: Optional pre-filtered array of artist IDs (default: fetch from user)
 */

defined( 'ABSPATH' ) || exit;

// Parse template arguments with defaults
$switcher_id = $args['switcher_id'] ?? 'artist-switcher-select';
$base_url = $args['base_url'] ?? get_permalink();
$current_artist_id = $args['current_artist_id'] ?? 0;
$user_id = $args['user_id'] ?? get_current_user_id();
$css_class = $args['css_class'] ?? '';
$label_text = $args['label_text'] ?? __( '-- Select an Artist --', 'extrachill-artist-platform' );

// Get user's accessible artist profiles - use provided list or fetch from user
$user_artist_ids = $args['artist_ids'] ?? ec_get_user_accessible_artists( $user_id );

// Only render if user has multiple artists
if ( count( $user_artist_ids ) <= 1 ) {
    return; // No switcher needed for single artist
}

// Build CSS classes
$container_classes = 'artist-switcher-container';
if ( ! empty( $css_class ) ) {
    $container_classes .= ' ' . esc_attr( $css_class );
}
?>

<div class="<?php echo esc_attr( $container_classes ); ?>">
    <select name="<?php echo esc_attr( $switcher_id ); ?>" id="<?php echo esc_attr( $switcher_id ); ?>" class="artist-switcher-select" data-base-url="<?php echo esc_attr( $base_url ); ?>">
        <option value=""><?php echo esc_html( $label_text ); ?></option>
        <?php foreach ( $user_artist_ids as $artist_id ) : 
            $artist_title = get_the_title( $artist_id );
            if ( empty( $artist_title ) ) continue; // Skip invalid artists
        ?>
            <option value="<?php echo esc_attr( $artist_id ); ?>" <?php selected( $current_artist_id, $artist_id ); ?>>
                <?php echo esc_html( $artist_title ); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>