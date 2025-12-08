# Extra Chill User Documentation

This directory contains **user-facing documentation** for the Extra Chill platform.

## 🚫 Rules

1.  **No Code Examples**: These guides are for end-users (artists, store managers, moderators), not developers.
2.  **Benefit-Focused**: Explain *why* a feature exists and *how* it helps the user.
3.  **Markdown Only**: All files must be valid Markdown (`.md`).
4.  **Structure**:
    *   Create a subdirectory for each platform/plugin (e.g., `artist-platform/`, `shop/`).
    *   The subdirectory name will be used as the `ec_doc_platform` taxonomy term.
    *   Use clear, descriptive filenames (e.g., `getting-started.md`, `managing-products.md`).

## 🔄 Sync Process

These files are the **Source of Truth**. Do not edit documentation directly in WordPress.

1.  **Edit**: Modify or create Markdown files in this directory.
2.  **Sync**: Run the sync script to push changes to `docs.extrachill.com`.
    ```bash
    ./scripts/sync.sh
    ```
