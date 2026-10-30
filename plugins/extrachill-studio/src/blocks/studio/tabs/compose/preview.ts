export interface ComposeSnapshot {
	title: string;
	content: string;
}

export interface AutosavePreviewResponse {
	id: number;
	parent: number;
	preview_link?: string;
}

export interface ComposePreviewResult {
	parentId: number;
	snapshot: ComposeSnapshot;
}

interface PreviewWindow {
	close: () => void;
	location: {
		replace: ( url: string ) => void;
	};
}

interface PreviewDependencies {
	openWindow: () => PreviewWindow | null;
	cancelPendingSave: () => void;
	waitForPendingSaves: () => Promise< void >;
	getSnapshot: () => ComposeSnapshot;
	getParentId: () => number | null;
	createDraft: ( snapshot: ComposeSnapshot ) => Promise< number >;
	setParentId: ( postId: number ) => void;
	createAutosave: (
		postId: number,
		snapshot: ComposeSnapshot
	) => Promise< AutosavePreviewResponse >;
}

function getPreviewUrl( response: AutosavePreviewResponse ): string {
	if ( ! response.preview_link ) {
		throw new Error( 'WordPress did not return a preview link.' );
	}

	let previewUrl: URL;
	try {
		previewUrl = new URL( response.preview_link );
	} catch {
		throw new Error( 'WordPress returned an invalid preview link.' );
	}

	if (
		previewUrl.protocol !== 'https:' ||
		previewUrl.hostname !== 'extrachill.com'
	) {
		throw new Error(
			'WordPress returned a preview link for the wrong site.'
		);
	}

	return previewUrl.toString();
}

/**
 * Open a real WordPress frontend preview from Studio's live editor state.
 *
 * @param dependencies Browser and persistence operations supplied by Compose.
 */
export async function openComposePreview(
	dependencies: PreviewDependencies
): Promise< ComposePreviewResult > {
	const previewWindow = dependencies.openWindow();
	if ( ! previewWindow ) {
		throw new Error(
			'Preview was blocked. Allow pop-ups for Studio and try again.'
		);
	}

	try {
		dependencies.cancelPendingSave();
		await dependencies.waitForPendingSaves();

		const snapshot = dependencies.getSnapshot();
		if ( ! snapshot.title && ! snapshot.content ) {
			throw new Error( 'Add a title or content before previewing.' );
		}

		let parentId = dependencies.getParentId();
		if ( ! parentId ) {
			parentId = await dependencies.createDraft( snapshot );
			if ( ! parentId ) {
				throw new Error( 'WordPress did not return the new draft ID.' );
			}
			dependencies.setParentId( parentId );
		}

		const autosave = await dependencies.createAutosave(
			parentId,
			snapshot
		);
		previewWindow.location.replace( getPreviewUrl( autosave ) );
		return { parentId, snapshot };
	} catch ( error ) {
		previewWindow.close();
		throw error;
	}
}
