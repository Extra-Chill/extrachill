import { createElement, useState } from '@wordpress/element';
import type { ComponentType, ReactElement } from 'react';
import { BlockShell, BlockShellHeader, BlockShellInner, ResponsiveTabs } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';

import { mountComponent } from './app/mount';
import { getStudioTabs } from './app/tabs';
import type { StudioContext, StudioPaneProps } from './types/studio';
import ComposePane from './tabs/compose';
import QrCodesPane from './tabs/qr-codes';
import SocialsPane from './tabs/socials';
import TranscribePane from './tabs/transcribe';

const ROOT_SELECTOR = '[data-ec-studio-root]';

const STUDIO_PANES: Record< string, ComponentType< StudioPaneProps > > = {
	compose: ComposePane,
	'qr-codes': QrCodesPane,
	socials: SocialsPane,
	transcribe: TranscribePane,
};

const StudioApp = ( { context }: { context: StudioContext } ): ReactElement => {
	const tabs = getStudioTabs( { canBrandSocials: context.canBrandSocials } );
	const [ activeTab, setActiveTab ] = useState( tabs[ 0 ]?.id || 'compose' );
	const renderPanel = ( id: string ): ReactElement => {
		const ActivePane = STUDIO_PANES[ id ] || ComposePane;
		return createElement( ActivePane, { context } );
	};

	return createElement(
		BlockShell,
		{
			children: createElement(
				BlockShellInner,
				{
					maxWidth: 'wide',
					children: [
						createElement( BlockShellHeader, {
							key: 'header',
							title: context.headline || 'Studio',
							description: context.description,
						} ),
						createElement( ResponsiveTabs, {
							key: 'tabs',
							tabs,
							active: activeTab,
							onChange: setActiveTab,
							renderPanel,
							showDesktopTabs: true,
							// Broadcast the active Studio tab into the shared
							// client-context registry so Roadie's chat knows
							// which tab the team member is on. Generic — handled
							// entirely by ResponsiveTabs; Studio just names the
							// surface. The Compose pane layers its richer
							// draft-specific context on top at higher priority.
							contextSurface: 'studio',
						} ),
					],
				}
			),
		},
	);
};

const initRoot = ( root: HTMLElement ): void => {
	if ( ! root || root.dataset.ecStudioMounted === 'true' ) {
		return;
	}

	root.dataset.ecStudioMounted = 'true';

	let socialPlatforms: string[] = [];
	try {
		socialPlatforms = JSON.parse( root.dataset.socialPlatforms || '[]' );
	} catch {
		socialPlatforms = [];
	}

	const context: StudioContext = {
		userName: root.dataset.userName || '',
		siteName: root.dataset.siteName || '',
		siteUrl: root.dataset.siteUrl || '',
		restNonce: root.dataset.restNonce || '',
		socialsApiBase: root.dataset.socialsApiBase || '',
		headline: root.dataset.headline || '',
		description: root.dataset.description || '',
		socialPlatforms,
		canBrandSocials: root.dataset.canBrandSocials === 'true',
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
