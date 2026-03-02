import { BaseResource } from './base';
import type {
  CalendarResponse,
  CalendarFilters,
  Venue,
  GeoSearchResult,
  EventSubmissionRequest,
  UpcomingCountsResponse,
} from '../types';

export interface CalendarParams {
  page?: number;
  per_page?: number;
  venue?: string;
  promoter?: string;
  location?: string;
  scope?: string;
  lat?: number;
  lng?: number;
  radius?: number;
}

export class EventsResource extends BaseResource {
  /**
   * Get calendar events grouped by date.
   * Namespace: datamachine/v1 (registered by data-machine-events)
   */
  calendar(params: CalendarParams = {}): Promise<CalendarResponse> {
    const query = this.buildQuery(params as Record<string, string | number | boolean | undefined>);
    return this.get(`datamachine/v1/events/calendar${query}`);
  }

  /**
   * Get available filter options (venues, promoters).
   */
  filters(): Promise<CalendarFilters> {
    return this.get('datamachine/v1/events/filters');
  }

  /**
   * List venues, optionally with geo bounds.
   */
  venues(params: {
    location?: string;
    sw_lat?: number;
    sw_lng?: number;
    ne_lat?: number;
    ne_lng?: number;
  } = {}): Promise<Venue[]> {
    const query = this.buildQuery(params as Record<string, string | number | boolean | undefined>);
    return this.get(`datamachine/v1/events/venues${query}`);
  }

  /**
   * Get a single venue by ID.
   */
  venue(id: number): Promise<Venue> {
    return this.get(`datamachine/v1/events/venues/${id}`);
  }

  /**
   * Check for duplicate venues.
   */
  checkDuplicateVenue(params: { name: string; city?: string }): Promise<Venue[]> {
    const query = this.buildQuery(params);
    return this.get(`datamachine/v1/events/venues/check-duplicate${query}`);
  }

  /**
   * Geocode search (Nominatim proxy).
   */
  geocodeSearch(query: string): Promise<GeoSearchResult[]> {
    return this.get(`datamachine/v1/events/geocode/search?q=${encodeURIComponent(query)}`);
  }

  /**
   * Submit a community event.
   * Namespace: extrachill/v1 (registered by extrachill-events)
   */
  submitEvent(data: EventSubmissionRequest): Promise<{ success: boolean; id: number }> {
    return this.post('extrachill/v1/event-submissions', data as unknown as Record<string, unknown>);
  }

  /**
   * Get upcoming event counts per location.
   */
  upcomingCounts(): Promise<UpcomingCountsResponse> {
    return this.get('extrachill/v1/events/upcoming-counts');
  }
}
