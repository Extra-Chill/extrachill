import { BaseResource } from './base';
import type {
  AnalyticsEventsResponse,
  AnalyticsSummaryResponse,
  AnalyticsMetaResponse,
} from '../types';

export interface AnalyticsEventsParams {
  event_type?: string;
  page?: number;
  per_page?: number;
  date_from?: string;
  date_to?: string;
}

export class AnalyticsResource extends BaseResource {
  getEvents(params: AnalyticsEventsParams = {}): Promise<AnalyticsEventsResponse> {
    const query = this.buildQuery(params as Record<string, string | number | boolean | undefined>);
    return this.get(`extrachill/v1/analytics/events${query}`);
  }

  getSummary(params: { period?: string } = {}): Promise<AnalyticsSummaryResponse> {
    const query = this.buildQuery(params);
    return this.get(`extrachill/v1/analytics/events/summary${query}`);
  }

  getMeta(): Promise<AnalyticsMetaResponse> {
    return this.get('extrachill/v1/analytics/meta');
  }

  trackView(data: { url: string; referrer?: string }): Promise<void> {
    return this.post('extrachill/v1/analytics/view', data as Record<string, unknown>);
  }

  trackClick(data: { url: string; element?: string }): Promise<void> {
    return this.post('extrachill/v1/analytics/click', data as Record<string, unknown>);
  }

  getLinkPageAnalytics(params: { artist_id?: number; date_range?: number } = {}): Promise<Record<string, unknown>> {
    const query = this.buildQuery(params as Record<string, string | number | boolean | undefined>);
    return this.get(`extrachill/v1/analytics/link-page${query}`);
  }
}
