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
    $user_id = bbp_get_displayed_user_id();
    $comment_count = function_exists('get_user_main_site_comment_count') ? get_user_main_site_comment_count($user_id) : 0;

    if ($comment_count > 0) {
        $comments_url = ec_get_site_url( 'community' ) . "/blog-comments?user_id={$user_id}";
        echo '<p class="bbp-user-main-site-comment-count"><b>Main Site Comments:</b> ' . $comment_count . ' <a href="' . esc_url($comments_url) . '">(View All)</a></p>';
    } else {
        echo '<p class="bbp-user-main-site-comment-count"><b>Main Site Comments:</b> ' . $comment_count . '</p>';
    }
    ?>
    </div>
    </div>
                    
<?php
// Wrap the entire conditional artist section in a card
// Check if the user is marked as an artist or professional
if ( $is_artist || $is_professional ) :
    // Use canonical function from extrachill-users plugin
    $user_artist_ids = function_exists('ec_get_artists_for_user') ? ec_get_artists_for_user( bbp_get_displayed_user_id() ) : array();
    ?>
    <div class="bbp-user-profile-card user-artist-cards-fullwidth">
        <h2>
            <?php
            $display_name = bbp_get_displayed_user_field('display_name');
            // Adjust title based on whether they have bands or not
            if ( !empty($user_artist_ids) ) {
                 printf( esc_html__( "%s's Artists", 'extra-chill-community' ), esc_html($display_name) );
            } else if ( bbp_get_displayed_user_id() == get_current_user_id() ) {
                 // Title for own profile with no bands
                 esc_html_e( 'Your Artist Profile & Link Page', 'extra-chill-community' );
            }
            ?>
        </h2>
        <?php if ( !empty($user_artist_ids) ) : ?>
            <ul class="user-artist-list">
				<?php
				$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
				if ( $artist_blog_id ) {
					switch_to_blog( $artist_blog_id ); // Switch to artist.extrachill.com for post data
				}
				foreach ( $user_artist_ids as $user_artist_id ) :
					$artist_post = get_post( $user_artist_id );

                    if ( $artist_post ) :
                        $artist_url = ec_get_site_url( 'artist' ) . '/' . $artist_post->post_name . '/';
                ?>
                    <li class="user-artist-item">
                        <a href="<?php echo esc_url( $artist_url ); ?>" class="button-3 button-small">
                            <?php echo esc_html( $artist_post->post_title ); ?>
                        </a>
                    </li>
                <?php
                    endif;
                endforeach;
                restore_current_blog();
                ?>
            </ul>
        <?php else : ?>
            <p><?php esc_html_e( 'No artist profiles yet.', 'extra-chill-community' ); ?></p>
        <?php endif; ?>

        <?php
        // Management buttons - only show if viewing own profile or user is admin
        if ( bbp_get_displayed_user_id() == get_current_user_id() || current_user_can( 'manage_options' ) ) :
            $current_user_id_for_card_buttons = get_current_user_id();
            $artist_count = is_array( $user_artist_ids ) ? count( $user_artist_ids ) : 0;
            $base_manage_artists_url_card = ec_get_site_url( 'artist' ) . '/manage-artist/';

            echo '<div class="user-artist-management-actions">';

            if ( $artist_count > 0 ) :
                $artist_label = $artist_count === 1
                    ? esc_html__( 'Manage Artist', 'extra-chill-community' )
                    : esc_html__( 'Manage Artists', 'extra-chill-community' );
            ?>
                <a href="<?php echo esc_url( $base_manage_artists_url_card ); ?>" class="button-1 button-small"><?php echo $artist_label; ?></a>
            <?php else : // No artist profiles, but user can create ?>
                <a href="<?php echo esc_url( ec_get_site_url( 'artist' ) . '/create-artist/' ); ?>" class="button-1 button-small"><?php esc_html_e( 'Create Artist Profile', 'extra-chill-community' ); ?></a>
            <?php endif;

            echo '</div>'; // End .user-artist-management-actions
        endif; // End permission check
        ?>
    </div>
<?php endif; // End if user_is_artist or user_is_professional ?>

</div>

</div> <?php // End Flex Grid Container ?>

        </div> <?php // End Flex Grid Container ?>


    </div><!-- #bbp-user-profile -->

<?php do_action('bbp_template_after_user_profile'); ?>
