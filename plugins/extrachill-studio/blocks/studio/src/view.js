import { createElement, useState } from '@wordpress/element';
import Tabs from '@extrachill/components/components/Tabs';
import '@extrachill/components/styles/components.scss';
import '@extrachill/chat/css';

import { mountComponent } from './app/mount';
import { getStudioTabs } from './app/tabs';
import ComposePane from './tabs/compose';
import ChatPane from './tabs/chat';
import QrCodesPane from './tabs/qr-codes';
import SocialsPane from './tabs/socials';

const ROOT_SELECTOR = '[data-ec-studio-root]';

const STUDIO_PANES = {
	compose: ComposePane,
	chat: ChatPane,
	'qr-codes': QrCodesPane,
	socials: SocialsPane,
};

const StudioApp = ( { context } ) => {
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

const initRoot = ( root ) => {
	if ( ! root || root.dataset.ecStudioMounted === 'true' ) {
		return;
	}

	root.dataset.ecStudioMounted = 'true';

	const context = {
		userName: root.dataset.userName || '',
		siteName: root.dataset.siteName || '',
		siteUrl: root.dataset.siteUrl || '',
		restNonce: root.dataset.restNonce || '',
		socialsApiBase: root.dataset.socialsApiBase || '',
	};

	const appMount = root.querySelector( '[data-ec-studio-app]' );
	mountComponent( appMount, createElement( StudioApp, { context } ) );
};

const init = () => {
	document.querySelectorAll( ROOT_SELECTOR ).forEach( initRoot );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
