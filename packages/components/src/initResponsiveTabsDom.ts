export interface ResponsiveTabsDomOptions {
	selector?: string;
	mobileBreakpoint?: number;
	hashPrefix?: string;
	onPanelRender?: ( panel: HTMLElement, tabId: string, root: HTMLElement ) => void;
}

const DEFAULT_SELECTOR = '[data-ec-responsive-tabs]';

function createNodeFromTemplate( template: HTMLTemplateElement ): HTMLElement | null {
	const fragment = template.content.cloneNode( true );
	const wrapper = document.createElement( 'div' );
	wrapper.appendChild( fragment );

	return wrapper.firstElementChild instanceof HTMLElement ? wrapper.firstElementChild : wrapper;
}

function renderDesktopPanel(
	root: HTMLElement,
	tabId: string,
	onPanelRender?: ResponsiveTabsDomOptions['onPanelRender']
) {
	const desktopPanel = root.querySelector<HTMLElement>( '.ec-responsive-tabs__desktop-panel' );
	const template = root.querySelector<HTMLTemplateElement>( `template[data-tab-panel="${ tabId }"]` );

	if ( ! desktopPanel || ! template ) {
		return;
	}

	desktopPanel.innerHTML = '';
	const content = createNodeFromTemplate( template );
	if ( ! content ) {
		return;
	}

	desktopPanel.appendChild( content );
	onPanelRender?.( desktopPanel, tabId, root );
}

function renderAccordion(
	root: HTMLElement,
	activeTabId: string | null,
	hashPrefix: string,
	onPanelRender?: ResponsiveTabsDomOptions['onPanelRender']
) {
	const accordion = root.querySelector<HTMLElement>( '.ec-responsive-tabs__accordion' );
	const tabs = Array.from( root.querySelectorAll<HTMLElement>( '.ec-tabs__tab[data-tab-id]' ) );

	if ( ! accordion ) {
		return;
	}

	accordion.innerHTML = '';

	tabs.forEach( ( tab ) => {
		const tabId = tab.dataset.tabId;
		if ( ! tabId ) {
			return;
		}

		const label = tab.textContent?.trim() || tabId;
		const item = document.createElement( 'div' );
		item.className = `ec-responsive-tabs__item${ tabId === activeTabId ? ' is-active' : '' }`;

		const trigger = document.createElement( 'button' );
		trigger.type = 'button';
		trigger.className = `ec-responsive-tabs__trigger${ tabId === activeTabId ? ' is-active' : '' }`;
		trigger.setAttribute( 'aria-expanded', tabId === activeTabId ? 'true' : 'false' );
		trigger.dataset.tabId = tabId;
		trigger.innerHTML = `<span>${ label }</span><span class="ec-responsive-tabs__arrow" aria-hidden="true">${ tabId === activeTabId ? '▲' : '▼' }</span>`;
		item.appendChild( trigger );

		if ( tabId === activeTabId ) {
			const template = root.querySelector<HTMLTemplateElement>( `template[data-tab-panel="${ tabId }"]` );
			if ( template ) {
				const panel = document.createElement( 'div' );
				panel.className = 'ec-responsive-tabs__panel';
				const content = createNodeFromTemplate( template );
				if ( content ) {
					panel.appendChild( content );
					item.appendChild( panel );
					onPanelRender?.( panel, tabId, root );
				}
			}
		}

		trigger.addEventListener( 'click', () => {
			const isActive = root.dataset.activeTab === tabId;
			if ( isActive ) {
				root.dataset.activeTab = '';
				window.location.hash = '';
				renderAccordion( root, null, hashPrefix, onPanelRender );
				return;
			}

			root.dataset.activeTab = tabId;
			window.location.hash = `${ hashPrefix }${ tabId }`;
			renderDesktopPanel( root, tabId, onPanelRender );
			renderAccordion( root, tabId, hashPrefix, onPanelRender );
			setActiveDesktopTab( root, tabId );

			// Scroll the newly-opened trigger into view after the panel renders.
			requestAnimationFrame( () => {
				const newTrigger = root.querySelector<HTMLElement>(
					`.ec-responsive-tabs__trigger[data-tab-id="${ tabId }"]`
				);
				newTrigger?.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			} );
		} );

		accordion.appendChild( item );
	} );
}

function setActiveDesktopTab( root: HTMLElement, activeTabId: string ) {
	root.querySelectorAll<HTMLElement>( '.ec-tabs__tab[data-tab-id]' ).forEach( ( tab ) => {
		tab.classList.toggle( 'is-active', tab.dataset.tabId === activeTabId );
		if ( tab.dataset.tabId === activeTabId ) {
			tab.setAttribute( 'aria-selected', 'true' );
		} else {
			tab.setAttribute( 'aria-selected', 'false' );
		}
	} );
}

function resolveHashTabId( root: HTMLElement, hashPrefix: string ) {
	const normalized = window.location.hash.replace( /^#/, '' );
	if ( ! normalized.startsWith( hashPrefix ) ) {
		return null;
	}

	const id = normalized.slice( hashPrefix.length );
	return root.querySelector<HTMLElement>( `.ec-tabs__tab[data-tab-id="${ id }"]` ) ? id : null;
}

function initResponsiveTabsRoot( root: HTMLElement, options: ResponsiveTabsDomOptions ) {
	if ( root.dataset.ecResponsiveTabsInitialized === '1' ) {
		return;
	}

	root.dataset.ecResponsiveTabsInitialized = '1';

	const hashPrefix = root.dataset.hashPrefix || options.hashPrefix || 'tab-';
	const tabs = Array.from( root.querySelectorAll<HTMLElement>( '.ec-tabs__tab[data-tab-id]' ) );
	const firstTabId = tabs[ 0 ]?.dataset.tabId || '';
	const initialTabId = resolveHashTabId( root, hashPrefix ) || root.dataset.activeTab || firstTabId;

	if ( ! initialTabId ) {
		return;
	}

	root.dataset.activeTab = initialTabId;
	setActiveDesktopTab( root, initialTabId );
	renderDesktopPanel( root, initialTabId, options.onPanelRender );
	renderAccordion( root, initialTabId, hashPrefix, options.onPanelRender );

	tabs.forEach( ( tab ) => {
		tab.addEventListener( 'click', () => {
			const tabId = tab.dataset.tabId;
			if ( ! tabId ) {
				return;
			}

			root.dataset.activeTab = tabId;
			window.location.hash = `${ hashPrefix }${ tabId }`;
			setActiveDesktopTab( root, tabId );
			renderDesktopPanel( root, tabId, options.onPanelRender );
			renderAccordion( root, tabId, hashPrefix, options.onPanelRender );
		} );
	} );

	window.addEventListener( 'hashchange', () => {
		const tabId = resolveHashTabId( root, hashPrefix );
		if ( ! tabId ) {
			return;
		}

		root.dataset.activeTab = tabId;
		setActiveDesktopTab( root, tabId );
		renderDesktopPanel( root, tabId, options.onPanelRender );
		renderAccordion( root, tabId, hashPrefix, options.onPanelRender );
	} );
}

export function initResponsiveTabsDom( options: ResponsiveTabsDomOptions = {} ) {
	if ( typeof document === 'undefined' ) {
		return;
	}

	const selector = options.selector || DEFAULT_SELECTOR;
	document.querySelectorAll<HTMLElement>( selector ).forEach( ( root ) => initResponsiveTabsRoot( root, options ) );
}
