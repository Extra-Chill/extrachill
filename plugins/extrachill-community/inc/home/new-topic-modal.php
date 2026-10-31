<?php
/**
 * New Topic Modal
 *
 * Modal container for the bbPress topic creation form on the community homepage.
 * For logged-in users: displays the full topic form with forum dropdown.
 * For logged-out users: bbPress displays the login/register block.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

$discussion_continuation = extrachill_community_can_continue_discussion_composer()
	? extrachill_community_get_discussion_composer_state()
	: null;

// bbPress otherwise treats the homepage as an edit-topic context and denies participants.
$homepage_topic_form_access = static function () {
	return is_user_logged_in()
		&& bbp_is_user_active()
		&& bbp_current_user_can_publish_topics();
};
?>

<div id="new-topic-modal-overlay" class="new-topic-modal-overlay"></div>
<div id="new-topic-modal" class="new-topic-modal" role="dialog" aria-modal="true" aria-labelledby="new-topic-modal-title" data-auto-open="<?php echo $discussion_continuation ? 'true' : 'false'; ?>">
	<div class="new-topic-modal-content">
		<button type="button" class="new-topic-modal-close" aria-label="<?php esc_attr_e( 'Close modal', 'extra-chill-community' ); ?>">&times;</button>
		<h2 id="new-topic-modal-title" class="new-topic-modal-title"><?php esc_html_e( 'New Community Post', 'extra-chill-community' ); ?></h2>
		<p id="new-topic-modal-description" class="new-topic-modal-description"><?php esc_html_e( 'Publish to the Extra Chill Community.', 'extra-chill-community' ); ?></p>
		<?php
		add_filter( 'bbp_current_user_can_access_create_topic_form', $homepage_topic_form_access );
		try {
			bbp_get_template_part( 'form', 'topic' );
		} finally {
			remove_filter( 'bbp_current_user_can_access_create_topic_form', $homepage_topic_form_access );
		}
		?>
	</div>
</div>
