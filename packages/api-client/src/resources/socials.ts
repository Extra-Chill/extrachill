import { BaseResource } from './base';
import type {
  SocialJobStatusResponse,
  SocialMediaUploadResponse,
  SocialPlatformAuthStatus,
  SocialPlatformsResponse,
  SocialPublishJobResponse,
  SocialPublishRequest,
  SocialStatusEntry,
} from '../types';

export class SocialsResource extends BaseResource {
  getAuthStatus(): Promise<SocialPlatformAuthStatus[]> {
    return this.get('datamachine/v1/socials/auth/status');
  }

  getPlatforms(): Promise<SocialPlatformsResponse> {
    return this.get('datamachine/v1/socials/platforms');
  }

  /**
   * Schedule a social cross-post job.
   *
   * Returns immediately with a job ID. The actual publishing happens
   * asynchronously via Action Scheduler. Poll {@link getJobStatus} to
   * check completion and retrieve per-platform results.
   */
  crossPost(data: SocialPublishRequest): Promise<SocialPublishJobResponse> {
    return this.post('datamachine/v1/socials/post', data as unknown as Record<string, unknown>);
  }

  /**
   * Get the status and results of a social cross-post job.
   *
   * @param jobId - The `job_id` returned by {@link crossPost}.
   * @returns Job record including `engine_data.results` when completed.
   */
  getJobStatus(jobId: number): Promise<SocialJobStatusResponse> {
    return this.get(`datamachine/v1/socials/jobs/${jobId}`);
  }

  uploadCroppedMedia(formData: FormData): Promise<SocialMediaUploadResponse> {
    return this.postFormData('datamachine/v1/socials/media/crop', formData);
  }

  getPostStatus(postId: number): Promise<SocialStatusEntry[]> {
    return this.get(`datamachine/v1/socials/status/${postId}`);
  }
}
