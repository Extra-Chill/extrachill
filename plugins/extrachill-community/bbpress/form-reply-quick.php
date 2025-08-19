<?php

/**
 * New/Edit Reply (Quick Version)
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// REMOVED test echo

// Note: This template assumes it's ONLY used for creating NEW replies via the quick reply button.
// Edit-specific logic might need adjustments if quick reply is ever used for editing.

// REMOVED: Commented out conditional wrapper div containing PHP tags

?>
<div id="new-reply-quick-<?php bbp_topic_id(); ?>" class="bbp-reply-form"> <?php // Unique container ID ?>

    <form id="new-post-quick" name="new-post-quick" method="post"> <?php // Unique form ID/Name ?>

        <?php do_action( 'bbp_theme_before_reply_form' ); ?>

        <fieldset class="bbp-form">
            <?php // REMOVED: Commented out legend containing PHP tags and surrounding PHP tags ?>
             <legend>Quick Reply</legend>

            <?php do_action( 'bbp_theme_before_reply_form_notices' ); ?>

            <?php // Removed closed topic/forum notices as the button shouldn't show if closed ?>


            <?php do_action( 'bbp_template_notices' ); ?>

            <div>

                <?php // No anonymous form needed as user must be logged in ?>
                <?php // bbp_get_template_part( 'form', 'anonymous' ); ?>

                <?php do_action( 'bbp_theme_before_reply_form_content' ); ?>

                <?php 
                // *** Replace bbp_the_content with direct wp_editor call ***
                $editor_content = bbp_is_reply_edit() ? bbp_get_form_reply_content() : ''; // Handle potential edit case if needed later
                
                // Use unique ID for mobile instance
                if ( isset($GLOBALS['extrachill_is_mobile_quick_reply']) && $GLOBALS['extrachill_is_mobile_quick_reply'] ) {
                    $editor_id = 'bbp_reply_content_quick_mobile';
                } else {
                    $editor_id = 'bbp_reply_content_quick'; // Default/Desktop ID
                }

                $editor_settings = array(
                    'textarea_name' => 'bbp_reply_content', // Keep name the same for bbPress processing
                    'textarea_rows' => 8, // Adjust rows as needed for quick reply
                    'editor_class'  => 'bbp-the-content',
                    // Match settings from bbp_enable_visual_editor filter:
                    'teeny'         => false,
                    'quicktags'     => false, 
                    'content_css'   => '/wp-content/themes/extra-chill-community/css/tinymce-editor.css',
                    // Original settings:
                    'dfw'           => false,
                    'tinymce'       => true,  // Use TinyMCE
                    'media_buttons' => false // Disable Add Media button
                );
                wp_editor( $editor_content, $editor_id, $editor_settings );
                ?>

                <?php do_action( 'bbp_theme_after_reply_form_content' ); ?>

                <?php // Removed allowed tags notice as editor provides formatting ?>

                <?php // Removed topic tags input - quick reply shouldn't set tags ?>

                <?php // Keep subscription checkbox if desired for quick reply ?>
                <?php if ( bbp_is_subscriptions_active() && ! bbp_is_anonymous() && !bbp_is_reply_edit() ) : ?>
                    <?php do_action( 'bbp_theme_before_reply_form_subscription' ); ?>
                    <p>
                        <input name="bbp_topic_subscription" id="bbp_topic_subscription_quick" type="checkbox" value="bbp_subscribe"<?php bbp_form_topic_subscribed(); ?> /> <?php // Unique ID ?>
                        <label for="bbp_topic_subscription_quick"><?php esc_html_e( 'Notify me of follow-up replies via email', 'bbpress' ); ?></label>
                    </p>
                    <?php do_action( 'bbp_theme_after_reply_form_subscription' ); ?>
                <?php endif; ?>

                <?php // Removed all edit-specific fields (status, revisions, reply_to dropdown) ?>

                <?php do_action( 'bbp_theme_before_reply_form_submit_wrapper' ); ?>

                <div class="bbp-submit-wrapper">
                    <?php do_action( 'bbp_theme_before_reply_form_submit_button' ); ?>
                    <?php // No cancel link needed for quick reply ?>
                    <?php // bbp_cancel_reply_to_link(); ?>
                    <button type="submit" id="bbp_reply_submit_quick" name="bbp_reply_submit" class="button submit"><?php esc_html_e( 'Submit', 'bbpress' ); ?></button> <?php // Unique ID ?>
                    <?php do_action( 'bbp_theme_after_reply_form_submit_button' ); ?>
                </div>

                <?php do_action( 'bbp_theme_after_reply_form_submit_wrapper' ); ?>

            </div>

            <?php bbp_reply_form_fields(); // Keep necessary hidden fields ?>

        </fieldset>

        <?php do_action( 'bbp_theme_after_reply_form' ); ?>

    </form>
</div>

<?php // if ( bbp_is_reply_edit() ) : ?> <?php // endif; ?> 