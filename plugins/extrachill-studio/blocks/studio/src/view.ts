import { createElement, useState } from '@wordpress/element';
import type { ComponentType, ReactElement } from 'react';
import Tabs from '@extrachill/components/components/Tabs';
import '@extrachill/components/styles/components.scss';
import '@extrachill/chat/css';

import { mountComponent } from './app/mount';
import { getStudioTabs } from './app/tabs';
import FloatingChat from './app/floating-chat';
import type { StudioContext, StudioPaneProps } from './types/studio';
import ComposePane from './tabs/compose';
import QrCodesPane from './tabs/qr-codes';
import SocialsPane from './tabs/socials';

const ROOT_SELECTOR = '[data-ec-studio-root]';
const CHAT_MOUNT_SELECTOR = '[data-ec-studio-chat]';

const STUDIO_PANES: Record< string, ComponentType< StudioPaneProps > > = {
	compose: ComposePane,
	'qr-codes': QrCodesPane,
	socials: SocialsPane,
};

const StudioApp = ( { context }: { context: StudioContext } ): ReactElement => {
	const tabs = getStudioTabs();
	const [ activeTab, setActiveTab ] = useState( tabs[ 0 ]?.id || 'compose' );
	const ActivePane = STUDIO_PANES[ activeTab ] || ComposePane;

	return createElement(
		'div',
		{ className: 'ec-studio-app' },
		createElement( Tabs, {
			tabs,
			active: activeTab,
			onChange: setActiveTab,
			classPrefix: 'ec-studio',
		} ),
		createElement(
			'div',
			{ className: 'ec-studio-app__panel', role: 'tabpanel' },
			createElement( ActivePane, { context } )
		)
	);
};

const initRoot = ( root: HTMLElement ): void => {
	if ( ! root || root.dataset.ecStudioMounted === 'true' ) {
		return;
	}

	root.dataset.ecStudioMounted = 'true';

	const context: StudioContext = {
		userName: root.dataset.userName || '',
		siteName: root.dataset.siteName || '',
		siteUrl: root.dataset.siteUrl || '',
		restNonce: root.dataset.restNonce || '',
		socialsApiBase: root.dataset.socialsApiBase || '',
	};

	const appMount = root.querySelector< HTMLElement >( '[data-ec-studio-app]' );
	mountComponent( appMount, createElement( StudioApp, { context } ) );
};

/**
 * Mount the floating chat into the PHP-rendered container in wp_footer.
 * This is separate from the studio block — it's a viewport-level overlay.
 *
 * The container may not exist yet when this script runs (viewScript loads
 * via block enqueue, which can fire before wp_footer priority 50). We use
 * a MutationObserver to catch it when it appears, with a fallback poll.
 */
const initFloatingChat = (): void => {
	const tryMount = (): boolean => {
		const chatMount = document.querySelector< HTMLElement >( CHAT_MOUNT_SELECTOR );
		if ( ! chatMount || chatMount.dataset.ecChatMounted === 'true' ) {
			return chatMount !== null;
		}

		chatMount.dataset.ecChatMounted = 'true';
		mountComponent( chatMount, createElement( FloatingChat ) );
		return true;
	};

	if ( tryMount() ) {
		return;
	}

	// Container not in DOM yet — watch for it.
	const observer = new MutationObserver( () => {
		if ( tryMount() ) {
			observer.disconnect();
		}
	} );

	observer.observe( document.body, { childList: true, subtree: true } );

	// Safety timeout — stop observing after 10s.
	setTimeout( () => observer.disconnect(), 10000 );
};

const init = (): void => {
	document.querySelectorAll< HTMLElement >( ROOT_SELECTOR ).forEach( initRoot );
	initFloatingChat();
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
