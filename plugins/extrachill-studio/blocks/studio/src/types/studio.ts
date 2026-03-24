/**
 * Shared types for the Studio block.
 */

export interface StudioContext {
	userName: string;
	siteName: string;
	siteUrl: string;
	restNonce: string;
	socialsApiBase: string;
}

export interface StudioTab {
	id: string;
	pane: string;
	label: string;
	preview: string;
}

export interface StudioPaneProps {
	context: StudioContext;
	/** Called by the Compose pane when the active draft changes. */
	onDraftChange?: ( postId: number | null, title: string ) => void;
}
