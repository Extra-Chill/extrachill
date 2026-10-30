/**
 * Authenticated fetch transport with token lifecycle.
 *
 * Wraps FetchTransport and adds:
 * - Bearer token injection via getAuthHeaders
 * - Proactive token refresh before expiry
 * - 401 retry with refresh lock (prevents thundering herd)
 * - Auth failure callback for logout/navigation
 *
 * Platform-specific storage is injected via callbacks:
 *   - loadTokens: read persisted tokens on init
 *   - saveTokens: persist after login/refresh
 *   - clearTokens: wipe on logout/auth failure
 *
 * Usage:
 *
 *   const transport = new AuthFetchTransport({
 *     baseUrl: 'https://extrachill.com/wp-json',
 *     refreshPath: 'extrachill/v1/auth/refresh',
 *     getDeviceId: () => SecureStore.getItemAsync('device_id'),
 *     loadTokens: () => SecureStore.getItemAsync('tokens').then(JSON.parse),
 *     saveTokens: (t) => SecureStore.setItemAsync('tokens', JSON.stringify(t)),
 *     clearTokens: () => SecureStore.deleteItemAsync('tokens'),
 *     onAuthFailure: () => router.replace('/login'),
 *   });
 *
 *   const client = new ExtraChillClient(transport);
 *   await transport.initialize(); // loads stored tokens
 */

import type { Transport, TransportRequest } from './types';
import { ApiError } from './fetch';

/** Token data stored by consumers. */
export interface StoredTokens {
  accessToken: string;
  refreshToken: string;
  /** Unix timestamp (seconds) when the access token expires. */
  accessExpiresAt: number;
}

export interface AuthFetchTransportConfig {
  /** Base URL for the REST API, e.g. "https://extrachill.com/wp-json" */
  baseUrl: string;

  /**
   * REST path for the token refresh endpoint.
   * @default 'extrachill/v1/auth/refresh'
   */
  refreshPath?: string;

  /**
   * How many milliseconds before expiry to proactively refresh.
   * @default 60000 (1 minute)
   */
  refreshBufferMs?: number;

  /**
   * Return a device ID for refresh requests.
   * Mobile apps generate a UUID and persist it; Node scripts can return a fixed string.
   */
  getDeviceId: () => string | Promise<string>;

  /**
   * Load persisted tokens on initialize().
   * Return null if no tokens are stored.
   */
  loadTokens: () => StoredTokens | null | Promise<StoredTokens | null>;

  /**
   * Persist tokens after login, register, or refresh.
   */
  saveTokens: (tokens: StoredTokens) => void | Promise<void>;

  /**
   * Clear persisted tokens on logout or auth failure.
   */
  clearTokens: () => void | Promise<void>;

  /**
   * Called when authentication cannot be recovered (refresh failed, no tokens).
   * Use this to navigate to a login screen or reset app state.
   */
  onAuthFailure?: () => void;

  /**
   * Extra headers to include on every request.
   * Useful for custom client identification headers.
   */
  defaultHeaders?: Record<string, string>;
}

export class AuthFetchTransport implements Transport {
  private config: AuthFetchTransportConfig;
  private accessToken: string | null = null;
  private refreshToken: string | null = null;
  private accessExpiresAt: number | null = null;
  private refreshPromise: Promise<boolean> | null = null;
  private initialized = false;

  constructor(config: AuthFetchTransportConfig) {
    this.config = config;
  }

  /**
   * Load stored tokens into memory. Call once at app startup.
   */
  async initialize(): Promise<void> {
    const tokens = await this.config.loadTokens();
    if (tokens) {
      this.accessToken = tokens.accessToken;
      this.refreshToken = tokens.refreshToken;
      this.accessExpiresAt = tokens.accessExpiresAt;
    }
    this.initialized = true;
  }

  /**
   * Whether initialize() has been called.
   */
  isInitialized(): boolean {
    return this.initialized;
  }

  /**
   * Whether tokens are currently loaded in memory.
   */
  hasTokens(): boolean {
    return this.accessToken !== null && this.refreshToken !== null;
  }

  /**
   * Store new tokens in memory and persist via saveTokens callback.
   * Call after login, register, or Google auth.
   */
  async setTokens(tokens: StoredTokens): Promise<void> {
    this.accessToken = tokens.accessToken;
    this.refreshToken = tokens.refreshToken;
    this.accessExpiresAt = tokens.accessExpiresAt;
    await this.config.saveTokens(tokens);
  }

  /**
   * Set or replace the auth failure callback.
   * Useful when the callback depends on React state or navigation
   * and must be wired after transport construction.
   */
  setOnAuthFailure(callback: () => void): void {
    this.config.onAuthFailure = callback;
  }

  /**
   * Clear tokens from memory and storage. Call on logout.
   */
  async clearAuth(): Promise<void> {
    this.accessToken = null;
    this.refreshToken = null;
    this.accessExpiresAt = null;
    await this.config.clearTokens();
  }

  /**
   * Execute an HTTP request with automatic auth handling.
   *
   * - Injects Bearer token if available
   * - Proactively refreshes before expiry
   * - Retries once on 401 after refreshing
   */
  async request<T>(req: TransportRequest): Promise<T> {
    // Proactive refresh before the request
    if (this.hasTokens() && this.isAccessExpiringSoon()) {
      const refreshed = await this.refreshAccessToken();
      if (!refreshed) {
        await this.handleAuthFailure();
        throw new ApiError('Session expired', 'session_expired', 401);
      }
    }

    try {
      return await this.executeRequest<T>(req);
    } catch (error) {
      // Retry once on 401 after refresh
      if (error instanceof ApiError && error.status === 401 && this.refreshToken) {
        const refreshed = await this.refreshAccessToken();
        if (!refreshed) {
          await this.handleAuthFailure();
          throw new ApiError('Session expired', 'session_expired', 401);
        }
        return await this.executeRequest<T>(req);
      }
      throw error;
    }
  }

  // ─── Private ───────────────────────────────────────────────────────────

  private async executeRequest<T>(req: TransportRequest): Promise<T> {
    const url = `${this.config.baseUrl}/${req.path}`;

    const isFormData = typeof FormData !== 'undefined' && req.body instanceof FormData;

    const headers: Record<string, string> = {
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(this.config.defaultHeaders ?? {}),
      ...(this.accessToken ? { Authorization: `Bearer ${this.accessToken}` } : {}),
      ...req.headers,
    };

    const response = await fetch(url, {
      method: req.method,
      headers,
      body: req.body
        ? isFormData
          ? (req.body as BodyInit)
          : JSON.stringify(req.body)
        : undefined,
    });

    if (response.status === 401) {
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
        response.status,
      );
    }

    if (response.status === 204) {
      return undefined as T;
    }

    return (await response.json()) as T;
  }

  private isAccessExpiringSoon(): boolean {
    if (!this.accessExpiresAt) return true;
    const bufferMs = this.config.refreshBufferMs ?? 60_000;
    return Date.now() >= this.accessExpiresAt * 1000 - bufferMs;
  }

  /**
   * Refresh the access token. Uses a lock to deduplicate concurrent refreshes.
   */
  private async refreshAccessToken(): Promise<boolean> {
    if (this.refreshPromise) {
      return this.refreshPromise;
    }

    this.refreshPromise = this.executeRefresh();

    try {
      return await this.refreshPromise;
    } finally {
      this.refreshPromise = null;
    }
  }

  private async executeRefresh(): Promise<boolean> {
    if (!this.refreshToken) return false;

    try {
      const deviceId = await this.config.getDeviceId();
      const refreshPath = this.config.refreshPath ?? 'extrachill/v1/auth/refresh';
      const url = `${this.config.baseUrl}/${refreshPath}`;

      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          refresh_token: this.refreshToken,
          device_id: deviceId,
        }),
      });

      if (!response.ok) return false;

      const data = (await response.json()) as {
        access_token: string;
        access_expires_at: string;
        refresh_token: string;
      };

      const expiresAt = Math.floor(new Date(data.access_expires_at).getTime() / 1000);

      await this.setTokens({
        accessToken: data.access_token,
        refreshToken: data.refresh_token,
        accessExpiresAt: expiresAt,
      });

      return true;
    } catch {
      return false;
    }
  }

  private async handleAuthFailure(): Promise<void> {
    await this.clearAuth();
    this.config.onAuthFailure?.();
  }
}
