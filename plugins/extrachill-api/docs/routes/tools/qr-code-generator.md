# QR Code Generator Endpoint

## Route
`POST /wp-json/extrachill/v1/tools/qr-code`

## Purpose
Creates high-resolution PNG QR codes for any URL so admins can generate print-ready assets directly inside ExtraChill tools without leaving WordPress.

## Request Fields
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `url` | string | Yes | Must be a valid URL; sanitized with `esc_url_raw` and validated via `filter_var`. |
| `size` | integer | No | Defaults to `1000`. Accepted range `100`–`2000` pixels. | 

## Processing Flow
1. REST schema enforces argument presence, type, and custom validators:
   - `extrachill_api_validate_qr_url()` ensures a valid URL.
   - `extrachill_api_validate_qr_size()` clamps sizes to the supported range.
2. Checks the Endroid QR Code library is loaded via Composer. Missing classes return `500 library_missing`.
3. Instantiates `Endroid\QrCode\QrCode` with the sanitized URL, UTF-8 encoding, high error correction, requested size, and a 40px margin.
4. Renders the code using `Endroid\QrCode\Writer\PngWriter` and returns a data URI string.

## Response Example
```json
{
  "success": true,
  "image_url": "data:image/png;base64,iVBORw0K...",
  "url": "https://extrachill.com",
  "size": 1000
}
```
- `image_url` is a base64-encoded PNG that can be dropped into `<img>` tags or downloaded by the browser.

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_url` / `invalid_url` | 400 | URL absent or fails validation. |
| `size_too_small` / `size_too_large` | 400 | `size` outside the allowed range. |
| `library_missing` | 500 | Endroid classes not autoloaded (Composer not installed). |
| `generation_failed` | 500 | Unexpected exception while rendering the PNG. |

## Integration Guidance
- Send JSON payloads; no authentication is required, but rate-limit client calls to avoid unnecessary image generation.
- Store or cache the returned `image_url` client-side if users will immediately download or preview the code.
- For large print assets, use the maximum allowed size (`2000`) to preserve fidelity before scaling in layout tools.
