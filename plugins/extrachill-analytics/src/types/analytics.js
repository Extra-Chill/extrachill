/**
 * Analytics type definitions.
 *
 * Relocated from @extrachill/api-client during M8 migration.
 * These are JSDoc-only types for editor tooling — no runtime cost.
 */

/**
 * @typedef {Object} AnalyticsEvent
 * @property {number}                  id         Event row ID.
 * @property {string}                  event_type Event type slug.
 * @property {string}                  source_url URL the event was recorded on.
 * @property {Record<string, unknown>} event_data Event payload.
 * @property {string}                  created_at Creation timestamp (UTC).
 * @property {number}                  [user_id]  Associated user ID.
 * @property {number}                  [blog_id]  Blog the event was recorded on.
 */

/**
 * @typedef {Object} AnalyticsEventsParams
 * @property {string} [event_type] Filter by event type.
 * @property {number} [blog_id]    Filter by blog ID.
 * @property {string} [date_from]  Lower date bound (Y-m-d).
 * @property {string} [date_to]    Upper date bound (Y-m-d).
 * @property {string} [search]     Free-text search.
 * @property {number} [limit]      Maximum rows to return.
 * @property {number} [offset]     Rows to skip.
 * @property {number} [page]       Page number.
 * @property {number} [per_page]   Rows per page.
 */

/**
 * @typedef {Object} AnalyticsEventsResponse
 * @property {AnalyticsEvent[]} events   Events on this page.
 * @property {number}           total    Total matching events.
 * @property {number}           page     Current page number.
 * @property {number}           per_page Rows per page.
 */

/**
 * @typedef {Object} AnalyticsSummaryResponse
 * @property {Record<string, number>} summary Counts keyed by dimension.
 * @property {string}                 period  Human-readable period label.
 */

/**
 * @typedef {Object} AnalyticsMetaResponse
 * @property {string[]} event_types  Known event types.
 * @property {number}   total_events Total stored events.
 */

export {};
