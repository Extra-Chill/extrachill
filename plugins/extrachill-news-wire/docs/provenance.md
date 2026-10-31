# Wire Provenance and Story Identity

Wire content is aggregated journalism. Every published item must remain traceable to the source item and Data Machine execution that produced it.

## Ownership

Data Machine owns generic execution provenance:

- `_datamachine_source_url` stores the canonical fetched source URL.
- `_datamachine_post_handler` identifies the post-producing handler.
- `_datamachine_post_flow_id` identifies the originating flow. Pipeline, agent, and user ownership are resolved through that flow.
- Job artifacts and transcripts retain the source packet, extraction context, and AI execution evidence.
- Processed-item tracking prevents the same source item from being selected repeatedly.

News Wire owns news-domain decisions:

- Whether a fetched document is publishable news.
- Whether multiple source items describe the same underlying story.
- Whether a new item updates an existing story, becomes a related development, or is rejected.
- Which source claims may appear in the published story.
- Festival, artist, location, and future Wire-type classification.

News Wire must not duplicate Data Machine origin fields under Wire-specific keys. Data Machine must not encode publisher, article, festival, or story-merging policy.

## Required Publication Evidence

A machine-published Wire item is complete only when all of the following are available:

1. A canonical source URL in `_datamachine_source_url`.
2. A handler and flow ID in Data Machine's post tracking metadata.
3. A retained job artifact or transcript containing the source packet used to generate the story.
4. Visible source attribution in the rendered article.

Rendered attribution is for readers. Metadata and job evidence are for auditability and automation. One does not replace the other.

Publishing should fail closed or remain in draft when the canonical source URL is unavailable. A source URL generated or inferred by AI is not acceptable.

## Source Truth and AI Output

The following values come from the fetch handler or source document and are authoritative:

- Canonical source URL.
- Source item identifier, such as a feed GUID, API ID, or Reddit fullname.
- Publisher or community identity.
- Original source title and byline when provided.
- Original publication timestamp when provided.
- Extraction timestamp.

AI may summarize, classify, and rewrite source material for the Wire article. AI must not overwrite or invent authoritative source fields. Source-derived values stay in the Data Machine packet and job evidence even when they are not copied into WordPress post meta.

## Identity Layers

Source identity and story identity are different.

### Source Item Identity

Data Machine owns exact source-item identity. The handler's stable item identifier and canonical URL determine whether that exact item was processed before.

Examples include a Reddit post fullname, RSS GUID, publisher API ID, or normalized canonical URL.

### Story Identity

News Wire owns cross-source story identity. Two different source items may describe the same announcement, controversy, lineup change, or subsequent development.

Do not add persistent story IDs until a second source or Wire vertical requires actual cross-source merging. When that consumer exists, the identity must be deterministic, explainable, and reviewable. Title similarity alone is insufficient.

## Publication Decisions

- **Create:** The source item covers a new story.
- **Update:** The same source item was revised without changing the underlying story.
- **Related development:** A distinct source advances an existing story and deserves its own post with an explicit relationship.
- **Merge:** Multiple source items are syndicated copies or materially identical reports. Preserve every source in job evidence.
- **Reject:** The item is off-topic, duplicative without additional value, unverifiable, or lacks a canonical source.

Published article content is editorial output. Automated source refreshes must not silently replace published prose. Material updates should create a revision and retain the prior version.

## Revision Policy

Wire posts support WordPress revisions. Machine updates should be exceptional rather than routine:

- Source metadata corrections may update post meta without rewriting the article.
- Material article changes create a WordPress revision.
- A newly discovered source usually becomes a related development instead of silently replacing the original story.
- Revision limits should be introduced only if measured machine-update volume creates database pressure.

## Current Gap

Production inspection in July 2026 found current Festival Wire posts missing Data Machine origin metadata even though the source URL remained visible in article content. This generic tracking regression is recorded upstream in `Extra-Chill/data-machine#2879`. News Wire must not add a local tracking workaround; the fix belongs in Data Machine's central post-producing tool execution.

## Adding Another Wire Type

Before adding a new Wire CPT, document:

- Its required source relationships and taxonomies.
- Its publishability and rejection rules.
- Its source handlers and stable item identifiers.
- Its subscription targets and return-value proposition.
- How its schema differs materially from `festival_wire`.

Only extract shared Wire code after the second CPT proves the common behavior.
