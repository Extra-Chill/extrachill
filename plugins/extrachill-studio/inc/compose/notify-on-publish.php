<?php
/**
 * Register Studio submissions into the publish-notify substrate.
 *
 * Closes the submission lifecycle loop (Extra-Chill/extrachill-studio#115):
 * submit → review queue (#109/#111) → publish → **the writer is told**. Until
 * now a contributor only learned their post went live if someone told them by
 * hand (that is how Steve Hughes found out his Jinjer recap published).
 *
 * WHY THIS IS JUST A REGISTRATION (and where the work actually happens)
 * --------------------------------------------------------------------
 * Studio Compose submissions are BORN ON MAIN (blog 1) and published there in
 * main's native editor (see inc/compose/rest.php and the review queue). This
 * plugin is active ONLY on the Studio subsite (blog 12), so the publish
 * `transition_post_status` fires in a MAIN request where Studio's PHP never
 * loads — a `transition_post_status` hook added here would never run for the
 * publish. That is the whole reason the observer lives in extrachill-users
 * (Network:true, loads on main) as a generic producer.
 *
 * Studio therefore does not hook the transition at all. It only DECLARES its
 * submissions into the substrate's descriptor registry via
 * ec_users_register_publish_notify_source(). That call persists a small,
 * data-only descriptor to a NETWORK SITE-OPTION. Because a site-option is
 * network-global, the descriptor Studio writes here on blog 12 is readable by
 * the substrate observer running on main — no Studio code needs to execute in
 * the publishing request. This is what makes the notification actually fire
 * despite Studio being subsite-only: the registration crosses the
 * activation-scope boundary as persisted data, not as a per-request hook.
 *
 * The descriptor points the generic observer at the `_ec_studio_submission`
 * provenance marker (#107) — an array carrying the submitter's `user_id` — and
 * supplies the notification type + title copy. The substrate resolves the
 * recipient, links the live permalink, and creates one idempotent notification
 * through the network receipt service.
 *
 * @package    ExtraChillStudio
 * @subpackage Compose
 * @since      0.20.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Context slug Studio owns in the publish-notify registry (dedupe namespace).
 */
const EC_STUDIO_PUBLISH_NOTIFY_CONTEXT = 'studio_compose';

/**
 * Notification type for a published Studio submission.
 */
const EC_STUDIO_PUBLISH_NOTIFY_TYPE = 'studio_published';

/**
 * Declare Studio submissions into the extrachill-users publish-notify registry.
 *
 * Idempotent and cheap (the substrate no-ops the write when the descriptor is
 * unchanged), so running it on every init on the Studio subsite is fine. The
 * descriptor is persisted network-wide, which is exactly how it becomes visible
 * to the observer on main.
 *
 * Guards on the substrate function existing so Studio degrades cleanly if
 * extrachill-users is absent or predates the contract.
 *
 * @since 0.20.1
 *
 * @return void
 */
function ec_studio_register_publish_notify_source(): void {
	if ( ! function_exists( 'ec_users_register_publish_notify_source' ) ) {
		return;
	}

	ec_users_register_publish_notify_source(
		EC_STUDIO_PUBLISH_NOTIFY_CONTEXT,
		array(
			// The born-on-main provenance marker (#107): an array carrying the
			// submitter's user_id. Defined in inc/compose/rest.php.
			'meta_key'       => EC_STUDIO_SUBMISSION_META,
			'user_id_field'  => 'user_id',
			'type'           => EC_STUDIO_PUBLISH_NOTIFY_TYPE,
			/* translators: %s: the published post title. */
			'title_template' => __( 'Your post "%s" is live on Extra Chill', 'extrachill-studio' ),
		)
	);
}
add_action( 'init', 'ec_studio_register_publish_notify_source', 20 );
