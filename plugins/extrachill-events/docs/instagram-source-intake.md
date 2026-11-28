# Instagram event sources

Event source preview, qualification and submission resolve URLs using `datamachine/resolve-source-url` for `event_import`. Website sources retain the existing qualification probe. Instagram profiles use the system-scoped `datamachine-socials/fetch-instagram-account` pre-flight with `max_items: 1`; this checks readability, not whether recent posts contain events. Only public Business/Creator accounts are supported.

Profiles are matched to existing venue, promoter or artist terms by account name and normalized Instagram handle. Unknown identities require explicit review rather than guessing from a caption. Venue handle matching uses Data Machine Events' `InstagramHandle` contract. Successful approvals fill missing handles and create daily social flows that publish directly, like web flows. Promoters are bound through the declared upsert field. Instagram venue identity is recorded in `_venue_instagram` and the flow name, not in upsert configuration: generic socials handlers have no `venue` setting. Structural venue binding for non-venue-aware handlers is a separate data-machine-events follow-up. Social AI steps use `EventSourceHandler::EVENT_POLICY` as their flow `user_message`; shared pipeline prompts and web-flow messages are unchanged. Events-domain policy is canonical in `EventSourceHandler::EVENT_POLICY`; handler item-shape instructions remain owned by Data Machine Socials.

Requires the Data Machine URL resolver, Instagram URL claim and handler AI guidance releases. Older installations retain the web scraper fallback.

## Existing Charleston flows

After deploying handler guidance, preview the migration from the plugin directory:

```sh
wp eval-file scripts/migrate-instagram-source-flows.php
```

Review canonical term matches for flows 902, 903, 908 and 909. The routine refuses missing or ambiguous terms or non-Instagram flows. Apply explicitly:

```sh
wp eval-file scripts/migrate-instagram-source-flows.php apply
```

Apply requires handler guidance, replaces each copied AI flow `user_message` with `EVENT_POLICY`, binds promoters only, and sets direct publishing, moves scheduled flows to daily and leaves manual flows manual, and skips term binding when no single term matches. The copied prompt is flow configuration (`user_message`), not queue content; no queue-clear call or pipeline configuration update is needed. It stops on errors; inspect partially applied flows before retrying. No production migration is performed by deploying the code alone.
