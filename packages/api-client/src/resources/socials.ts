import { BaseResource } from './base';
import type {
  SocialJobRecord,
  SocialJobStatusResponse,
  SocialJobWaitOptions,
  SocialMediaUploadResponse,
  SocialPlatformAuthStatus,
  SocialPlatformsResponse,
  SocialPublishJobResponse,
  SocialPublishRequest,
  SocialStatusEntry,
} from '../types';

const DEFAULT_JOB_POLL_INTERVAL_MS = 3000;
const DEFAULT_JOB_TIMEOUT_MS = 120000;

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

  /**
   * Wait for a social cross-post job to reach a terminal state.
   *
   * This is transport-agnostic client-side orchestration over the Socials job
   * endpoint. It works in WordPress blocks, mobile apps, and Node consumers.
   *
   * @param jobId - The `job_id` returned by {@link crossPost}.
   * @param options - Polling interval, timeout, abort signal, and status hook.
   * @returns The completed job record, including `engine_data.results`.
   * @throws When the job fails, times out, cannot be found, or is aborted.
   */
  async waitForCrossPostJob(jobId: number, options: SocialJobWaitOptions = {}): Promise<SocialJobRecord> {
    const intervalMs = options.intervalMs ?? DEFAULT_JOB_POLL_INTERVAL_MS;
    const timeoutMs = options.timeoutMs ?? DEFAULT_JOB_TIMEOUT_MS;
    const startedAt = Date.now();

    while (true) {
      if (options.signal?.aborted) {
        throw new Error('Social job wait aborted');
      }

      const response = await this.getJobStatus(jobId);
      const job = response.jobs?.[0];

      if (!job) {
        throw new Error(`Social job ${jobId} was not found`);
      }

      options.onStatus?.(job);

      if (job.status === 'completed') {
        return job;
      }

      if (typeof job.status === 'string' && job.status.startsWith('failed')) {
        const reason = job.engine_data?.error || job.status.replace(/^failed:?\s*/, '') || `Social job ${jobId} failed`;
        throw new Error(reason);
      }

      if (Date.now() - startedAt >= timeoutMs) {
        throw new Error(`Timed out waiting for social job ${jobId}`);
      }

      await this.delay(intervalMs, options.signal);
    }
  }

  uploadCroppedMedia(formData: FormData): Promise<SocialMediaUploadResponse> {
    return this.postFormData('datamachine/v1/socials/media/crop', formData);
  }

  getPostStatus(postId: number): Promise<SocialStatusEntry[]> {
    return this.get(`datamachine/v1/socials/status/${postId}`);
  }

  private delay(ms: number, signal?: AbortSignal): Promise<void> {
    return new Promise((resolve, reject) => {
      if (signal?.aborted) {
        reject(new Error('Social job wait aborted'));
        return;
      }

      const timeout = setTimeout(() => {
        signal?.removeEventListener('abort', onAbort);
        resolve();
      }, ms);

      const onAbort = () => {
        clearTimeout(timeout);
        reject(new Error('Social job wait aborted'));
      };

      signal?.addEventListener('abort', onAbort, { once: true });
    });
  }
}
