import { BaseResource } from './base';
import type {
  ActivityResponse,
  HydratedObject,
} from '../types';

export class ActivityResource extends BaseResource {
  getFeed(cursor?: string, limit = 20): Promise<ActivityResponse> {
    const params: Record<string, string | number | boolean | undefined> = { cursor, limit };
    const query = this.buildQuery(params);
    return this.get(`extrachill/v1/activity${query}`);
  }

  getObject(objectType: string, blogId: number, id: string): Promise<HydratedObject> {
    const query = this.buildQuery({ object_type: objectType, blog_id: blogId, id });
    return this.get(`extrachill/v1/object${query}`);
  }
}
