# Rank System

Point-based ranking system with 30 tiers tracking user engagement and contributions across platform.

## Rank Tiers

### Complete Rank Progression
Tiers from lowest to highest with point requirements:

| Points Required | Rank Title |
|----------------|------------|
| 0 - 14 | Dew |
| 15 - 34 | Droplet |
| 35 - 68 | Puddle |
| 69 - 102 | Crisp Air |
| 103 - 154 | First Frost |
| 155 - 231 | Overnight Freeze |
| 232 - 348 | Ice Cube |
| 349 - 522 | Ice Tray |
| 523 - 784 | Bag of Ice |
| 785 - 1,177 | Ice Maker |
| 1,178 - 1,767 | Cooler |
| 1,768 - 2,651 | Fridge |
| 2,652 - 3,977 | Freezer |
| 3,978 - 5,967 | Ice Machine |
| 5,968 - 8,951 | Walk-In Freezer |
| 8,952 - 13,427 | Frozen Foods Isle |
| 13,428 - 20,142 | Ice Rink |
| 20,143 - 30,213 | Flurry |
| 30,214 - 45,321 | Snowstorm |
| 45,322 - 67,982 | Ski Resort |
| 67,983 - 101,973 | Blizzard |
| 101,974 - 152,960 | Glacier |
| 152,961 - 229,441 | Antarctica |
| 229,442 - 344,163 | Ice Age |
| 344,164 - 516,245 | Upper Atmosphere |
| 516,246+ | Frozen Deep Space |

## Point Calculation

### Point Sources

#### bbPress Activity
- **Topics Created**: 2 points per topic
- **Replies Posted**: 2 points per reply

#### Community Engagement
- **Upvotes Received**: 0.5 points per upvote on your topics/replies

#### Main Site Contributions
- **Published Posts**: 10 points per post on extrachill.com main site

#### Future Sources
- **Followers**: Reserved for future implementation (currently 0 points)

### Total Points Formula
```
Total Points = (Topics × 2) + (Replies × 2) + (Upvotes × 0.5) + (Main Site Posts × 10)
```

## Point Updates

### Recalculation Timing
Points recalculate hourly via WordPress Cron for bbPress activity changes.

### Real-Time Updates
Upvote points update immediately when votes are added or removed.

### Caching Strategy
Point totals cached for 1 hour in WordPress transients for performance.

### Recalculation Queue
User added to recalculation queue when creating topics or replies. Queue processes hourly.

## Rank Display

### Reply Author Details
Rank and points display in author sidebar for all forum replies.

### User Profiles
Current rank and total points visible in user profile headers.

### Leaderboard
Users sortable by `extrachill_total_points` user meta on leaderboard page.

## Data Storage

### User Meta Fields
- `extrachill_total_points`: Cached total point count
- Individual point sources calculated from activity counts

### Transient Cache
- `user_points_{user_id}`: 1-hour cached total
- `user_topic_count_{user_id}`: 1-hour cached topic count
- `user_reply_count_{user_id}`: 1-hour cached reply count

## Usage Context

Rank system encourages:
- Consistent forum participation through topic and reply creation
- Quality content creation via upvote rewards
- Cross-platform engagement between forum and main site
- Long-term community involvement with clear progression path

Ranks provide visible achievement milestones and community status indicators.
