<?php
/**
 * Default Artist Profile Sections (registered by AP core)
 *
 * Re-homes the blocks that single-artist_profile.php used to hardcode into the
 * section registry (see section-registry.php). Output is intentionally
 * byte-equivalent to the previous template so there is NO visual regression for
 * this foundation PR — only the wiring changes (template loop instead of inline
 * markup).
 *
 * Sections registered here:
 *   - hero       (priority 10): header image, manage button, profile image,
 *                title + genre/local-scene meta, social icons, and the link-page
 *                URL card. This is where the link page becomes "one tool" — a
 *                card inside the profile, not the destination the profile links
 *                out to.
 *   - overview   (priority 20): the .entry-content block — the About bio.
 *
 * The cross-site Coverage block is NO LONGER rendered here. It moved to a
 * foreign-registered section owned by extrachill-multisite (priority 30, id
 * `coverage`) via the same `ec_artist_profile_sections` filter — the layer-pure
 * proof that a foreign plugin self-registers a term-scoped hub section. AP core
 * does not name it.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register AP core's default profile sections.
 *
 * @param array[] $sections       Registered sections.
 * @param int     $artist_id      Artist profile post ID.
 * @param int     $artist_term_id Bound main-blog `artist` term_id.
 * @return array[]
 */
function ec_register_default_artist_profile_sections( $sections, $artist_id, $artist_term_id ) {
    $sections[] = array(
        'id'       => 'hero',
        'label'    => __( 'Overview', 'extrachill-artist-platform' ),
        'priority' => 10,
        'as_tab'   => false,
        'render'   => 'ec_render_artist_profile_hero_section',
    );

    $sections[] = array(
        'id'       => 'overview',
        'label'    => __( 'About', 'extrachill-artist-platform' ),
        'priority' => 20,
        'as_tab'   => false,
        'render'   => 'ec_render_artist_profile_overview_section',
    );

    return $sections;
}
add_filter( 'ec_artist_profile_sections', 'ec_register_default_artist_profile_sections', 10, 3 );

/**
 * Render the hero section.
 *
 * Byte-equivalent to the hero block previously inlined in
 * single-artist_profile.php.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @param int $artist_term_id    Bound main-blog `artist` term_id (unused here).
 * @return void
 */
function ec_render_artist_profile_hero_section( $artist_profile_id, $artist_term_id = 0 ) {
    $genre      = get_post_meta( $artist_profile_id, '_genre', true );
    $local_city = get_post_meta( $artist_profile_id, '_local_city', true );

    // Prepare for Hero Section
    $hero_background_style = '';
    $header_image_id = get_post_meta( $artist_profile_id, '_artist_profile_header_image_id', true );

    if ( $header_image_id ) {
        $image_url = wp_get_attachment_image_url( $header_image_id, 'full' ); // Or 'large', depending on desired size
        if ( $image_url ) {
            $hero_background_style = 'style="background-image: url(\'' . esc_url( $image_url ) . '\');"';
        }
    } else {
        // Fallback for no specific header image; class-based styling can apply
        $hero_background_style = '';
    }
    ?>

                        <div class="artist-profile-header artist-hero" <?php echo $hero_background_style; ?>>
                            <div class="artist-hero-overlay"></div>
                            <div class="artist-hero-content">
                                <?php
                                // --- Manage Artist Button (Moved to top of hero content) ---
                                if ( is_user_logged_in() ) :
                                    // Display "Manage Artist Profile" button if the current user can manage members for this artist profile.
                                    // This uses the custom capability 'manage_artist_members'.
                                    if ( ec_can_manage_artist( get_current_user_id(), $artist_profile_id ) ) :
                                        $manage_artist_url = get_permalink( get_page_by_path( 'manage-artist' ) );
                                        if ( $manage_artist_url ) {
                                            echo '<div class="artist-profile-actions">';
                                            echo '<a href="' . esc_url( $manage_artist_url ) . '" class="button-2 button-medium artist-manage-button">Manage Artist</a>';
                                            echo '</div>';
                                        }
                                    endif;
                                endif;
                                // --- End Manage Artist Button (Moved) ---
                                ?>
                                <?php // Display Profile Picture and then Title/Meta in a flex row
                                // The artist-hero-top-row container helps to align the image and the text content side-by-side.
                                ?>
                                <div class="artist-hero-top-row">
                                    <?php if ( has_post_thumbnail( $artist_profile_id ) ) : ?>
                                        <div class="artist-profile-featured-image">
                                            <?php echo get_the_post_thumbnail( $artist_profile_id, 'medium' ); // This is the actual profile picture ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="artist-hero-text-content">
                                        <h1 class="artist-hero-title" itemprop="headline"><?php echo esc_html( get_the_title( $artist_profile_id ) ); ?></h1>

                                        <?php if ( ! empty( $genre ) || ! empty( $local_city ) ) : ?>
                                            <p class="artist-meta-info">
                                                <?php if ( ! empty( $genre ) ) : ?>
                                                    <span class="artist-genre"><strong><?php esc_html_e( 'Genre:', 'extrachill-artist-platform' ); ?></strong> <?php echo esc_html( $genre ); ?></span>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $genre ) && ! empty( $local_city ) ) : ?>
                                                    <span class="artist-meta-separator">|</span>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $local_city ) ) : ?>
                                                    <span class="artist-local-scene"><strong><?php esc_html_e( 'Local Scene:', 'extrachill-artist-platform' ); ?></strong> <?php echo esc_html( $local_city ); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php
                                // Use centralized social links rendering
                                $social_manager = extrachill_artist_platform_social_links();
                                echo $social_manager->render_social_icons( $artist_profile_id, array(
                                    'container_class' => 'artist-social-links',
                                    'icon_class' => 'extrch-social-icon'
                                ) );
                                ?>

                                <?php
                                // --- Display Artist Link Page URL (New) ---
                                $link_page_id_for_url_display = apply_filters('ec_get_link_page_id', $artist_profile_id);
                                if ( $link_page_id_for_url_display && get_post_type( $link_page_id_for_url_display ) === 'artist_link_page' ) {
                                    global $post; // Ensure $post is the artist_profile CPT object
                                    if ( isset( $post ) && $post->post_type === 'artist_profile' ) {
                                        $artist_slug_for_url = $post->post_name;
                                        $public_url_href = '';
                                        $public_url_display_text = '';

                                        if ( defined('EXTRCH_LINKPAGE_DEV') && EXTRCH_LINKPAGE_DEV ) {
                                            $public_url_href = get_permalink( $link_page_id_for_url_display );
                                        } else {
                                            // Use canonical extrachill.link URL
                                            $public_url_href = 'https://extrachill.link/' . $artist_slug_for_url;
                                        }

                                        if ( ! empty( $public_url_href ) ) {
                                            $public_url_display_text = preg_replace( '#^https?://#', '', $public_url_href );

                                            echo '<div class="artist-public-link-display">';
                                            echo '<a href="' . esc_url( $public_url_href ) . '" class="button-3 button-medium" rel="ugc noopener">' . esc_html( $public_url_display_text ) . '</a>';
                                            echo '</div>';
                                        }
                                    }
                                }
                                // --- End Display Artist Link Page URL (New) ---
                                ?>

                            </div>
                        </div>

                        <?php
                        /**
                         * generate_after_entry_title hook.
                         *
                         * @since 0.1
                         * @hooked generate_post_meta - 10
                         */
                        // do_action( 'generate_after_entry_title' ); // Maybe hide default meta?
                        ?>
<?php
}

/**
 * Render the overview (entry-content) section: the About bio.
 *
 * The cross-site Coverage block that previously rendered here is now a
 * foreign-registered section owned by extrachill-multisite (see this file's
 * docblock). The bio block itself is byte-equivalent to the previous template.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @param int $artist_term_id    Bound main-blog `artist` term_id (unused here).
 * @return void
 */
function ec_render_artist_profile_overview_section( $artist_profile_id, $artist_term_id = 0 ) {
    ?>
                        <div class="entry-content" itemprop="text">
                            <?php
                            // --- Artist Bio Section ---
                            $artist_name = get_the_title( $artist_profile_id );
                            $artist_bio = get_post_field( 'post_content', $artist_profile_id );

                            echo '<div class="artist-bio-section">';
                            echo '<h2 class="section-title">' . esc_html( sprintf( __( 'About %s', 'extrachill-artist-platform' ), $artist_name ) ) . '</h2>';
                            if ( ! empty( $artist_bio ) ) {
                                echo '<div class="artist-bio">';
                                echo wpautop( $artist_bio );
                                echo '</div>';
                            } else {
                                echo '<p>' . __( 'No biography available yet.', 'extrachill-artist-platform' ) . '</p>';
                            }
                            echo '</div>'; // .artist-bio-section
                            ?>
                        </div><!-- .entry-content -->
<?php
}
