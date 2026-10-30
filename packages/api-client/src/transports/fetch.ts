/**
 * Native fetch transport.
 *
 * Works in:
 * - React Native (built-in fetch)
 * - Modern browsers (without @wordpress/api-fetch)
 * - Node 18+ (built-in fetch)
 *
 * Auth is handled via a configurable header function.
 * The mobile app passes Bearer tokens, Node scripts pass Basic auth, etc.
 */

import type { Transport, TransportRequest } from './types';

export interface FetchTransportConfig {
  /** Base URL for the REST API, e.g. "https://extrachill.com/wp-json" */
  baseUrl: string;

  /**
   * Return auth headers for each request.
   * Return empty object for public endpoints.
   *
   * Examples:
   *   Bearer:  () => ({ Authorization: `Bearer ${token}` })
   *   Basic:   () => ({ Authorization: `Basic ${btoa('user:pass')}` })
   *   Nonce:   () => ({ 'X-WP-Nonce': nonce })
   */
  getAuthHeaders?: () => Record<string, string> | Promise<Record<string, string>>;

  /**
   * Called when a request returns 401.
   * Use this to trigger token refresh or logout.
   */
  onUnauthorized?: () => void | Promise<void>;
}

export class FetchTransport implements Transport {
  private config: FetchTransportConfig;

  constructor(config: FetchTransportConfig) {
    this.config = config;
  }

  async request<T>(req: TransportRequest): Promise<T> {
    const url = `${this.config.baseUrl}/${req.path}`;

    const authHeaders = this.config.getAuthHeaders
      ? await this.config.getAuthHeaders()
      : {};

    const isFormData = typeof FormData !== 'undefined' && req.body instanceof FormData;

    const headers: Record<string, string> = {
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...authHeaders,
      ...req.headers,
    };

    const response = await fetch(url, {
      method: req.method,
      headers,
      body: req.body
        ? isFormData
          ? req.body as BodyInit
          : JSON.stringify(req.body)
        : undefined,
    });

    if (response.status === 401) {
      await this.config.onUnauthorized?.();
      throw new ApiError('Unauthorized', 'unauthorized', 401);
    }

    if (!response.ok) {
      let errorData: { code?: string; message?: string } = {};
      try {
        errorData = await response.json();
      } catch {
        // Response wasn't JSON
      }
      throw new ApiError(
        errorData.message || `Request failed with status ${response.status}`,
        errorData.code || 'request_failed',
        response.status
      );
    }

    // Handle 204 No Content
    if (response.status === 204) {
      return undefined as T;
    }

    return await response.json() as T;
  }
}

export class ApiError extends Error {
  code: string;
  status: number;

  constructor(message: string, code: string, status: number) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}
