import { BaseResource } from './base';
import type {
  BandNameResponse,
  RapperNameResponse,
  ImageVoteResponse,
  ImageVoteCountResponse,
  AiAdventureResponse,
  TaxonomyCountsResponse,
} from '../types';

export class BlogResource extends BaseResource {
  generateBandName(data: Record<string, unknown> = {}): Promise<BandNameResponse> {
    return this.post('extrachill/v1/blog/band-name', data);
  }

  generateRapperName(data: Record<string, unknown> = {}): Promise<RapperNameResponse> {
    return this.post('extrachill/v1/blog/rapper-name', data);
  }

  vote(data: { post_id: number; instance_id: string; image_id: string; email?: string }): Promise<ImageVoteResponse> {
    return this.post('extrachill/v1/blog/image-voting/vote', data as Record<string, unknown>);
  }

  getVoteCounts(postId: number, instanceId: string): Promise<ImageVoteCountResponse> {
    return this.get(`extrachill/v1/blog/image-voting/vote-count/${postId}/${instanceId}`);
  }

  aiAdventure(data: { action?: string; conversation?: Array<{ role: string; content: string }> }): Promise<AiAdventureResponse> {
    return this.post('extrachill/v1/blog/ai-adventure', data as Record<string, unknown>);
  }

  taxonomyCounts(): Promise<TaxonomyCountsResponse> {
    return this.get('extrachill/v1/blog/taxonomy-counts');
  }
}
