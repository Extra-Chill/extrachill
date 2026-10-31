# Profile image attachment IDs

Artist identity remains owned by the artist profile. Attachment IDs are local to
one WordPress site, not network-wide.

- `extrachill/update-artist.profile_image_id` uses the **artist site's** media
  library.
- `extrachill/save-link-page-settings.profile_image_id` and the corresponding
  field on `extrachill/artist-update-links` use the **Link Page storage site's**
  media library. `0` clears the image on both sites.
- The Link Page editor uploads its image into the Link Page storage site.

Identity pushes copy the source image into the destination library and store
`_ec_source_attachment = <source-blog-id>:<source-attachment-id>` on the copy.
Reverse saves use the same mapping and resolve an originating attachment rather
than copying it back again. Repeated pushes reuse the materialized attachment.
Copies have their own files and generated WordPress image metadata. Old copies
are retained because they may be referenced elsewhere.

Migrated images are adopted without changing the page's attachment ID only when
the source and destination files have identical SHA-256 hashes. An ID collision
or matching title is never sufficient. Missing/unreadable source files return an
error rather than writing a foreign ID. This implementation requires local,
readable source media files; offloaded media must expose a readable local file.

The owned-content backfill compares mapped image identity. Its dry run neither
copies attachments nor adds tracking metadata. Run its apply mode on the artist
site to repair stale pages:

```sh
wp --url=<artist-site> eval 'print_r( ec_artist_backfill_link_page_owned_content( true ) );'
```
