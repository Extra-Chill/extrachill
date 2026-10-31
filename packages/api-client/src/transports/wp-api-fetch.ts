/**
 * WordPress api-fetch transport.
 *
 * Wraps @wordpress/api-fetch for use inside WordPress blocks.
 * Nonce handling, root URL, and middleware are all managed by WP core.
 *
 * This transport is in a separate entry point (wordpress.ts) so the main
 * bundle has zero WordPress dependencies. Only import this in WP blocks.
 */

import type { Transport, TransportRequest } from './types';
import { ApiError } from './fetch';

type ApiFetchOptions = {
  path: string;
  method: string;
  data?: Record<string, unknown>;
  body?: FormData;
  headers?: Record<string, string>;
};

type ApiFetchFn = <T>(options: ApiFetchOptions) => Promise<T>;

export class WpApiFetchTransport implements Transport {
  private apiFetch: ApiFetchFn;

  constructor(apiFetch: ApiFetchFn) {
    this.apiFetch = apiFetch;
  }

  async request<T>(req: TransportRequest): Promise<T> {
    const isFormData = typeof FormData !== 'undefined' && req.body instanceof FormData;

    try {
      const result = await this.apiFetch<T>({
        path: req.path,
        method: req.method,
        ...(isFormData
          ? { body: req.body as FormData }
          : req.body
            ? { data: req.body as Record<string, unknown> }
            : {}),
        headers: req.headers,
      });
      return result;
    } catch (error: unknown) {
      if (error && typeof error === 'object' && 'code' in error) {
        const wpError = error as { code: string; message: string; data?: { status: number } };
        throw new ApiError(
          wpError.message,
          wpError.code,
          wpError.data?.status || 500
        );
      }
      throw error;
    }
  }
}
