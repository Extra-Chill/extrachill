/**
 * dirtyStorage
 *
 * Per-artist unsaved-edit buffer backed by sessionStorage. Lets the link-page
 * editor rehydrate in-progress work after a tab close / reopen without
 * relying on beforeunload guards.
 *
 * Storage shape (per artist):
 *
 *   {
 *     version: 1,
 *     savedAt: '<ISO>',
 *     artist:   { name, profile_image_id, profile_image_url } | undefined,
 *     links:    { links, settings, bio, css_vars, raw_font_values,
 *                 background_image_id, background_image_url } | undefined,
 *     socials:  [ { id, type, url, icon_class }, ... ] | undefined,
 *     dirtySections: [ 'artist' | 'links' | 'socials', ... ]
 *   }
 *
 * All reads/writes are wrapped in try/catch and degrade to null / no-op so a
 * disabled / full / private-mode sessionStorage never crashes the editor.
 */

const KEY_PREFIX = 'ec-link-page-editor:dirty:';
const SCHEMA_VERSION = 1;

const buildKey = ( artistId ) => `${ KEY_PREFIX }${ artistId }`;

const getStorage = () => {
	try {
		if ( typeof window === 'undefined' || ! window.sessionStorage ) {
			return null;
		}
		return window.sessionStorage;
	} catch ( e ) {
		return null;
	}
};

/**
 * Read the stored dirty buffer for an artist.
 *
 * @param {number|string} artistId
 * @return {object|null} Parsed buffer or null when missing / malformed / version-mismatched.
 */
export function readDirty( artistId ) {
	if ( ! artistId ) {
		return null;
	}
	const storage = getStorage();
	if ( ! storage ) {
		return null;
	}

	try {
		const raw = storage.getItem( buildKey( artistId ) );
		if ( ! raw ) {
			return null;
		}
		const parsed = JSON.parse( raw );
		if ( ! parsed || parsed.version !== SCHEMA_VERSION ) {
			return null;
		}
		return parsed;
	} catch ( e ) {
		return null;
	}
}

/**
 * Read-modify-write merge of a partial snapshot into the stored buffer.
 *
 * `partial` is shaped like a subset of the storage shape:
 *
 *   writeDirty( artistId, { section: 'links', links: { bio: 'new bio' } } );
 *   writeDirty( artistId, { section: 'socials', socials: [ ... ] } );
 *
 * When `section` is provided it is added to `dirtySections`.
 *
 * @param {number|string} artistId
 * @param {object}        partial  Partial buffer to merge.
 * @return {void}
 */
export function writeDirty( artistId, partial ) {
	if ( ! artistId || ! partial ) {
		return;
	}
	const storage = getStorage();
	if ( ! storage ) {
		return;
	}

	try {
		const existing = readDirty( artistId ) || {
			version: SCHEMA_VERSION,
			dirtySections: [],
		};

		const next = {
			...existing,
			version: SCHEMA_VERSION,
			savedAt: new Date().toISOString(),
		};

		const { section, ...sectionData } = partial;

		if ( sectionData.artist !== undefined ) {
			next.artist = { ...( existing.artist || {} ), ...sectionData.artist };
		}
		if ( sectionData.links !== undefined ) {
			next.links = { ...( existing.links || {} ), ...sectionData.links };
		}
		if ( sectionData.socials !== undefined ) {
			next.socials = sectionData.socials;
		}

		if ( section ) {
			const dirty = new Set( existing.dirtySections || [] );
			dirty.add( section );
			next.dirtySections = Array.from( dirty );
		}

		storage.setItem( buildKey( artistId ), JSON.stringify( next ) );
	} catch ( e ) {
		// Quota exceeded, storage disabled, JSON failure — silently drop.
	}
}

/**
 * Remove the stored dirty buffer for an artist.
 *
 * @param {number|string} artistId
 * @return {void}
 */
export function clearDirty( artistId ) {
	if ( ! artistId ) {
		return;
	}
	const storage = getStorage();
	if ( ! storage ) {
		return;
	}

	try {
		storage.removeItem( buildKey( artistId ) );
	} catch ( e ) {
		// no-op
	}
}

/**
 * Quick existence check without parsing.
 *
 * @param {number|string} artistId
 * @return {boolean}
 */
export function hasDirty( artistId ) {
	return readDirty( artistId ) !== null;
}
