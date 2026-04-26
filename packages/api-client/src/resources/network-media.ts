import { BaseResource } from './base';
import type { NetworkMediaItem, NetworkMediaListResponse } from '../types';

export interface NetworkMediaListParams {
  media_type?: 'image' | 'video' | 'audio';
  search?: string;
  per_page?: number;
  page?: number;
}

/**
 * Network media library access.
 *
 * Phase 1 of the network-wide unified media library
 * (extrachill-multisite#2) — currently scoped to the main editorial site
 * (blog 1). Use this from any subsite to browse and upload to the main
 * media library.
 */
export class NetworkMediaResource extends BaseResource {
  /** List attachments from the main media library. */
  list(params: NetworkMediaListParams = {}): Promise<NetworkMediaListResponse> {
    const query = this.buildQuery({
      media_type: params.media_type,
      search: params.search,
      per_page: params.per_page,
      page: params.page,
    });
    return this.get(`extrachill/v1/network-media${query}`);
  }

  /** Upload a file into the main media library. */
  upload(formData: FormData): Promise<NetworkMediaItem> {
    return this.postFormData('extrachill/v1/network-media', formData);
  }

  /**
   * Helper to build a FormData payload for upload.
   * Browser/RN compatible — caller hands in a File or Blob.
   */
  buildUploadForm(file: Blob | File, opts: { title?: string; alt?: string } = {}): FormData {
    const formData = new FormData();
    formData.append('file', file);
    if (opts.title) formData.append('title', opts.title);
    if (opts.alt) formData.append('alt', opts.alt);
    return formData;
  }
}
