/**
 * Base resource — every resource group extends this.
 * Provides typed helper methods wrapping the transport.
 */

import type { Transport } from '../transports/types';

export abstract class BaseResource {
  protected transport: Transport;

  constructor(transport: Transport) {
    this.transport = transport;
  }

  protected get<T>(path: string): Promise<T> {
    return this.transport.request<T>({ path, method: 'GET' });
  }

  protected post<T>(path: string, body?: Record<string, unknown>): Promise<T> {
    return this.transport.request<T>({ path, method: 'POST', body });
  }

  protected put<T>(path: string, body?: Record<string, unknown>): Promise<T> {
    return this.transport.request<T>({ path, method: 'PUT', body });
  }

  protected del<T>(path: string, body?: Record<string, unknown>): Promise<T> {
    return this.transport.request<T>({ path, method: 'DELETE', body });
  }

  protected postFormData<T>(path: string, formData: FormData): Promise<T> {
    return this.transport.request<T>({ path, method: 'POST', body: formData as unknown as Record<string, unknown> });
  }

  protected buildQuery(params: Record<string, string | number | boolean | undefined>): string {
    const entries = Object.entries(params).filter(
      (entry): entry is [string, string | number | boolean] => entry[1] !== undefined
    );
    if (entries.length === 0) return '';
    const search = new URLSearchParams();
    for (const [key, value] of entries) {
      search.append(key, String(value));
    }
    return `?${search.toString()}`;
  }
}
