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
 * and a fixed drawer on desktop. Always available on every Studio page.
 * Chat component stays mounted when closed (hidden via CSS) to preserve
 * conversation state.
 */
const FloatingChat = (): ReactElement => {
	const [ isOpen, setIsOpen ] = useState( false );

	return createElement(
		'div',
		{ className: `ec-roadie ${ isOpen ? 'is-open' : '' }` },

		// Overlay backdrop — click to close
		isOpen
			? createElement( 'div', {
				className: 'ec-roadie__backdrop',
				onClick: () => setIsOpen( false ),
			} )
			: null,

		// Chat panel — always rendered, visibility controlled by CSS
		createElement(
			'div',
			{ className: 'ec-roadie__panel' },
			createElement(
				'div',
				{ className: 'ec-roadie__header' },
				createElement( 'span', { className: 'ec-roadie__title' }, __( 'Roadie', 'extrachill-studio' ) ),
				createElement(
					'button',
					{
						type: 'button',
						className: 'ec-roadie__close',
						onClick: () => setIsOpen( false ),
						'aria-label': __( 'Close chat', 'extrachill-studio' ),
					},
					'✕'
				)
			),
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
					{ className: 'ec-roadie__empty' },
					createElement( 'h3', null, __( 'Roadie', 'extrachill-studio' ) ),
					createElement(
						'p',
						null,
						__( 'Proofread drafts, manage socials, and run platform operations.', 'extrachill-studio' )
					)
				),
				processingLabel: ( turnCount: number ) =>
					__( `Working... (turn ${ turnCount })`, 'extrachill-studio' ),
				className: 'ec-roadie__chat',
			} )
		),

		// FAB button — bottom right, visible when closed
		! isOpen
			? createElement(
				'button',
				{
					type: 'button',
					className: 'ec-roadie__fab',
					onClick: () => setIsOpen( true ),
					'aria-label': __( 'Open Roadie chat', 'extrachill-studio' ),
				},
				createElement( 'span', { className: 'ec-roadie__fab-label' }, __( 'Roadie', 'extrachill-studio' ) )
			)
			: null
	);
};

export default FloatingChat;
