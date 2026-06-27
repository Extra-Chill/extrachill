/**
 * Typed response shapes for the Network tab analytics REST routes.
 *
 * These mirror the ability outputs wrapped by extrachill-api's
 * `extrachill/v1/analytics/*` routes (extrachill-analytics abilities) and the
 * Data Machine Business GA4 route. They are intentionally partial — the Studio
 * Network tab reads only the fields it renders, and treats the rest as opaque.
 *
 * The analytics layer uses a `not_instrumented` coverage-gap convention: a
 * dimension that cannot currently be measured returns `{ measured: false,
 * not_instrumented: true, reason }` rather than a misleading zero. The
 * `NotInstrumented` type captures that marker so chart components can render a
 * "not instrumented" state instead of a false zero.
 */

/** Coverage-gap marker — a dimension that cannot currently be measured. */
export interface NotInstrumented {
	measured: false;
	not_instrumented: true;
	reason: string;
}

/**
 * Narrow a measured-or-gap union to the coverage-gap marker.
 * @param value
 */
export const isNotInstrumented = ( value: unknown ): value is NotInstrumented =>
	typeof value === 'object' &&
	value !== null &&
	( value as { not_instrumented?: unknown } ).not_instrumented === true;

/* ── GET /extrachill/v1/analytics/summary ── */

export interface AnalyticsSummaryEventType {
	event_type: string;
	count: number;
	daily_avg: number;
}

export interface AnalyticsSummaryResponse {
	event_types: AnalyticsSummaryEventType[];
	total: number;
	days: number;
	period: string;
	since: string;
	as_of: string;
}

/* ── GET /extrachill/v1/analytics/surface-growth ── */

export interface SurfaceSupplyMeasured {
	measured: true;
	new_in_window: number;
	prior_total: number;
	per_week: number;
	pct_per_week: number | null;
	unit: string;
	definition: string;
}

export type SurfaceSupply = SurfaceSupplyMeasured | NotInstrumented;

export interface SurfaceDemandMeasured {
	measured: true;
	basis: string;
	organic_share: number;
	current_sessions: number;
	previous_sessions: number;
	current_organic: number;
	previous_organic: number;
	slope_pct: number | null;
	pct_per_week: number | null;
	is_new_traffic: boolean;
	definition: string;
}

export type SurfaceDemand = SurfaceDemandMeasured | NotInstrumented;

export interface SurfaceGrowthSurface {
	surface: string;
	label: string;
	blog_id: number;
	host: string;
	supply: SurfaceSupply;
	demand: SurfaceDemand;
	growth_pct_per_week: number | null;
}

export interface SurfaceGrowthRankedEntry {
	surface: string;
	label: string;
	pct_per_week: number;
}

export interface SurfaceGrowthRanking {
	ranked: SurfaceGrowthRankedEntry[];
	unranked: Array< { surface: string; reason: string } >;
}

export interface SurfaceGrowthResponse {
	surfaces: SurfaceGrowthSurface[];
	supply_ranking: SurfaceGrowthRanking;
	demand_ranking: SurfaceGrowthRanking;
	fastest_growing: {
		surface: string | null;
		label?: string;
		axis?: string;
		pct_per_week?: number;
		reason?: string;
	};
	weeks: number;
	days: number;
	ga_available: boolean;
	as_of: string;
	note: string;
}

/* ── GET /extrachill/v1/analytics/retention ── */

export interface RetentionCohort {
	cohort_week: string;
	cohort_size: number;
	returned_w1: number;
	returned_w2: number;
	retention_w1: number;
	retention_w2: number;
}

export interface RetentionResponse {
	return_rate: {
		total_visitors: number;
		returning_visitors: number;
		rate: number;
		definition: string;
	};
	cohort_retention: {
		cohorts: RetentionCohort[];
		weeks: number;
		definition: string;
	};
	cross_site_return: {
		total_visitors: number;
		cross_site_visitors: number;
		rate: number;
		definition: string;
	};
	session_depth: {
		avg_pageviews_per_visitor_day: number;
		max_pageviews_per_visitor_day: number;
		definition: string;
	};
	days: number;
	blog_id: number;
	period: string;
	as_of: string;
	note: string;
}

/* ── GET /extrachill/v1/analytics/conversion-map ── */

export interface ConversionRow {
	entry_sessions: number;
	reached_any: number;
	reached_any_rate: number;
	same_session: {
		events: number;
		community: number;
		artist: number;
		any: number;
	};
	return: {
		events: number;
		community: number;
		artist: number;
		any: number;
	};
	returned_rate: number;
	reached_any_same_count: number;
	reached_any_return_count: number;
	returned_count: number;
	/** Present on per-article rows. */
	post_id?: number;
	title?: string;
	slug?: string;
	/** Present on per-category rows. */
	term_id?: number;
	category?: string;
}

export interface ConversionMapResponse {
	overall: ConversionRow;
	by_article: ConversionRow[];
	by_category: ConversionRow[];
	entry_blog_id: number;
	days: number;
	period: string;
	as_of: string;
	note: string;
}

/* ── POST /datamachine/v1/analytics/ga (action: date_stats) ── */

export interface GaDateStatsRow {
	date?: string;
	sessions?: number;
	[ key: string ]: unknown;
}

export interface GaDateStatsResponse {
	success: boolean;
	results?: GaDateStatsRow[];
	error?: string;
	[ key: string ]: unknown;
}
