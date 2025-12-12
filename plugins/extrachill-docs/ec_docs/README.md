# Extra Chill User Documentation

This directory contains **user-facing documentation** for the Extra Chill platform.

## Rules

1. **No Code Examples**: These guides are for end-users (artists, store managers, moderators), not developers.
2. **Benefit-Focused**: Explain *why* a feature exists and *how* it helps the user.
3. **Markdown Only**: All files must be valid Markdown (`.md`).
4. **No Frontmatter**: Do not use YAML frontmatter. Title comes from the H1 header, slug from filename, platform from directory.
5. **Structure**:
    * Create a subdirectory for each platform (must match slug in `taxonomy-seed.php`)
    * The subdirectory name becomes the `ec_doc_platform` taxonomy term
    * Use clear, descriptive filenames in kebab-case (e.g., `getting-started.md`, `managing-products.md`)

## File Format

```markdown
# Document Title

Introduction paragraph...

## Section Header

Content...

## Support

If you have questions or run into issues:

- Visit our [Support Forum](https://community.extrachill.com/r/tech-support)
- [Contact us](https://extrachill.com/contact/)
```

## Sync Process

These files are the **Source of Truth**. Do not edit documentation directly in WordPress.

1. **Edit**: Modify or create Markdown files in this directory.
2. **Sync**: Run the sync script to push changes to `docs.extrachill.com`.
    ```bash
    ./scripts/sync.sh
    ```

## Platform Directories

See `inc/core/taxonomy-seed.php` for the authoritative platform mapping.
