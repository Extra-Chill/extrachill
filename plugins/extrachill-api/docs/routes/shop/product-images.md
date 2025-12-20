# Shop Product Images

Manage product images with support for featured image and gallery uploads. Supports up to 5 images per product.

## Endpoints

### Upload Product Images

**Endpoint**: `POST /wp-json/extrachill/v1/shop/products/{id}/images`

**Purpose**: Upload one or more images to a product. First image becomes featured image; remaining become gallery.

**Permission**: Requires logged-in user who can manage the artist product owner

**Parameters**:
- `id` (integer, required, in URL) - Product ID
- `files` (file array, required) - Image files to upload (JPG, PNG, GIF, WebP; max 5MB each)

**Supported File Types**:
- JPEG (image/jpeg, .jpg/.jpeg)
- PNG (image/png, .png)
- GIF (image/gif, .gif)
- WebP (image/webp, .webp)

**File Size Limits**:
- Maximum 5MB per file
- Maximum 5 images per product

**Request Example** (using FormData):
```javascript
const formData = new FormData();
formData.append('files', fileInput.files[0]);
formData.append('files', fileInput.files[1]);

fetch(`/wp-json/extrachill/v1/shop/products/456/images`, {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ' + token
  },
  body: formData
});
```

**Response** (HTTP 200):
```json
{
  "id": 456,
  "name": "Album CD",
  "price": "14.99",
  "stock": 50,
  "image_id": 789,
  "image_url": "https://shop.extrachill.com/wp-content/uploads/2025/01/album-cd.jpg",
  "gallery": [
    {
      "id": 790,
      "url": "https://shop.extrachill.com/wp-content/uploads/2025/01/album-back.jpg"
    },
    {
      "id": 791,
      "url": "https://shop.extrachill.com/wp-content/uploads/2025/01/album-inside.jpg"
    }
  ],
  "image_count": 3
}
```

**Error Responses**:
- `400` - No files uploaded, invalid file type, file too large, or image limit reached
- `401` - User not logged in
- `403` - User cannot manage the product's artist
- `404` - Product not found
- `500` - Shop site not configured or file upload service unavailable

### Delete Product Image

**Endpoint**: `DELETE /wp-json/extrachill/v1/shop/products/{id}/images/{attachment_id}`

**Purpose**: Remove an image from a product's featured or gallery.

**Permission**: Requires logged-in user who can manage the artist product owner

**Parameters**:
- `id` (integer, required, in URL) - Product ID
- `attachment_id` (integer, required, in URL) - Attachment ID to delete

**Response** (HTTP 200):
```json
{
  "id": 456,
  "name": "Album CD",
  "price": "14.99",
  "stock": 50,
  "image_id": 790,
  "image_url": "https://shop.extrachill.com/wp-content/uploads/2025/01/album-back.jpg",
  "gallery": [
    {
      "id": 791,
      "url": "https://shop.extrachill.com/wp-content/uploads/2025/01/album-inside.jpg"
    }
  ],
  "image_count": 2
}
```

**Error Responses**:
- `400` - Cannot delete last image (products must have at least one image)
- `401` - User not logged in
- `403` - User cannot manage the product's artist
- `404` - Product or image not found
- `500` - File deletion service unavailable

**Implementation Details**:
- Products stored on shop site (Blog ID 3)
- First image in list is featured image (WooCommerce thumbnail)
- Remaining images stored as gallery in `_product_image_gallery` meta
- Images ordered as comma-separated attachment IDs
- First upload becomes featured if product has no featured image
- Subsequent uploads added to gallery
- Delete operations reorder remaining images automatically
- Both featured and gallery images can be deleted if product has multiple images

**File**: `inc/routes/shop/product-images.php`

---

## Usage Examples

### Upload Single Product Image (JavaScript)

```javascript
async function uploadProductImage(productId, file) {
  const formData = new FormData();
  formData.append('files', file);

  const response = await fetch(
    `/wp-json/extrachill/v1/shop/products/${productId}/images`,
    {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + localStorage.getItem('access_token')
      },
      body: formData
    }
  );

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message);
  }

  const product = await response.json();
  console.log(`Image uploaded. Product now has ${product.image_count} images`);
  return product;
}
```

### Upload Multiple Images

```javascript
async function uploadProductGallery(productId, files) {
  const formData = new FormData();
  
  for (const file of files) {
    formData.append('files', file);
  }

  const response = await fetch(
    `/wp-json/extrachill/v1/shop/products/${productId}/images`,
    {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + localStorage.getItem('access_token')
      },
      body: formData
    }
  );

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message);
  }

  const product = await response.json();
  console.log(`Uploaded ${files.length} images. Total: ${product.image_count}`);
  return product;
}
```

### React Image Upload Component

```javascript
import React, { useState } from 'react';

function ProductImageUpload({ productId, artistId, onUploadComplete }) {
  const [files, setFiles] = useState([]);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState(null);

  const handleFileSelect = (e) => {
    setFiles(Array.from(e.target.files));
    setError(null);
  };

  const handleUpload = async () => {
    setUploading(true);
    setError(null);

    try {
      const formData = new FormData();
      files.forEach(file => formData.append('files', file));

      const response = await fetch(
        `/wp-json/extrachill/v1/shop/products/${productId}/images`,
        {
          method: 'POST',
          headers: {
            'Authorization': 'Bearer ' + localStorage.getItem('access_token')
          },
          body: formData
        }
      );

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.message);
      }

      const product = await response.json();
      setFiles([]);
      onUploadComplete(product);
    } catch (err) {
      setError(err.message);
    } finally {
      setUploading(false);
    }
  };

  return (
    <div className="product-image-upload">
      <input 
        type="file" 
        multiple 
        accept="image/*"
        onChange={handleFileSelect}
        disabled={uploading}
      />
      
      {files.length > 0 && (
        <button onClick={handleUpload} disabled={uploading}>
          {uploading ? 'Uploading...' : `Upload ${files.length} Image(s)`}
        </button>
      )}

      {error && <div className="error">{error}</div>}
    </div>
  );
}

export default ProductImageUpload;
```

### Delete Product Image

```javascript
async function deleteProductImage(productId, attachmentId) {
  const response = await fetch(
    `/wp-json/extrachill/v1/shop/products/${productId}/images/${attachmentId}`,
    {
      method: 'DELETE',
      headers: {
        'Authorization': 'Bearer ' + localStorage.getItem('access_token')
      }
    }
  );

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message);
  }

  const product = await response.json();
  console.log(`Image deleted. Product now has ${product.image_count} images`);
  return product;
}
```

### Product Gallery Editor

```javascript
function ProductGalleryEditor({ product, onUpdate }) {
  const handleDeleteImage = async (attachmentId) => {
    if (!confirm('Delete this image?')) return;

    try {
      const updated = await deleteProductImage(product.id, attachmentId);
      onUpdate(updated);
    } catch (error) {
      alert('Failed to delete image: ' + error.message);
    }
  };

  return (
    <div className="gallery-editor">
      <div className="featured-image">
        <h3>Featured Image</h3>
        <img src={product.image_url} alt={product.name} />
        <button onClick={() => handleDeleteImage(product.image_id)}>
          Delete Featured Image
        </button>
      </div>

      {product.gallery.length > 0 && (
        <div className="gallery">
          <h3>Gallery ({product.gallery.length})</h3>
          {product.gallery.map(img => (
            <div key={img.id} className="gallery-item">
              <img src={img.url} alt="Gallery item" />
              <button onClick={() => handleDeleteImage(img.id)}>
                Delete
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
```

---

## Usage Notes

**Image Ordering**:
- First uploaded image becomes featured image (product thumbnail)
- Additional images added to gallery in upload order
- Gallery images stored as comma-separated IDs
- Deletion automatically reorders remaining images

**Image Limits**:
- Maximum 5 images per product
- Upload will fail if product already has 5 images
- Partial uploads not possible (upload fails if any file invalid)

**File Validation**:
- Only JPG, PNG, GIF, WebP accepted
- File type validated both by extension and MIME type
- Each file limited to 5MB
- Invalid files rejected with specific error message

**Storage**:
- Images stored on shop site (Blog ID 3)
- WordPress media library attachment handling
- Images tied to product via attachment parent relationship
- Automatic thumbnail generation on upload

**Related Endpoints**:
- [Shop Products](products.md) - Product CRUD operations
- [Media Upload](../media/upload.md) - General media upload endpoint
- [Shop Orders](orders.md) - Order management
