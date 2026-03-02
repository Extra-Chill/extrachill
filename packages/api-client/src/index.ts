/**
 * @extrachill/api-client
 *
 * Universal typed API client for the Extra Chill platform.
 * Works in WordPress blocks, React Native, and Node.
 *
 * Usage (mobile / Node / browser):
 *
 *   import { ExtraChillClient, FetchTransport } from '@extrachill/api-client';
 *
 *   const client = new ExtraChillClient(
 *     new FetchTransport({
 *       baseUrl: 'https://extrachill.com/wp-json',
 *       getAuthHeaders: () => ({ Authorization: `Bearer ${token}` }),
 *     })
 *   );
 *
 *   const artist = await client.artists.get(42);
 *   const events = await client.events.calendar({ venue: 'continental-club' });
 *
 * For WordPress blocks, use the separate entry point:
 *
 *   import { ExtraChillClient } from '@extrachill/api-client';
 *   import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
 *   import apiFetch from '@wordpress/api-fetch';
 *
 *   const client = new ExtraChillClient(new WpApiFetchTransport(apiFetch));
 */

// Core client
export { ExtraChillClient } from './client';

// Transports
export { FetchTransport, ApiError } from './transports/fetch';
export type { FetchTransportConfig } from './transports/fetch';
export { AuthFetchTransport } from './transports/auth-fetch';
export type { AuthFetchTransportConfig, StoredTokens } from './transports/auth-fetch';
export type { Transport, TransportRequest, TransportResponse } from './transports/types';

// All types
export * from './types';

// Resource classes (for advanced use / extension)
export { AuthResource } from './resources/auth';
export { ArtistsResource } from './resources/artists';
export { BlogResource } from './resources/blog';
export { CommunityResource } from './resources/community';
export { EventsResource } from './resources/events';
export { AnalyticsResource } from './resources/analytics';
export { MediaResource } from './resources/media';
export { ShopResource } from './resources/shop';
export { UsersResource } from './resources/users';
export { AdminResource } from './resources/admin';
export { ActivityResource } from './resources/activity';
export { SeoResource } from './resources/seo';

// Resource param types
export type { CalendarParams } from './resources/events';
export type { AnalyticsEventsParams } from './resources/analytics';
