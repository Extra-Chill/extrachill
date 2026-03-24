import { __ } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';
import type { ReactElement } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { Chat } from '@extrachill/chat';
import type { StudioPaneProps } from '../../types/studio';

/**
 * Studio Chat tab.
 *
 * Mounts the @extrachill/chat Chat component, scoped to the Studio agent
 * (agent_id=5). The chat component handles sessions, messages, tool calls,
 * and continuation loops — this pane just provides the mount point and config.
 */
const STUDIO_AGENT_ID = 5;
const CHAT_BASE_PATH = '/datamachine/v1/chat';

const ChatPane = ( _props: StudioPaneProps ): ReactElement =>
	createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--chat' },
		createElement( Chat, {
			basePath: CHAT_BASE_PATH,
			fetchFn: apiFetch,
			agentId: STUDIO_AGENT_ID,
			showTools: true,
			showSessions: true,
			placeholder: __( 'Ask Roadie anything…', 'extrachill-studio' ),
			emptyState: createElement(
				'div',
				{ className: 'ec-studio-chat-empty' },
				createElement( 'h3', null, __( 'Roadie', 'extrachill-studio' ) ),
				createElement(
					'p',
					null,
					__( 'Manage socials, draft content, check analytics, and run platform operations through chat.', 'extrachill-studio' )
				)
			),
			processingLabel: ( turnCount: number ) =>
				__( `Working... (turn ${ turnCount })`, 'extrachill-studio' ),
		} )
	);

export default ChatPane;
