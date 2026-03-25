import { __ } from '@wordpress/i18n';
import { createElement, useState } from '@wordpress/element';
import type { ReactElement } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { Chat } from '@extrachill/chat';

const STUDIO_AGENT_ID = 5;
const CHAT_BASE_PATH = '/datamachine/v1/chat';

/**
 * Floating Roadie chat — mounted outside the Studio block in wp_footer.
 *
 * Bottom-right FAB button that opens a full-screen chat overlay on mobile
 * and a fixed drawer on desktop.
 */
const FloatingChat = (): ReactElement => {
	const [ isOpen, setIsOpen ] = useState( false );

	if ( ! isOpen ) {
		// Closed state: just the FAB button
		return createElement(
			'button',
			{
				type: 'button',
				className: 'ec-roadie-fab',
				onClick: () => setIsOpen( true ),
				'aria-label': __( 'Open Roadie chat', 'extrachill-studio' ),
			},
			__( 'Roadie', 'extrachill-studio' )
		);
	}

	// Open state: backdrop + panel
	return createElement(
		'div',
		{ className: 'ec-roadie-overlay' },

		// Backdrop
		createElement( 'div', {
			className: 'ec-roadie-backdrop',
			onClick: () => setIsOpen( false ),
		} ),

		// Panel
		createElement(
			'div',
			{ className: 'ec-roadie-panel' },

			// Header
			createElement(
				'div',
				{ className: 'ec-roadie-panel__header' },
				createElement( 'span', { className: 'ec-roadie-panel__title' }, __( 'Roadie', 'extrachill-studio' ) ),
				createElement(
					'button',
					{
						type: 'button',
						className: 'ec-roadie-panel__close',
						onClick: () => setIsOpen( false ),
						'aria-label': __( 'Close', 'extrachill-studio' ),
					},
					'✕'
				)
			),

			// Chat
			createElement(
				'div',
				{ className: 'ec-roadie-panel__body' },
				createElement( Chat, {
					basePath: CHAT_BASE_PATH,
					fetchFn: apiFetch,
					agentId: STUDIO_AGENT_ID,
					showTools: true,
					showSessions: true,
					placeholder: __( 'Ask Roadie anything…', 'extrachill-studio' ),
					metadata: {
						client_context: {
							site: 'studio.extrachill.com',
						},
					},
					emptyState: createElement(
						'div',
						{ className: 'ec-roadie-empty' },
						createElement( 'h3', null, __( 'Roadie', 'extrachill-studio' ) ),
						createElement( 'p', null, __( 'Proofread drafts, manage socials, and run platform operations.', 'extrachill-studio' ) )
					),
					processingLabel: ( turnCount: number ) =>
						__( `Working... (turn ${ turnCount })`, 'extrachill-studio' ),
				} )
			)
		)
	);
};

export default FloatingChat;
