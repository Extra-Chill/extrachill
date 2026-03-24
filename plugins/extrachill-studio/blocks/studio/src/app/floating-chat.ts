import { __ } from '@wordpress/i18n';
import { createElement, useState } from '@wordpress/element';
import type { ReactElement } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { Chat } from '@extrachill/chat';

const STUDIO_AGENT_ID = 5;
const CHAT_BASE_PATH = '/datamachine/v1/chat';

interface FloatingChatProps {
	activeTab: string;
	activePostId: number | null;
	activePostTitle: string;
}

/**
 * Floating chat panel — persistent across all Studio tabs.
 *
 * Collapsible sidebar that provides Roadie chat with client context
 * awareness. The agent knows which tab the user is on and what draft
 * they're editing.
 */
const FloatingChat = ( { activeTab, activePostId, activePostTitle }: FloatingChatProps ): ReactElement => {
	const [ isOpen, setIsOpen ] = useState( false );

	const clientContext: Record< string, unknown > = {
		site: 'studio.extrachill.com',
		tab: activeTab,
	};

	if ( activePostId ) {
		clientContext.post_id = activePostId;
	}
	if ( activePostTitle ) {
		clientContext.post_title = activePostTitle;
	}

	return createElement(
		'div',
		{ className: `ec-studio-floating-chat ${ isOpen ? 'is-open' : 'is-closed' }` },

		// Toggle button
		createElement(
			'button',
			{
				type: 'button',
				className: 'ec-studio-floating-chat__toggle',
				onClick: () => setIsOpen( ( open ) => ! open ),
				'aria-label': isOpen ? __( 'Close Roadie', 'extrachill-studio' ) : __( 'Open Roadie', 'extrachill-studio' ),
			},
			isOpen
				? __( '✕', 'extrachill-studio' )
				: __( 'Roadie', 'extrachill-studio' )
		),

		// Chat panel (always rendered, hidden via CSS when closed to preserve state)
		createElement(
			'div',
			{ className: 'ec-studio-floating-chat__panel' },
			createElement( Chat, {
				basePath: CHAT_BASE_PATH,
				fetchFn: apiFetch,
				agentId: STUDIO_AGENT_ID,
				showTools: true,
				showSessions: true,
				placeholder: __( 'Ask Roadie anything…', 'extrachill-studio' ),
				metadata: {
					client_context: clientContext,
				},
				emptyState: createElement(
					'div',
					{ className: 'ec-studio-chat-empty' },
					createElement( 'h3', null, __( 'Roadie', 'extrachill-studio' ) ),
					createElement(
						'p',
						null,
						__( 'Proofread drafts, manage socials, and run platform operations.', 'extrachill-studio' )
					)
				),
				processingLabel: ( turnCount: number ) =>
					__( `Working... (turn ${ turnCount })`, 'extrachill-studio' ),
				className: 'ec-studio-floating-chat__chat',
			} )
		)
	);
};

export default FloatingChat;
