import { BaseResource } from './base';
import type {
  AnalyticsEventsResponse,
  AnalyticsSummaryResponse,
  AnalyticsMetaResponse,
} from '../types';

export interface AnalyticsEventsParams {
  event_type?: string;
  blog_id?: number;
  date_from?: string;
  date_to?: string;
  search?: string;
  limit?: number;
  offset?: number;
  page?: number;
  per_page?: number;
}

export class AnalyticsResource extends BaseResource {
  getEvents(params: AnalyticsEventsParams = {}): Promise<AnalyticsEventsResponse> {
    const query = this.buildQuery(params as Record<string, string | number | boolean | undefined>);
    return this.get(`extrachill/v1/analytics/events${query}`);
  }

  getSummary(eventType: string, days = 30, blogId?: number): Promise<AnalyticsSummaryResponse> {
    const query = this.buildQuery({ event_type: eventType, days, blog_id: blogId });
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
