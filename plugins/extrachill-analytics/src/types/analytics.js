/**
 * Analytics type definitions.
 *
 * Relocated from @extrachill/api-client during M8 migration.
 * These are JSDoc-only types for editor tooling — no runtime cost.
 */

/**
 * @typedef {Object} AnalyticsEvent
 * @property {number}                   id
 * @property {string}                   event_type
 * @property {string}                   source_url
 * @property {Record<string, unknown>}  event_data
 * @property {string}                   created_at
 * @property {number}                   [user_id]
 * @property {number}                   [blog_id]
 */

/**
 * @typedef {Object} AnalyticsEventsParams
 * @property {string} [event_type]
 * @property {number} [blog_id]
 * @property {string} [date_from]
 * @property {string} [date_to]
 * @property {string} [search]
 * @property {number} [limit]
 * @property {number} [offset]
 * @property {number} [page]
 * @property {number} [per_page]
 */

/**
 * @typedef {Object} AnalyticsEventsResponse
 * @property {AnalyticsEvent[]} events
 * @property {number}           total
 * @property {number}           page
 * @property {number}           per_page
 */

/**
 * @typedef {Object} AnalyticsSummaryResponse
 * @property {Record<string, number>} summary
 * @property {string}                 period
 */

/**
 * @typedef {Object} AnalyticsMetaResponse
 * @property {string[]} event_types
 * @property {number}   total_events
 */

export {};
