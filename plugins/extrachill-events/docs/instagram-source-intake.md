# Instagram event sources

Event source preview, qualification and submission resolve URLs using `datamachine/resolve-source-url` for `event_import`. Website sources retain the existing qualification probe. Instagram profiles use the system-scoped `datamachine-socials/fetch-instagram-account` pre-flight with `max_items: 1`; this checks readability, not whether recent posts contain events. Only public Business/Creator accounts are supported.

Profiles are matched to existing venue, promoter or artist terms by account name and normalized Instagram handle. Unknown identities require explicit review rather than guessing from a caption. Venue handle matching uses Data Machine Events' `InstagramHandle` contract. Successful approvals fill missing handles, bind the canonical term and create daily, draft-producing social flows. Flow prompts remain empty. Events-domain policy is canonical in `EventSourceHandler::EVENT_POLICY`; handler item-shape instructions remain owned by Data Machine Socials.

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

Apply requires handler guidance, installs canonical events policy on the owning pipeline, binds the venue/promoter, clears the AI message and prompt queue through public abilities, and sets draft publishing and daily scheduling. It stops on errors; inspect partially applied flows before retrying. No production migration is performed by deploying the code alone.
