/**
 * Shared types for the composer term-picker.
 *
 * The picker is taxonomy-PARAMETERIZED: location is the only taxonomy wired
 * today, but artist / festival / venue can be enabled later purely by adding
 * entries to the localized `taxonomies` config — no new component per taxonomy.
 */

/**
 * A single curated network term as returned by the WP REST taxonomy endpoint
 * (e.g. GET /wp/v2/location?search=...). Users may only SELECT these; the
 * picker never creates new terms.
 */
export interface Term {
	id: number;
	name: string;
	parent: number;
}

/**
 * Per-taxonomy configuration. One entry per taxonomy the picker offers.
 *
 * `restBase` is the taxonomy's REST base (location -> /wp/v2/location). The
 * picker is read-only against that endpoint: it searches existing terms, it
 * never POSTs new ones.
 */
export interface TaxonomyConfig {
	/** Taxonomy slug, e.g. "location". */
	taxonomy: string;
	/** REST base used to build the search URL, e.g. "location". */
	restBase: string;
	/** Visible field label, e.g. "Location". */
	label: string;
	/** Placeholder shown in the search input. */
	placeholder: string;
	/** Hierarchical taxonomies show parent context in suggestions. */
	hierarchical: boolean;
	/**
	 * Name of the POST field the bbPress save handler reads. Values are
	 * submitted as `${field}[]` so the server can assign multiple terms.
	 */
	field: string;
	/** Pre-selected terms (topic edit flow) used to seed the chips. */
	selected: Term[];
}

/**
 * Localized config injected via wp_localize_script as `extrachillTermPicker`.
 */
export interface TermPickerConfig {
	restUrl: string;
	restNonce: string;
	taxonomies: TaxonomyConfig[];
}

declare global {
	interface Window {
		extrachillTermPicker?: TermPickerConfig;
	}
}
