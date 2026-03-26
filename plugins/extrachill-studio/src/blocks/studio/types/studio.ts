/**
 * Shared types for the Studio block.
 */

export interface StudioContext {
	userName: string;
	siteName: string;
	siteUrl: string;
	restNonce: string;
	socialsApiBase: string;
	headline: string;
	description: string;
}

export interface StudioTab {
	id: string;
	pane: string;
	label: string;
	preview: string;
}

export interface StudioPaneProps {
	context: StudioContext;
}
