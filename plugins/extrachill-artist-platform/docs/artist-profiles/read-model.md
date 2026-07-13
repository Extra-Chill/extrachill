# Artist Profile Read Model

Authorized network consumers should use the `extrachill/artist-get` ability for
one published artist profile. It is exposed through the WordPress Abilities REST
surface and accepts an `id` integer.

The response is authoritative on the Artist Platform site and contains:

- `id`, `name`, `slug`, and `permalink`
- `bio` from the artist profile post content
- `profile_image_id` / `profile_image_url` and `header_image_id` / `header_image_url`
- `local_city` for the hometown or local scene and `genre` for the artist style
- `official_links`, normalized from the artist-managed social-link store
- `link_page_id` when the artist has a link page

Only published `artist_profile` posts are returned. Draft, private, and missing
profiles return `invalid_artist`, so cross-site consumers must not query post
meta directly or duplicate the profile model.

## Core REST Contract

`artist_profile` is a public CPT with `show_in_rest` enabled. Core
`/wp/v2/artist_profile/<id>` exposes standard post content and `featured_media`
(the profile image), but it does not expose the header image, local scene,
genre, or normalized official links because those are Artist Platform metadata.
Use `extrachill/artist-get` when those fields are needed together.

## Latest Release

The Artist Platform does not currently store a latest-release field. Consumers
must treat it as unavailable rather than infer it from social or link-page
URLs. Add it only once an Artist Platform-owned release field has a defined
editor and privacy contract.
