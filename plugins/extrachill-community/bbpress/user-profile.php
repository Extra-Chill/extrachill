<?php
/**
 * User Profile
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

do_action('bbp_template_before_user_profile');
?>
    
<div id="bbp-user-profile" class="bbp-user-profile">
    <?php bbp_get_template_part( 'user-details' ); ?>
    
    <?php 
$displayed_user_id = bbp_get_displayed_user_id();
$current_user_id   = get_current_user_id();
$is_artist         = get_user_meta( $displayed_user_id, 'user_is_artist', true );
$is_professional   = get_user_meta( $displayed_user_id, 'user_is_professional', true );
?>

<div class="bbp-user-profile-cards-container"> <?php // Start Flex Grid Container ?>
<div class="bbp-user-profile-card">
                    <?php if (bbp_get_displayed_user_field('description')) : ?>
                        <h3><?php esc_html_e('About', 'bbpress'); ?></h3>
                <p class="bbp-user-description"><?php echo bbp_rel_nofollow(bbp_get_displayed_user_field('description')); ?></p>
            <?php endif; 

            // --- Add Local Scene (City) here --- 
            $local_city = get_user_meta(bbp_get_displayed_user_id(), 'local_city', true);
            if ( $local_city ) :
            ?>
                <p class="bbp-user-local-scene-inline"><strong><?php esc_html_e('Local Scene:', 'extra-chill-community'); ?></strong> <?php echo esc_html($local_city); ?></p>
            <?php 
            endif; // End local_city check
            // --- End Local Scene --- 

?>
</div>
            <?php do_action('bbp_template_before_user_details_menu_items'); ?>
        <hr>
        <div class="bbp-user-profile-card">
        <div class="user-profile-activity">
    <h3><?php esc_html_e('Community Activity', 'bbpress'); ?></h3>
    <?php if (bbp_get_user_last_posted()) : ?>
        <p class="bbp-user-last-activity"><b>Last Post:</b> <?php printf(esc_html__('%s', 'bbpress'), bbp_get_time_since(bbp_get_user_last_posted(), false, true)); ?></p>
    <?php endif; ?>
    <?php $join_date = bbp_get_displayed_user_field('user_registered'); ?>
    <?php if (!empty($join_date)) : ?>
        <p class="bbp-user-join-date"><b>Joined:</b> <?php echo date_i18n(get_option('date_format'), strtotime($join_date)); ?></p>
    <?php endif; ?>

    <p class="bbp-user-topic-count"><b>Threads Started:</b> <?php printf(esc_html__('%s', 'bbpress'), bbp_get_user_topic_count()); ?> <a href="<?php bbp_user_topics_created_url(); ?>"><?php printf(esc_html__("(%s's Threads)", 'bbpress'), bbp_get_displayed_user_field('display_name')); ?></a></p>
    <p class="bbp-user-reply-count"><b>Total Replies:</b> <?php printf(esc_html__('%s', 'bbpress'), bbp_get_user_reply_count()); ?> <a href="<?php bbp_user_replies_created_url(); ?>"><?php printf(esc_html__("(%s's Replies Created)", 'bbpress'), bbp_get_displayed_user_field('display_name')); ?></a></p>

    <!-- Display Main Site Blog Post Count -->
    <?php
    // Properly display the main site blog post count and "View All" link on the profile
    display_main_site_post_count_on_profile();
    ?>

    <!-- Display Main Site Comments Count -->
    <?php
    $main_site_comments_html = display_main_site_comment_count_for_user();
    if (!empty($main_site_comments_html)) : ?>
        <p class="bbp-user-main-site-comment-count"><?php echo $main_site_comments_html; ?></p>
    <?php endif; ?>
    </div>
    </div>
                    
<?php
// Wrap the entire conditional artist section in a card
// Check if the user is marked as an artist or professional
if ( $is_artist || $is_professional ) :
    $user_band_ids = get_user_meta( bbp_get_displayed_user_id(), '_band_profile_ids', true );
    ?>
    <div class="bbp-user-profile-card user-band-cards-fullwidth">
        <h2>
            <?php
            $display_name = bbp_get_displayed_user_field('display_name');
            // Adjust title based on whether they have bands or not
            if ( !empty($user_band_ids) && is_array($user_band_ids) ) {
                 printf( esc_html__( "%s's Bands", 'extra-chill-community' ), esc_html($display_name) );
            } else if ( bbp_get_displayed_user_id() == get_current_user_id() ) {
                 // Title for own profile with no bands
                 esc_html_e( 'Your Band Profile & Link Page', 'extra-chill-community' );
            }
            ?>
        </h2>
        <?php if ( !empty($user_band_ids) && is_array($user_band_ids) ) : ?>
            <ul class="user-band-cards band-cards-container">
                <?php foreach ( $user_band_ids as $user_band_id ) : ?>
                    <?php 
                    $band_post = get_post( $user_band_id ); 
                    if ( $band_post && $band_post->post_type === 'band_profile' ) :
                        get_template_part('bbpress/loop', 'single-band-card', ['band_id' => $user_band_id]);
                    endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p><?php esc_html_e( 'No band memberships yet.', 'extra-chill-community' ); ?></p>
        <?php endif; ?>

        <?php 
        // --- Conditional Management Buttons for Own Profile --- 
        if ( bbp_get_displayed_user_id() == get_current_user_id() ) : // Check if it's the logged-in user's own profile
            // $is_artist is already confirmed by the outer conditional
            $current_user_id_for_card_buttons = get_current_user_id();
            $base_manage_bands_url_card = home_url( '/manage-band-profiles/' );
            $base_manage_link_page_url_card = home_url( '/manage-link-page/' );

            echo '<div class="user-band-management-actions" style="text-align: right; margin-top: 15px;">'; // Basic styling for bottom-right

            if ( !empty($user_band_ids) && is_array($user_band_ids) ) : // Already have $user_band_ids from above
                $latest_band_id_card = 0;
                $latest_modified_timestamp_card = 0;
                foreach ( $user_band_ids as $band_id_item_card ) {
                    $band_id_int_card = absint($band_id_item_card);
                    if ( $band_id_int_card > 0 ) {
                        $post_modified_gmt_card = get_post_field( 'post_modified_gmt', $band_id_int_card, 'raw' );
                        if ( $post_modified_gmt_card ) {
                            $current_timestamp_card = strtotime( $post_modified_gmt_card );
                            if ( $current_timestamp_card > $latest_modified_timestamp_card ) {
                                $latest_modified_timestamp_card = $current_timestamp_card;
                                $latest_band_id_card = $band_id_int_card;
                            }
                        }
                    }
                }
                $final_manage_bands_url_card = $base_manage_bands_url_card;
                $final_manage_link_page_url_card = $base_manage_link_page_url_card;
                if ( $latest_band_id_card > 0 ) {
                    $final_manage_bands_url_card = add_query_arg( 'band_id', $latest_band_id_card, $base_manage_bands_url_card );
                    $final_manage_link_page_url_card = add_query_arg( 'band_id', $latest_band_id_card, $base_manage_link_page_url_card );
                }
            ?>
                <a href="<?php echo esc_url( $final_manage_bands_url_card ); ?>" class="button button-small extrachill-manage-profile-button"><?php esc_html_e( 'Manage Band(s)', 'extra-chill-community' ); ?></a>
            <?php else : // No band profiles, but is an artist, viewing own profile ?>
                <a href="<?php echo esc_url( $base_manage_bands_url_card ); ?>" class="button button-small extrachill-manage-profile-button"><?php esc_html_e( 'Create Band Profile', 'extra-chill-community' ); ?></a>
            <?php endif; // End if has band_ids (for buttons)
            echo '</div>'; // End .user-band-management-actions
        endif; // End if viewing own profile
        ?>
    </div>
<?php endif; // End if user_is_artist or user_is_professional ?>

</div>

</div> <?php // End Flex Grid Container ?>

        </div> <?php // End Flex Grid Container ?>


    </div><!-- #bbp-user-profile -->

<?php do_action('bbp_template_after_user_profile'); ?>
