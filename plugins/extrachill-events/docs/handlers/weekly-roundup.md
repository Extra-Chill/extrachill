# Weekly Roundup Handler

Automated Instagram carousel image generation from events grouped by date and location. Part of the Data Machine integration for comprehensive event automation.

## Overview

The weekly roundup system generates beautiful Instagram-ready carousel slides from a configurable date range and location. It queries local events, groups them by day, generates 1080x1350px images with typography and color coding, and prepares post content for publishing.

**Key Components**:
- **WeeklyRoundupHandler** - Fetch step handler for image generation
- **RoundupPublishHandler** - Publish step handler for post creation
- **SlideGenerator** - GD-based image rendering with typography
- **Configuration Classes** - Settings for both handlers

## Handler Registration

### Weekly Roundup Fetch Handler

**Handler Slug**: `weekly_roundup`  
**Step Type**: `fetch`  
**Class**: `ExtraChillEvents\Handlers\WeeklyRoundup\WeeklyRoundupHandler`

Registers as a Data Machine fetch handler that generates carousel images from events.

**Example Configuration**:
```json
{
  "week_start_day": "monday",
  "week_end_day": "sunday",
  "location_term_id": 5,
  "title": "Charleston Weekend Roundup"
}
```

### Roundup Publish Handler

**Handler Slug**: `roundup_publish`  
**Step Type**: `publish`  
**Class**: `ExtraChillEvents\Handlers\WeeklyRoundup\RoundupPublishHandler`

Registers as a Data Machine publish handler that creates WordPress posts with generated images.

**Example Configuration**:
```json
{
  "post_status": "draft"
}
```

## Configuration Fields

### Weekly Roundup Settings

| Field | Type | Required | Description | Default |
|-------|------|----------|-------------|---------|
| `week_start_day` | select | Yes | First weekday of roundup window | monday |
| `week_end_day` | select | Yes | Last weekday of roundup window | sunday |
| `location_term_id` | select | No | Filter by location taxonomy term (0 = all) | — |
| `title` | text | No | Title displayed on first slide | — |

**Weekday Options**: Monday, Tuesday, Wednesday, Thursday, Friday, Saturday, Sunday

**Location Options**: Dynamically populated from active events with location taxonomy terms

### Roundup Publish Settings

| Field | Type | Required | Description | Default |
|-------|------|----------|-------------|---------|
| `post_status` | select | Yes | WordPress post status | draft |

**Status Options**: Draft, Published, Pending Review

## Data Flow

### Weekly Roundup Execution

1. **Configuration Resolution**
   - Parse week_start_day and week_end_day
   - Resolve location_term_id filter (0 = all locations)
   - Extract title for first slide

2. **Date Range Calculation**
   - Calculate next occurrence of week_start_day
   - Calculate next occurrence of week_end_day after start day
   - Handle wraparound (e.g., Fri-Wed becomes Fri-Wed next week)
   - Format as YYYY-MM-DD

3. **Event Querying**
   - Use `Calendar_Query::build_query_args()` with date range
   - Apply location filter via `tax_filters['location']`
   - Exclude past events (`show_past: false`)
   - Build paginated event results

4. **Event Grouping**
   - Group events by date using `Calendar_Query::group_events_by_date()`
   - Returns array keyed by date with `date_obj` and `events` array

5. **Image Generation**
   - Pass day groups to `SlideGenerator::generate_slides()`
   - Automatically distribute days across multiple slides based on height
   - Generate PNG images via GD library
   - Store in Data Machine files repository

6. **Engine Data Storage**
   - Store image paths, event summary, metadata
   - Output: `image_file_paths`, `event_summary`, `location_name`, `date_range`, `total_events`, `total_slides`

### Roundup Publish Execution

1. **Image Retrieval**
   - Extract `image_file_paths` from engine data
   - Verify all images exist

2. **Media Library Upload**
   - Use `media_handle_sideload()` to upload each image
   - Name: `roundup-slide-{number}.png`
   - Description: `{title} - Slide {number}`

3. **Post Creation**
   - Title: `{location_name}: {date_range}`
   - Content: Instagram caption + images + event summary
   - Author: Current user (or ID 1 if not authenticated)
   - Status: Configured via settings

4. **Post Thumbnail**
   - Set first uploaded image as featured image

5. **Engine Data Update**
   - Store `post_id` and `published_url` for downstream steps

## Image Generation

### SlideGenerator Class

GD-based image renderer generating 1080x1350px carousel slides.

**Constants**:
```php
// Dimensions
WIDTH = 1080px
HEIGHT = 1350px

// Colors
Background: #1a1a1a (dark)
Text: #e5e5e5 (light)
Muted: #b0b0b0 (medium gray)
Title Underline: #53940b (accent green)

// Weekday Colors (for day headers)
Sunday: #ff6b6b (red)
Monday: #4ecdc4 (teal)
Tuesday: #45b7d1 (blue)
Wednesday: #96ceb4 (green)
Thursday: #feca57 (yellow)
Friday: #d63384 (pink)
Saturday: #54a0ff (light blue)
```

**Typography**:
- **Header Font** (Wilco Loft Sans): Day headers, titles
- **Body Font** (Helvetica): Event titles, metadata
- **Fallback**: DejaVuSans if theme fonts unavailable

**Font Resolution**:
1. Check theme directory for fonts
   - Header: `WilcoLoftSans-Treble.ttf` or `Lobster_Two/LobsterTwo-Regular.ttf`
   - Body: `helvetica.ttf`
2. Fall back to system DejaVuSans

**Font Sizes**:
- Title: 36pt
- Day Header: 28pt
- Event Title: 22pt
- Event Meta: 18pt
- Line Height: 1.4x font size

**Layout**:
- Padding: 60px on all sides
- Events distributed across multiple slides
- Height calculated based on content
- Automatic pagination if doesn't fit single slide

### Slide Distribution Algorithm

Events are automatically distributed across multiple slides:

1. Calculate available height after padding and title (if first slide)
2. Iterate through day groups
3. Calculate height needed for each day group
4. If day group fits current slide, add it
5. If doesn't fit:
   - Save current slide
   - Start new slide with that day group
6. Handle last slide

Each slide can contain multiple days of events, up to the 1080x1350px canvas.

## Output Data

### Engine Data From Weekly Roundup

```php
[
    'image_file_paths' => [
        '/path/to/roundup-slide-1.png',
        '/path/to/roundup-slide-2.png',
        // ... more slides
    ],
    'event_summary' => "Monday, Jan 15:\n- Event 1 @ Venue (7:00 PM)\n- Event 2 @ Venue (9:00 PM)\n\n...",
    'location_name' => 'Charleston',
    'date_range' => 'Jan 15 - Jan 21, 2024',
    'date_start' => '2024-01-15',
    'date_end' => '2024-01-21',
    'total_events' => 12,
    'total_slides' => 2,
    'roundup_context' => [
        'location' => 'Charleston',
        'start_date' => '2024-01-15',
        'end_date' => '2024-01-21',
        'day_count' => 7,
        'event_count' => 12,
    ]
]
```

### Engine Data From Roundup Publish

```php
[
    'post_id' => 1234,
    'post_title' => 'Charleston: Jan 15 - Jan 21, 2024',
    'post_url' => 'https://example.com/2024/01/charleston-jan-15-jan-21-2024/',
    'image_count' => 2,
    'attachment_ids' => [567, 568],
    'published_url' => 'https://example.com/2024/01/charleston-jan-15-jan-21-2024/'
]
```

## Integration with Data Machine

The weekly roundup handlers are automatically registered when:
1. Data Machine plugin is active
2. `DataMachine\Core\Steps\Fetch\Handlers\FetchHandler` class exists
3. Plugin's `init_datamachine_handlers()` is called on 'init' hook (priority 20)

**Flow Integration Example**:
```
fetch: weekly_roundup
  → generates images
  → stores image_file_paths in engine data

publish: roundup_publish
  → retrieves image_file_paths
  → uploads to media library
  → creates post with images and caption
```

Downstream steps can access all engine data for additional processing (e.g., social media posting).

## Error Handling

**Weekly Roundup Handler**:
- Logs if week_start_day or week_end_day missing
- Returns empty array if no events found for date range
- Logs event count and day count for debugging

**RoundupPublishHandler**:
- Returns error if engine data unavailable
- Returns error if no images in engine data
- Logs warning for individual image upload failures
- Returns error if no images successfully uploaded
- Logs post creation failure with details

**SlideGenerator**:
- Returns null if image creation fails
- Returns null if fonts unavailable (falls back to system fonts)
- Returns null if image file can't be saved

## Logging

Both handlers use Data Machine's logging system. Logs include:

**Weekly Roundup**:
- Handler startup with config
- Event query results (count, days)
- Slide generation completion with counts
- Any errors during execution

**Roundup Publish**:
- Handler startup with image count
- Individual image upload warnings
- Post creation success with URL
- Post thumbnail assignment

Access logs via Data Machine's job/pipeline UI.

## Examples

### Basic Weekly Roundup (Monday-Sunday)

**Fetch Config**:
```json
{
  "week_start_day": "monday",
  "week_end_day": "sunday",
  "title": "This Week in Charleston"
}
```

**Publish Config**:
```json
{
  "post_status": "draft"
}
```

Generates carousel images for Monday-Sunday window, all locations.

### Weekend Roundup (Friday-Sunday)

**Fetch Config**:
```json
{
  "week_start_day": "friday",
  "week_end_day": "sunday",
  "location_term_id": 3,
  "title": "Charleston Weekend"
}
```

Generates carousel for Friday-Sunday, filtered to location ID 3.

### Multi-Location Weekly Posts

Run multiple roundup flows with different location_term_id values to create separate posts for each location.

## Requirements

- **Data Machine** plugin active (for FetchHandler/PublishHandler base classes)
- **data-machine-events** plugin (for Calendar_Query, events)
- **Location taxonomy** must exist and be assigned to data_machine_events post type
- **GD library** (PHP extension) for image generation
- **Fonts** (optional): Theme fonts in `/assets/fonts/` (falls back to system fonts)
- **Media library access** for uploading images

## Performance Considerations

- **Image Generation**: GD operations are CPU-intensive; consider running on schedule
- **Database Queries**: Uses single WP_Query with proper pagination
- **Memory**: GD images held in memory per slide; watch for memory limits with many events
- **Storage**: PNG images stored in Data Machine's file repository

Monitor Data Machine pipeline jobs for:
- Execution time
- Memory usage
- Generated image counts
- Upload success rates
