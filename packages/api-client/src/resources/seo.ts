import { BaseResource } from './base';
import type {
  SeoAuditStatus,
  SeoConfig,
} from '../types';

export class SeoResource extends BaseResource {
  getConfig(): Promise<SeoConfig> {
    return this.get('extrachill/v1/seo/config');
  }

  updateConfig(data: Partial<SeoConfig>): Promise<SeoConfig> {
    return this.put('extrachill/v1/seo/config', data as Record<string, unknown>);
  }

  startAudit(): Promise<{ success: boolean }> {
    return this.post('extrachill/v1/seo/audit');
  }

  continueAudit(): Promise<{ success: boolean }> {
    return this.post('extrachill/v1/seo/audit/continue');
  }

  getAuditStatus(): Promise<SeoAuditStatus> {
    return this.get('extrachill/v1/seo/audit/status');
  }

  getAuditDetails(params: Record<string, string | number | boolean | undefined> = {}): Promise<Record<string, unknown>> {
    const query = this.buildQuery(params);
    return this.get(`extrachill/v1/seo/audit/details${query}`);
  }
}
