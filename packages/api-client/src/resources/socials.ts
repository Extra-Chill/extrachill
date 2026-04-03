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
    return this.get('datamachine/v1/socials/auth/status');
  }

  getPlatforms(): Promise<SocialPlatformsResponse> {
    return this.get('datamachine/v1/socials/platforms');
  }

  crossPost(data: SocialPublishRequest): Promise<SocialPublishResponse> {
    return this.post('datamachine/v1/socials/post', data as unknown as Record<string, unknown>);
  }

  uploadCroppedMedia(formData: FormData): Promise<SocialMediaUploadResponse> {
    return this.postFormData('datamachine/v1/socials/media/crop', formData);
  }

  getPostStatus(postId: number): Promise<SocialStatusEntry[]> {
    return this.get(`datamachine/v1/socials/status/${postId}`);
  }
}
