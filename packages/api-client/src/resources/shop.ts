import { BaseResource } from './base';
import type {
  ShopProduct,
  ShopOrder,
  StripeConnectStatus,
  ShippingAddress,
  ShippingLabel,
  TaxonomyCountsResponse,
} from '../types';

export class ShopResource extends BaseResource {
  // ─── Products ────────────────────────────────────────────────────────

  listProducts(): Promise<ShopProduct[]> {
    return this.get('extrachill/v1/shop/products');
  }

  getProduct(id: number): Promise<ShopProduct> {
    return this.get(`extrachill/v1/shop/products/${id}`);
  }

  createProduct(data: Partial<ShopProduct>): Promise<ShopProduct> {
    return this.post('extrachill/v1/shop/products', data as Record<string, unknown>);
  }

  updateProduct(id: number, data: Partial<ShopProduct>): Promise<ShopProduct> {
    return this.put(`extrachill/v1/shop/products/${id}`, data as Record<string, unknown>);
  }

  deleteProduct(id: number): Promise<void> {
    return this.del(`extrachill/v1/shop/products/${id}`);
  }

  uploadProductImages(productId: number, formData: FormData): Promise<Array<{ id: number; url: string }>> {
    return this.postFormData(`extrachill/v1/shop/products/${productId}/images`, formData);
  }

  deleteProductImage(productId: number, attachmentId: number): Promise<void> {
    return this.del(`extrachill/v1/shop/products/${productId}/images/${attachmentId}`);
  }

  // ─── Orders ──────────────────────────────────────────────────────────

  listOrders(params: { artist_id: number; status?: string; page?: number } = { artist_id: 0 }): Promise<ShopOrder[]> {
    const query = this.buildQuery(params as Record<string, string | number | boolean | undefined>);
    return this.get(`extrachill/v1/shop/orders${query}`);
  }

  updateOrderStatus(orderId: number, data: { artist_id: number; status: string; tracking_number?: string }): Promise<ShopOrder> {
    return this.put(`extrachill/v1/shop/orders/${orderId}/status`, data as Record<string, unknown>);
  }

  refundOrder(orderId: number, artistId: number): Promise<{ success: boolean }> {
    return this.post(`extrachill/v1/shop/orders/${orderId}/refund`, { artist_id: artistId });
  }

  // ─── Stripe Connect ──────────────────────────────────────────────────

  getStripeConnectStatus(artistId: number): Promise<StripeConnectStatus> {
    return this.get(`extrachill/v1/shop/stripe-connect/status?artist_id=${artistId}`);
  }

  createStripeOnboardingLink(artistId: number): Promise<{ url: string }> {
    return this.post('extrachill/v1/shop/stripe-connect/onboarding-link', { artist_id: artistId });
  }

  createStripeDashboardLink(artistId: number): Promise<{ url: string }> {
    return this.post('extrachill/v1/shop/stripe-connect/dashboard-link', { artist_id: artistId });
  }

  // ─── Shipping ────────────────────────────────────────────────────────

  getShippingAddress(artistId: number): Promise<ShippingAddress> {
    return this.get(`extrachill/v1/shop/shipping-address?artist_id=${artistId}`);
  }

  updateShippingAddress(artistId: number, address: ShippingAddress): Promise<ShippingAddress> {
    return this.put('extrachill/v1/shop/shipping-address', { artist_id: artistId, ...address } as Record<string, unknown>);
  }

  purchaseShippingLabel(orderId: number, artistId: number): Promise<ShippingLabel> {
    return this.post('extrachill/v1/shop/shipping-labels', { order_id: orderId, artist_id: artistId });
  }

  getShippingLabel(orderId: number, artistId: number): Promise<ShippingLabel> {
    return this.get(`extrachill/v1/shop/shipping-labels/${orderId}?artist_id=${artistId}`);
  }

  // ─── Taxonomy ────────────────────────────────────────────────────────

  taxonomyCounts(): Promise<TaxonomyCountsResponse> {
    return this.get('extrachill/v1/shop/taxonomy-counts');
  }
}
