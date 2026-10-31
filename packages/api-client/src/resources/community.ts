import { BaseResource } from './base';
import type {
  CommunityDraft,
  UpvoteResponse,
  TaxonomyCountsResponse,
} from '../types';

export class CommunityResource extends BaseResource {
  getDrafts(): Promise<CommunityDraft[]> {
    return this.get('extrachill/v1/community/drafts');
  }

  saveDraft(data: { title: string; content: string; forum_id: number }): Promise<CommunityDraft> {
    return this.post('extrachill/v1/community/drafts', data as Record<string, unknown>);
  }

  deleteDraft(draftId: number): Promise<void> {
    return this.del('extrachill/v1/community/drafts', { id: draftId });
  }

  upvote(data: { post_id: number }): Promise<UpvoteResponse> {
    return this.post('extrachill/v1/community/upvote', data as Record<string, unknown>);
  }

  taxonomyCounts(): Promise<TaxonomyCountsResponse> {
    return this.get('extrachill/v1/community/taxonomy-counts');
  }
}
