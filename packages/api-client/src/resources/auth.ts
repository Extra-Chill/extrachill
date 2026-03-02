import { BaseResource } from './base';
import type {
  LoginRequest,
  LoginResponse,
  RegisterRequest,
  RegisterResponse,
  RefreshRequest,
  RefreshResponse,
  GoogleLoginRequest,
  GoogleLoginResponse,
  BrowserHandoffResponse,
  AuthMeResponse,
  OAuthConfigResponse,
} from '../types';

export class AuthResource extends BaseResource {
  login(data: LoginRequest): Promise<LoginResponse> {
    return this.post('extrachill/v1/auth/login', data as unknown as Record<string, unknown>);
  }

  register(data: RegisterRequest): Promise<RegisterResponse> {
    return this.post('extrachill/v1/auth/register', data as unknown as Record<string, unknown>);
  }

  refresh(data: RefreshRequest): Promise<RefreshResponse> {
    return this.post('extrachill/v1/auth/refresh', data as unknown as Record<string, unknown>);
  }

  loginWithGoogle(data: GoogleLoginRequest): Promise<GoogleLoginResponse> {
    return this.post('extrachill/v1/auth/google', data as unknown as Record<string, unknown>);
  }

  logout(deviceId: string): Promise<void> {
    return this.post('extrachill/v1/auth/logout', { device_id: deviceId });
  }

  me(): Promise<AuthMeResponse> {
    return this.get('extrachill/v1/auth/me');
  }

  createBrowserHandoff(redirectUrl: string): Promise<BrowserHandoffResponse> {
    return this.post('extrachill/v1/auth/browser-handoff', { redirect_url: redirectUrl });
  }

  getOAuthConfig(): Promise<OAuthConfigResponse> {
    return this.get('extrachill/v1/config/oauth');
  }
}
