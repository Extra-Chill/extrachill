import { BaseResource } from './base';
import type {
  SocialMediaUploadResponse,
  SocialPlatformAuthStatus,
  SocialPlatformsResponse,
  SocialPublishRequest,
  SocialPublishResponse,
  SocialStatusEntry,
} from '../types';

export class SocialsResource extends BaseResource {
  getAuthStatus(): Promise<SocialPlatformAuthStatus[]> {
    return this.get('datamachine-socials/v1/auth/status');
  }

  getPlatforms(): Promise<SocialPlatformsResponse> {
    return this.get('datamachine-socials/v1/platforms');
  }

  crossPost(data: SocialPublishRequest): Promise<SocialPublishResponse> {
    return this.post('datamachine-socials/v1/post', data as unknown as Record<string, unknown>);
  }

  uploadCroppedMedia(formData: FormData): Promise<SocialMediaUploadResponse> {
    return this.postFormData('datamachine-socials/v1/media/crop', formData);
  }

  getPostStatus(postId: number): Promise<SocialStatusEntry[]> {
    return this.get(`datamachine-socials/v1/status/${postId}`);
  }
}
