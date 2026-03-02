import { BaseResource } from './base';
import type { MediaUploadResponse } from '../types';

export class MediaResource extends BaseResource {
  /**
   * Upload a file. Works with both browser File objects and React Native file refs.
   * The caller is responsible for constructing the FormData with platform-appropriate file handling.
   */
  upload(formData: FormData): Promise<MediaUploadResponse> {
    return this.postFormData('extrachill/v1/media', formData);
  }

  /**
   * Helper to build FormData for a media upload (browser/RN compatible).
   */
  buildUploadForm(context: string, targetId: number | null, file: Blob | File): FormData {
    const formData = new FormData();
    formData.append('context', context);
    if (targetId) {
      formData.append('target_id', String(targetId));
    }
    formData.append('file', file);
    return formData;
  }

  delete(context: string, targetId: number): Promise<void> {
    return this.del('extrachill/v1/media', { context, target_id: targetId });
  }
}
