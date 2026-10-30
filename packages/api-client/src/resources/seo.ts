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

  startAudit(mode?: string): Promise<{ success: boolean }> {
    return this.post('extrachill/v1/seo/audit', mode ? { mode } : undefined);
  }

  continueAudit(): Promise<{ success: boolean }> {
    return this.post('extrachill/v1/seo/audit/continue');
  }

  getAuditStatus(): Promise<SeoAuditStatus> {
    return this.get('extrachill/v1/seo/audit/status');
  }

  getAuditDetails(category: string, page = 1, perPage = 50): Promise<Record<string, unknown>> {
    const query = this.buildQuery({ category, page, per_page: perPage });
    return this.get(`extrachill/v1/seo/audit/details${query}`);
  }

  exportAuditDetails(category: string): Promise<Record<string, unknown>> {
    const query = this.buildQuery({ category, export: true });
    return this.get(`extrachill/v1/seo/audit/details${query}`);
  }
}
