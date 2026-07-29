/**
 * Shared types for network-aware topic taxonomy correction.
 *
 * The picker is taxonomy-parameterized so artist, festival, and location share
 * one network-aware implementation.
 */

/**
 * A local term assignment. IDs are always Community-local IDs returned by the
 * network projection ability.
 */
export interface Term {
	id: number;
	name: string;
	slug: string;
}

export interface NetworkTerm {
	taxonomy: string;
	slug: string;
	name: string;
	source: string;
}

/**
 * Per-taxonomy configuration. One entry per taxonomy the picker offers.
 *
 * Search and projection use the network-owned Abilities API contracts. The
 * submitted field still contains ordinary Community-local term IDs.
 */
export interface TaxonomyConfig {
	/** Taxonomy slug, e.g. "location". */
	taxonomy: string;
	/** Visible field label, e.g. "Location". */
	label: string;
	/** Placeholder shown in the search input. */
	placeholder: string;
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
	topicId: number;
	taxonomies: TaxonomyConfig[];
}

declare global {
	interface Window {
		extrachillTermPicker?: TermPickerConfig;
	}
}
