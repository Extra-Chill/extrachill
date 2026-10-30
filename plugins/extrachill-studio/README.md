# Extra Chill Studio

The team workspace for publishing, social operations, transcription, analytics, and AI-assisted workflows across the Extra Chill network.

Studio runs at [studio.extrachill.com](https://studio.extrachill.com/) as a team-gated WordPress application. It gives contributors and editors focused tools outside the standard WordPress admin while preserving WordPress as the canonical content and identity system.

## Product Surfaces

### Blog

The Blog tab is a frontend block editor for Extra Chill articles.

- Creates drafts directly on the main Extra Chill site from the first meaningful save.
- Autosaves through WordPress core's per-user autosave endpoints.
- Restores newer autosaves when a writer returns to a draft.
- Supports main-site media browsing and uploads.
- Opens a real main-site preview from the live editor state.
- Submits finished work into the editorial review queue.
- Notifies the writer when an editor publishes the article.
- Shares active draft context with Roadie and refreshes accepted external edits.

Editors review pending work from **Studio wp-admin → Posts → Studio Submissions**, then finish metadata and publication in the main site's native editor.

Team documentation lives at [docs.extrachill.com/studio/](https://docs.extrachill.com/studio/).

### Socials

The Socials tab provides capability-aware access to shared brand accounts.

- Publishes captions and media through Data Machine Socials.
- Polls asynchronous cross-post jobs and shows per-platform results.
- Browses and uploads network media.
- Reads, filters, and replies to supported platform comments.
- Supports a review path for team members who cannot publish directly.

The remaining Socials roadmap centers on migrating approvals to Data Machine PendingActionStore, adding reviewer actions, platform-aware previews, and finishing the unified inbox.

### Transcribe

The Transcribe tab sends recordings to the Sweatpants/Whisper pipeline.

- Uploads audio directly to the transcription service.
- Supports model, speaker diarization, and filler-removal options.
- Polls short-running jobs in the browser.
- Creates a main-site draft for the uploader when processing finishes.
- Sends a completion email and supports transcript viewing, copying, and download.

### QR Codes

The QR Codes tab uses the existing Extra Chill QR ability to generate, preview, and download print-ready PNG files for any URL.

### Network

The Network tab gives team members a private view of platform health, including traffic, audience growth, retention, surface activity, and conversion paths.

## Access Model

Studio uses capabilities owned by the Extra Chill Users plugin.

- Logged-out visitors receive the shared login/register experience.
- Logged-in non-team users receive a team-only access message.
- Team members need the `access_studio` capability.
- Social account access is separately gated by the brand Socials capability.
- Blog review requires the native `edit_others_posts` editor capability.
- Administrators retain operational access.

Permission checks fail closed when the team-membership dependency is unavailable.

## Architecture

Studio is a WordPress plugin with a server-rendered Gutenberg block and a React application.

```text
Extra Chill team identity
          ↓
  Studio block shell
          ↓
 ┌────────┬─────────┬────────────┬──────────┬─────────┐
 │  Blog  │ Socials │ Transcribe │ QR Codes │ Network │
 └────────┴─────────┴────────────┴──────────┴─────────┘
```

Important boundaries:

- **Users and team roles:** Extra Chill Users
- **Canonical articles, revisions, and autosaves:** WordPress core on `extrachill.com`
- **Social accounts and platform operations:** Data Machine Socials
- **Transcription execution:** Sweatpants
- **AI chat context:** shared Extra Chill chat/Roadie integration
- **Analytics data:** owning Extra Chill analytics abilities
- **UI primitives and tokens:** Extra Chill Components and Tokens

Studio orchestrates these owner-layer capabilities. It does not create parallel identity, post, autosave, social, or analytics systems.

## Editorial Lifecycle

```text
team access
    ↓
main-site draft + autosaves
    ↓
preview
    ↓
pending review
    ↓
editor metadata + revision
    ↓
schedule or publish
    ↓
writer notification
```

The draft is born on the main site and remains there throughout the lifecycle. Studio records provenance instead of moving posts between subsites.

## Development

Requirements:

- WordPress 6.9+
- The PHP version declared in `extrachill-studio.php`
- Node.js and npm
- `extrachill-users`
- Extra Chill theme and shared component packages
- Feature dependencies for Socials, transcription, analytics, and chat

Install and verify:

```bash
npm ci
npm run test:unit
npm run typecheck
npm run build
```

Source lives under `src/blocks/studio/`. Generated release assets are written to `build/` during packaging and are not an editable source tree.

Key server modules:

```text
inc/compose/             Cross-site editorial REST and lifecycle support
inc/review-queue.php     Editor-facing blog review queue
inc/social-drafts.php    Legacy Socials approval path pending migration
inc/transcription/       Completion callback and email handoff
inc/abilities/           Studio-owned WordPress abilities
inc/team-experience/     Team adoption instrumentation
```

## Roadmap

The current roadmap is tracked in GitHub issues. Primary areas are:

1. Migrate Socials approval to Data Machine PendingActionStore.
2. Add signed reviewer actions and a pending Socials queue.
3. Add platform-aware previews and finish Socials inbox state.
4. Build a contributor and intern operating flow around existing WordPress content primitives.
5. Add deliberate inline AI editing and diff review to Compose.
6. Investigate real-time collaboration against current WordPress/Gutenberg primitives.
7. Remove obsolete client and editor compatibility debt.

See [open Studio issues](https://github.com/Extra-Chill/extrachill-studio/issues) for scoped work and dependencies.

## Related Repositories

- [extrachill-users](https://github.com/Extra-Chill/extrachill-users) — identity, team roles, profiles, and notifications
- [extrachill-network](https://github.com/Extra-Chill/extrachill-network) — multisite and cross-site foundations
- [data-machine](https://github.com/Extra-Chill/data-machine) — automation, pending actions, jobs, email, and abilities
- [data-machine-socials](https://github.com/Extra-Chill/data-machine-socials) — social platform operations
- [data-machine-editor](https://github.com/Extra-Chill/data-machine-editor) — inline editing and diff review
- [extrachill-components](https://github.com/Extra-Chill/extrachill-components) — shared interface components

## License

GPL-2.0-or-later
