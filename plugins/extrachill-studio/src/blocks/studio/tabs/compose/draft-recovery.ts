export interface WpPost {
	id: number;
	title: { rendered: string; raw?: string };
	content: { rendered: string; raw?: string };
	status: string;
	date: string;
	modified: string;
	modified_gmt?: string;
}

export interface WpAutosave {
	id: number;
	parent: number;
	author: number;
	title?: { rendered: string; raw?: string };
	content?: { rendered: string; raw?: string };
	modified?: string;
	modified_gmt?: string;
}

interface DraftContent {
	title: string;
	content: string;
}

/**
 * Prefer the current user's newer autosave, falling back to the parent draft.
 *
 * @param post          Parent draft returned by the posts endpoint.
 * @param currentUserId Current WordPress user ID.
 * @param loadAutosaves Fetches autosaves for the parent draft.
 */
export async function recoverDraftContent(
	post: WpPost,
	currentUserId: number | undefined,
	loadAutosaves: ( postId: number ) => Promise< WpAutosave[] >
): Promise< DraftContent > {
	let title = post.title.raw || post.title.rendered || '';
	let content = post.content.raw || post.content.rendered || '';

	if ( ! currentUserId ) {
		return { title, content };
	}

	try {
		const autosaves = await loadAutosaves( post.id );
		const userAutosave = Array.isArray( autosaves )
			? autosaves.find(
					( autosave ) => autosave?.author === currentUserId
			  )
			: null;

		if (
			userAutosave &&
			userAutosave.modified_gmt &&
			post.modified_gmt &&
			userAutosave.modified_gmt > post.modified_gmt
		) {
			title = userAutosave.title?.raw || title;
			content = userAutosave.content?.raw || content;
		}
	} catch {
		// Best-effort recovery: an unavailable autosave must not block the draft.
	}

	return { title, content };
}
