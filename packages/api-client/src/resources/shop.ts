import { BaseResource } from './base';
import type {
  ShopProduct,
  ShopProductInput,
  ShopListProductsParams,
  ShopListOrdersParams,
  ShopOrder,
  ShopOrdersResponse,
  ShopRefundResponse,
  ShopSellerRef,
  StripeConnectStatus,
  ShippingAddress,
  ShippingAddressResponse,
  ShippingAddressUpdateResponse,
  ShippingLabelLookup,
  ShippingLabelPurchase,
  TaxonomyCountsResponse,
} from '../types';

/**
 * Seller-scoped shop routes. Every seller (artist, venue or promoter) is sent
 * as `seller_type` + `seller_id`; product item routes follow the product's
 * own seller.
 */
export class ShopResource extends BaseResource {
  private sellerParams(seller: ShopSellerRef): { seller_type: string; seller_id: number } {
    return { seller_type: seller.type, seller_id: seller.id };
  }

  // ─── Products ────────────────────────────────────────────────────────

  listProducts(seller: ShopSellerRef, params: ShopListProductsParams = {}): Promise<ShopProduct[]> {
    const query = this.buildQuery({ ...this.sellerParams(seller), ...params });
    return this.get(`extrachill/v1/shop/products${query}`);
  }

  getProduct(id: number): Promise<ShopProduct> {
    return this.get(`extrachill/v1/shop/products/${id}`);
  }

  createProduct(seller: ShopSellerRef, data: ShopProductInput): Promise<ShopProduct> {
    return this.post('extrachill/v1/shop/products', { ...data, ...this.sellerParams(seller) } as Record<string, unknown>);
  }

  /** Update a product; pass `seller` to move it to another seller you manage. */
  updateProduct(id: number, data: ShopProductInput, seller?: ShopSellerRef): Promise<ShopProduct> {
    const body = seller ? { ...data, ...this.sellerParams(seller) } : data;
    return this.put(`extrachill/v1/shop/products/${id}`, body as Record<string, unknown>);
  }

  deleteProduct(id: number): Promise<void> {
    return this.del(`extrachill/v1/shop/products/${id}`);
  }

  uploadProductImages(productId: number, formData: FormData): Promise<ShopProduct> {
    return this.postFormData(`extrachill/v1/shop/products/${productId}/images`, formData);
  }

  deleteProductImage(productId: number, attachmentId: number): Promise<ShopProduct> {
    return this.del(`extrachill/v1/shop/products/${productId}/images/${attachmentId}`);
  }

  // ─── Orders ──────────────────────────────────────────────────────────

  listOrders(seller: ShopSellerRef, params: ShopListOrdersParams = {}): Promise<ShopOrdersResponse> {
    const query = this.buildQuery({ ...this.sellerParams(seller), ...params });
    return this.get(`extrachill/v1/shop/orders${query}`);
  }

  updateOrderStatus(
    orderId: number,
    seller: ShopSellerRef,
    data: { status: 'completed'; tracking_number?: string }
  ): Promise<ShopOrder> {
    return this.put(`extrachill/v1/shop/orders/${orderId}/status`, { ...data, ...this.sellerParams(seller) });
  }

  refundOrder(orderId: number, seller: ShopSellerRef, reason?: string): Promise<ShopRefundResponse> {
    return this.post(`extrachill/v1/shop/orders/${orderId}/refund`, {
      ...this.sellerParams(seller),
      ...(reason ? { reason } : {}),
    });
  }

  // ─── Stripe Connect ──────────────────────────────────────────────────

  getStripeConnectStatus(seller: ShopSellerRef): Promise<StripeConnectStatus> {
    return this.get(`extrachill/v1/shop/stripe-connect/status${this.buildQuery(this.sellerParams(seller))}`);
  }

  createStripeOnboardingLink(seller: ShopSellerRef): Promise<{ success: boolean; url: string }> {
    return this.post('extrachill/v1/shop/stripe-connect/onboarding-link', this.sellerParams(seller));
  }

  createStripeDashboardLink(seller: ShopSellerRef): Promise<{ success: boolean; url: string }> {
    return this.post('extrachill/v1/shop/stripe-connect/dashboard-link', this.sellerParams(seller));
  }

  // ─── Shipping ────────────────────────────────────────────────────────

  getShippingAddress(seller: ShopSellerRef): Promise<ShippingAddressResponse> {
    return this.get(`extrachill/v1/shop/shipping-address${this.buildQuery(this.sellerParams(seller))}`);
  }

  updateShippingAddress(seller: ShopSellerRef, address: ShippingAddress): Promise<ShippingAddressUpdateResponse> {
    return this.put('extrachill/v1/shop/shipping-address', { ...address, ...this.sellerParams(seller) } as Record<string, unknown>);
  }

  purchaseShippingLabel(orderId: number, seller: ShopSellerRef): Promise<ShippingLabelPurchase> {
    return this.post('extrachill/v1/shop/shipping-labels', { order_id: orderId, ...this.sellerParams(seller) });
  }

  getShippingLabel(orderId: number, seller: ShopSellerRef): Promise<ShippingLabelLookup> {
    return this.get(`extrachill/v1/shop/shipping-labels/${orderId}${this.buildQuery(this.sellerParams(seller))}`);
  }

  // ─── Taxonomy ────────────────────────────────────────────────────────

  taxonomyCounts(): Promise<TaxonomyCountsResponse> {
    return this.get('extrachill/v1/shop/taxonomy-counts');
  }
}
