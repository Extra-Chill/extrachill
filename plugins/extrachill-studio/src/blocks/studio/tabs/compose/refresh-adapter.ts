/**
 * Chat → editor refresh adapter.
 *
 * The ONE place in the stack that knows BOTH event names — by design. It
 * translates the chat's generic "a pending action resolved" announcement
 * (`frontend-agent-chat:action-resolved`, emitted by frontend-agent-chat) into
 * Blocks Everywhere's generic "your post changed externally, refresh"
 * signal (`blocksEverywhere:refresh-content`, consumed by blocks-everywhere).
 *
 * This mirrors how extrachill-roadie bridges `access_roadie` →
 * `datamachine_can_access_agent`: the two generic layers never reference each
 * other; a thin host-owned adapter couples them.
 *
 * - frontend-agent-chat must not know about Blocks Everywhere / Studio.
 * - blocks-everywhere must not know about the chat.
 * - So the coupling lives here, and only here.
 *
 * Post-id matching is intentionally NOT done here: blocks-everywhere's receiver
 * is told which post it is watching (via the Compose tab's `watchPostId` wiring)
 * and ignores events for any other post. The adapter just forwards the identity
 * the chat surfaced and lets the receiver decide whether it applies. We forward
 * only `accepted` decisions, since a rejected action did not change the post and
 * so there is nothing to refresh.
 */

/**
 * The chat event this adapter listens for.
 * @see Automattic/frontend-agent-chat#84
 */
const CHAT_ACTION_RESOLVED_EVENT = 'frontend-agent-chat:action-resolved';

/**
 * The Blocks Everywhere event this adapter dispatches.
 * @see Extra-Chill/blocks-everywhere#107
 */
const BE_REFRESH_CONTENT_EVENT = 'blocksEverywhere:refresh-content';

interface ActionResolvedDetail {
	action_id?: string;
	decision?: string;
	post_id?: number | string;
	blog_id?: number | string;
	kind?: string;
}

/**
 * Install the chat → editor refresh adapter.
 *
 * Listens on `window` for the chat's action-resolved event and, on an accepted
 * decision that carries a post id, dispatches the Blocks Everywhere refresh
 * event on `document` (where BE's receiver listens). Returns a cleanup function
 * that removes the listener.
 *
 * @return {() => void} Cleanup function that removes the listener.
 */
export function installChatRefreshAdapter(): () => void {
	const handler = ( event: Event ): void => {
		const detail =
			( event as CustomEvent< ActionResolvedDetail > ).detail || {};

		// Only accepted resolutions changed the post; rejected ones leave it
		// untouched, so there is nothing for the editor to refresh.
		if ( detail.decision !== 'accepted' ) {
			return;
		}

		const postId = detail.post_id;
		if ( postId === undefined || postId === null || postId === '' ) {
			return;
		}

		// Forward as the BE-generic refresh signal. BE's receiver matches the
		// post id against the editor's watched post and ignores mismatches.
		document.dispatchEvent(
			new CustomEvent( BE_REFRESH_CONTENT_EVENT, {
				detail: {
					postId,
					blogId: detail.blog_id,
					kind: detail.kind,
				},
			} )
		);
	};

	window.addEventListener( CHAT_ACTION_RESOLVED_EVENT, handler );

	return () => {
		window.removeEventListener( CHAT_ACTION_RESOLVED_EVENT, handler );
	};
}
