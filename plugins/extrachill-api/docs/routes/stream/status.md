# Stream Status

Check the current status of streaming functionality. Provides information about stream availability, configuration, and operational state.

## Endpoints

### Get Stream Status

**Endpoint**: `GET /wp-json/extrachill/v1/stream/status`

**Purpose**: Retrieve current streaming status and configuration information.

**Permission**: Requires logged-in user with stream access permissions

**Parameters**: None

**Request Example**:
```
GET /wp-json/extrachill/v1/stream/status
Authorization: Bearer your-jwt-token
```

**Response** (HTTP 200):
```json
{
  "status": "online",
  "stream_key": "live_123456789",
  "server_url": "rtmp://stream.example.com/live",
  "viewer_count": 42,
  "is_live": true,
  "last_streamed": "2024-01-15T20:30:00Z",
  "configuration": {
    "max_bitrate": "3000k",
    "supported_resolutions": ["720p", "1080p"],
    "recording_enabled": true
  }
}
```

**Response - Stream Offline** (HTTP 200):
```json
{
  "status": "offline",
  "is_live": false,
  "last_streamed": "2024-01-14T18:45:00Z",
  "configuration": {
    "max_bitrate": "3000k",
    "supported_resolutions": ["720p", "1080p"],
    "recording_enabled": true
  }
}
```

**Error Responses**:
- `403` - Insufficient permissions to access stream status
- `503` - Stream service unavailable

**Implementation Details**:
- Requires extrachill-stream plugin
- Calls `ec_stream_rest_get_status()` function
- Permission checking via `ec_stream_rest_permissions_check()`
- Returns comprehensive stream state information

**File**: `inc/routes/stream/status.php`

---

## Usage Examples

### Check Stream Status

```javascript
async function getStreamStatus() {
  try {
    const response = await fetch('/wp-json/extrachill/v1/stream/status', {
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('access_token')}`
      }
    });

    const status = await response.json();

    if (response.ok) {
      updateStreamUI(status);
    } else {
      console.error('Failed to get stream status:', status.message);
    }
  } catch (error) {
    console.error('Network error:', error);
  }
}

function updateStreamUI(status) {
  const statusIndicator = document.getElementById('stream-status');

  if (status.is_live) {
    statusIndicator.textContent = `🔴 LIVE - ${status.viewer_count} viewers`;
    statusIndicator.className = 'status-live';
  } else {
    statusIndicator.textContent = '⚫ Offline';
    statusIndicator.className = 'status-offline';
  }
}
```

### Stream Dashboard

```javascript
// Poll for status updates
setInterval(async () => {
  const status = await getStreamStatus();

  if (status.is_live) {
    document.getElementById('stream-key').textContent = status.stream_key;
    document.getElementById('server-url').textContent = status.server_url;
    document.getElementById('viewer-count').textContent = status.viewer_count;
  }
}, 30000); // Update every 30 seconds
```

---

## Usage Notes

**Status Values**:
- `online`: Stream service is operational
- `offline`: Stream service is not currently active
- `maintenance`: Stream service is under maintenance

**Live Indicators**:
- `is_live`: Boolean indicating if currently streaming
- `viewer_count`: Current number of viewers (when live)
- `last_streamed`: Timestamp of last streaming session

**Configuration**:
- Bitrate limits and supported resolutions
- Recording capabilities
- Stream server information

**Permissions**:
- Requires appropriate user permissions
- Stream owners can see their own stream status
- Admins can see all stream statuses

**Caching**:
- Status information may be cached briefly
- Real-time updates for live streaming state
- Configuration data changes less frequently

**Related Endpoints**:
- Stream management endpoints in extrachill-stream plugin</content>
<parameter name="filePath">docs/routes/stream/status.md