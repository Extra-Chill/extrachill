/**
 * Transport layer abstraction.
 *
 * A Transport is the only platform-specific piece in the client.
 * It knows how to send HTTP requests and handle auth headers.
 * Everything above it is pure TypeScript — platform agnostic.
 */

export interface TransportRequest {
  path: string;
  method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH';
  body?: Record<string, unknown> | FormData;
  headers?: Record<string, string>;
}

export interface TransportResponse<T = unknown> {
  data: T;
  status: number;
  headers: Record<string, string>;
}

export interface Transport {
  request<T>(req: TransportRequest): Promise<T>;
}
