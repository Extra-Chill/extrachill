import { __ } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Chat } from '@extrachill/chat';

/**
 * Studio Chat tab.
 *
 * Mounts the @extrachill/chat Chat component, scoped to the Studio agent
 * (agent_id=5). The chat component handles sessions, messages, tool calls,
 * and continuation loops — this pane just provides the mount point and config.
 */
const STUDIO_AGENT_ID = 5;
const CHAT_BASE_PATH = '/datamachine/v1/chat';

const ChatPane = () =>
	createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--chat' },
		createElement( Chat, {
			basePath: CHAT_BASE_PATH,
			fetchFn: apiFetch,
			agentId: STUDIO_AGENT_ID,
			showTools: true,
			showSessions: true,
			placeholder: __( 'Ask the Studio agent...', 'extrachill-studio' ),
			emptyState: createElement(
				'div',
				{ className: 'ec-studio-chat-empty' },
				createElement( 'h3', null, __( 'Studio Agent', 'extrachill-studio' ) ),
				createElement(
					'p',
					null,
					__( 'Chat with the Studio agent to manage socials, generate content, and operate the Extra Chill platform.', 'extrachill-studio' )
				)
			),
			processingLabel: ( turnCount ) =>
				/* translators: %d is the current turn number */
				// eslint-disable-next-line @wordpress/i18n-no-variables
				__( `Working... (turn ${ turnCount })`, 'extrachill-studio' ),
		} )
	);

export default ChatPane;
