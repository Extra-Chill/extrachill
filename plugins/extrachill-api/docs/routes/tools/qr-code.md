# QR Code Generator

REST API endpoint for generating high-resolution print-ready QR codes for any URL.

## Endpoint

### Generate QR Code

**Endpoint**: `POST /wp-json/extrachill/v1/tools/qr-code`

**Purpose**: Generate high-resolution print-ready QR codes for any URL using the Endroid QR Code library.

**Parameters**:
- `url` (string, required) - The URL to encode in the QR code
- `size` (integer, optional) - QR code size in pixels (default: 1000, min: 100, max: 2000)

**Response** (HTTP 200):
```json
{
  "image_url": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...",
  "url": "https://example.com",
  "size": 1000
}
```

**Permission**: Public (no authentication required)

**File**: `inc/routes/tools/qr-code.php`

## Error Responses

**Missing URL** (HTTP 400):
```json
{
  "code": "missing_url",
  "message": "URL is required.",
  "data": { "status": 400 }
}
```

**Invalid URL** (HTTP 400):
```json
{
  "code": "invalid_url",
  "message": "Please provide a valid URL.",
  "data": { "status": 400 }
}
```

**Size Too Small** (HTTP 400):
```json
{
  "code": "size_too_small",
  "message": "Size must be at least 100 pixels.",
  "data": { "status": 400 }
}
```

**Size Too Large** (HTTP 400):
```json
{
  "code": "size_too_large",
  "message": "Size cannot exceed 2000 pixels.",
  "data": { "status": 400 }
}
```

**Library Missing** (HTTP 500):
```json
{
  "code": "library_missing",
  "message": "QR code generation library is not available.",
  "data": { "status": 500 }
}
```

**Generation Failed** (HTTP 500):
```json
{
  "code": "generation_failed",
  "message": "Failed to generate QR code: [error details]",
  "data": { "status": 500 }
}
```

## Features

- **High Resolution**: Generates QR codes up to 2000x2000 pixels for print quality
- **Error Correction**: Uses High error correction level for reliable scanning
- **Margin**: Includes 40px margin around the QR code for better readability
- **UTF-8 Support**: Supports international characters in URLs
- **Base64 Output**: Returns QR code as data URI for immediate use

## Dependencies

- Endroid QR Code library (Composer dependency)
- PHP GD extension for image processing

## Integration

Used by admin tools and marketing features to generate QR codes for sharing links, event pages, and promotional materials.