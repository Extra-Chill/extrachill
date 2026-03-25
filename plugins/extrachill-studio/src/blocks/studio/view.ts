import { createElement, useState } from '@wordpress/element';
import type { ComponentType, ReactElement } from 'react';
import { Panel, Tabs } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';

import { mountComponent } from './app/mount';
import { getStudioTabs } from './app/tabs';
import type { StudioContext, StudioPaneProps } from './types/studio';
import ComposePane from './tabs/compose';
import QrCodesPane from './tabs/qr-codes';
import SocialsPane from './tabs/socials';

const ROOT_SELECTOR = '[data-ec-studio-root]';

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
		createElement( Panel, { className: 'ec-studio-app__panel', classPrefix: 'ec-panel', compact: true }, createElement( ActivePane, { context } ) )
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

const init = (): void => {
	document.querySelectorAll< HTMLElement >( ROOT_SELECTOR ).forEach( initRoot );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
