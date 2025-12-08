# Extra Chill Blocks

Custom Gutenberg blocks for extrachill.com (Blog ID 1) providing interactive content elements for creative blog posts.

## Site-Specific Plugin

This plugin is intended for use **only on extrachill.com** (the main blog site). It should not be network-activated. The blocks enhance content creation with interactive and AI-powered elements specific to the blog's creative content needs.

## Blocks

| Block | Description |
|-------|-------------|
| **Trivia** | Interactive multiple-choice trivia questions with scoring |
| **Image Voting** | Image-based polls with email capture and newsletter integration |
| **Rapper Name Generator** | Generates rapper stage names based on user input |
| **Band Name Generator** | Generates band names by genre with customizable options |
| **AI Adventure** | AI-powered text adventure games with branching narratives |
| **AI Adventure Path** | Story path/branch container (child of AI Adventure) |
| **AI Adventure Step** | Individual story step with triggers (child of AI Adventure Path) |

## Dependencies

- **extrachill-api**: REST API endpoints for all block operations
- **extrachill-ai-client**: AI provider for Adventure blocks and name generators
- **extrachill-newsletter**: Newsletter subscription for Image Voting block

## API Endpoints

All endpoints registered via extrachill-api plugin:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/extrachill/v1/blocks/rapper-name` | POST | Generate rapper name |
| `/extrachill/v1/blocks/band-name` | POST | Generate band name |
| `/extrachill/v1/blocks/image-voting/vote` | POST | Submit vote |
| `/extrachill/v1/blocks/image-voting/vote-count/{post_id}/{instance_id}` | GET | Get vote count |
| `/extrachill/v1/blocks/ai-adventure` | POST | AI adventure game logic |

## Build System

Uses `@wordpress/scripts` for block compilation:

```bash
npm install     # Install dependencies
npm run build   # Production build
npm run start   # Development with hot reload
```

Build output goes to `/build/` directory. The universal `build.sh` script handles npm build automatically when `@wordpress/scripts` is detected.

## Version

- **Current**: 1.0.0
- **WordPress**: 5.8+
- **PHP**: 7.4+
- **Network**: false (site-specific activation only)

## Author

Chris Huber - [chubes.net](https://chubes.net)
