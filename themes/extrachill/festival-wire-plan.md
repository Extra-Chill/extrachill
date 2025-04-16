# Plan: Integrate "Festival Wire" Custom Post Type (Revised)

**Objective:** Integrate a "Festival Wire" custom post type into the Extra Chill theme to display music festival news in an attractive, user-friendly format, filterable by festival.  *The content for this feed will be automatically populated by an external source via a satellite plugin.*  This revision updates the display to a grid of mini blog cards, incorporates a "drop us a tip" form, and refines the filtering and styling approach, including secure handling of Cloudflare Turnstile keys.

**Design Style:** Modern, clean, minimalist, **grid of mini blog cards** design, text-based initially, consistent with the Extra Chill theme's overall style.  Sidebar will be removed from the Festival Wire archive page initially. Filter bar styling will replicate the existing sorting dropdown and "Randomize Posts" button styles for consistency. Dark mode compatibility will be maintained.

**Content Source:** *Automatically populated* from an external source via a satellite plugin.  *Our focus is on designing the frontend display for this automatically imported content.*

**Key Features:**

*   Custom Post Type: "Festival Wire"
*   Dedicated Directory: `festival-wire/` within the theme
*   Template Files: `archive-festival-wire.php`, `single-festival-wire.php`
*   Custom CSS: `festival-wire.css`
*   JavaScript (if needed): `festival-wire.js`
*   **Grid Layout (Archive Page) with Mini Blog Cards**
*   Filterable by Festival (using existing theme's filter bar style)
*   Modular and Expandable Code
*   **"Drop us a tip" form at the bottom of the archive page with Cloudflare Turnstile bot protection (using WordPress Options for keys)**

**Detailed Steps:**

1.  **Create `festival-wire` directory:**
    *   Create a new directory named `festival-wire` within the `/wp-content/themes/colormag-pro/` directory. This directory will house all PHP files related to the Festival Wire functionality.

2.  **Create `festival-wire.php`:**
    *   Inside the `festival-wire/` directory, create a file named `festival-wire.php`.
    *   This file will be the main file for the Festival Wire module and will contain:
        *   Code to register the "Festival Wire" custom post type.
        *   Code to enqueue `festival-wire.css` and `festival-wire.js`.
        *   Any functions specific to the Festival Wire functionality.

3.  **Create CSS and JS folders (if needed):**
    *   Check if `css` and `js` directories exist in the root of the theme (`/wp-content/themes/colormag-pro/css/` and `/wp-content/themes/colormag-pro/js/`).
    *   If these directories do not exist, create them.

4.  **Create `festival-wire.css` and `festival-wire.js`:**
    *   Create an empty CSS file named `festival-wire.css` in the `/wp-content/themes/colormag-pro/css/` directory. This file will contain all CSS styles specific to the Festival Wire section.
    *   Create an empty JavaScript file named `festival-wire.js` in the `/wp-content/themes/colormag-pro/js/` directory. This file will contain any JavaScript functionality needed for the Festival Wire section.

5.  **Register Custom Post Type & Enqueue CSS/JS in `festival-wire.php`:**
    *   In `festival-wire/festival-wire.php`, add the code to register the "Festival Wire" custom post type.  Use labels and arguments appropriate for a news feed format. *Design the custom post type to be flexible enough to accommodate data fields expected from the external content source (e.g., title, content, festival name, date).*
    *   In the same file, add code to enqueue `festival-wire.css` and `festival-wire.js`. Ensure these are enqueued conditionally, only on Festival Wire related pages (archive and single post pages).

6.  **Enqueue `festival-wire.php` in `functions.php`:**
    *   Open the theme's main `functions.php` file (`/wp-content/themes/colormag-pro/functions.php`).
    *   Add code to include/require `festival-wire/festival-wire.php`. This will be the *only* line in `functions.php` related to Festival Wire enqueueing, centralizing all logic in `festival-wire.php`.

7.  **Develop Template Structure (Grid of Mini Blog Cards):**
    *   *(Focus on creating a visually engaging and user-friendly grid of mini blog cards.)*
    *   **Archive Template (`archive-festival-wire.php`):**
        *   Create `archive-festival-wire.php` in the theme's root directory (`/wp-content/themes/colormag-pro/`).
        *   Structure this template to display a **grid** of "Festival Wire" posts.
        *   *Consider these visual elements for each **mini blog card** (Festival Wire post):*
            *   *Clear and concise Post Title (headline/festival name) -  Make it prominent.*
            *   *Brief Post Excerpt (content snippet) -  Limit to a few lines for readability in the grid.*
            *   *Subtle Post Date/Time - Display in a less prominent way.*
            *   *Visually distinct Festival Tag/Category - Use a color-coded tag or icon.*
        *   Implement functionality for filtering posts by festival.  *Prioritize a simple and intuitive filtering mechanism, replicating the style of the existing "Randomize Posts" button and sorting dropdown.*
        *   Implement pagination if needed for a large number of posts. *Use standard WordPress pagination for a seamless user experience.*
        *   **Remove the sidebar from this template.** (`<?php // get_sidebar(); ?>` in `archive-festival_wire.php`)
        *   **Add "Drop us a tip" form at the bottom of the template.**
            *   *Simple form with a text input and submit button.*
            *   **Implement Cloudflare Turnstile bot protection (using WordPress Options for keys).**
                *   *Store Site Key and Secret Key as WordPress Options (`ec_turnstile_site_key`, `ec_turnstile_secret_key`).*
                *   *Reference `extrachill-custom/contact-form.php` for frontend and backend integration logic.*
                *   *Frontend Integration: Use `get_option('ec_turnstile_site_key')` to dynamically insert the site key into the Turnstile widget.*
                *   *Backend Validation: Use `get_option('ec_turnstile_secret_key')` in `wp_surgeon_verify_turnstile()` for server-side verification.*
                *   *Include necessary JavaScript and server-side validation to integrate Turnstile.*
            *   *Form submission sends an email to the admin with the tip content.*
    *   **Single Post Template (`single-festival-wire.php`):**
        *   Create `single-festival-wire.php` in the theme's root directory (`/wp-content/themes/colormag-pro/`).
        *   Structure this template for displaying the full content of a single "Festival Wire" post in a clean and readable format.*
        *   Include:
            *   Prominent Post Title
            *   Full Post Content - *Ensure good typography for comfortable reading.*
            *   Post Date/Time
            *   Festival Tag/Category
        *   Consider adding social sharing buttons. *Place them in a visually accessible location.*

8.  **Modularization and Expandability:**
    *   Write code in a modular fashion, breaking down templates into template parts where appropriate (e.g., for individual post display in the archive).
    *   Ensure the code is well-commented and easy to understand for future modifications and additions.
    *   Design the structure to be expandable for potential future features, such as incorporating images, videos, or more complex filtering options.

9.  **CSS Styling (`festival-wire.css`):**
    *   Style the "Festival Wire" section to be visually appealing, modern, and *seamlessly* consistent with the ColorMag Pro theme's overall design language, now focusing on a **grid layout of mini blog cards**.
    *   Focus on a clean, minimalist aesthetic with subtle pops of color *to highlight key elements like festival tags or dates.*
    *   Ensure the layout is responsive and looks good on different screen sizes. *Test on various devices to ensure optimal viewing.*
    *   Pay attention to typography to ensure readability in the grid format. *Choose fonts and sizes that are easy to read in a card context.*
    *   **Filter Bar Styling:** Replicate the existing styles for `#post-sorting` and `button#randomize-posts` from `style.css` for the Festival Wire filter bar. Ensure dark mode compatibility using theme variables.

10. **JavaScript Functionality (`festival-wire.js`):**
    *   Implement any necessary JavaScript functionality. *Initially, JavaScript might not be required. However, keep this file for potential future enhancements, such as:*
        *   *Interactive filtering (if dropdown menus are enhanced with JS).*
        *   *Dynamic content loading (infinite scroll or "Load More" button, if needed for performance).*
    *   Keep JavaScript code separate and well-organized.

11. **Brainstorming Styling:**
    *   Discuss visual style for mini blog cards.
    *   Explore color palettes, typography, card design, and subtle pops of color.
    *   Consider minimalist and clean aesthetic.

12. **Cloudflare Turnstile Integration:**
    *   **Securely Store Keys:** Use WordPress Options (`ec_turnstile_site_key`, `ec_turnstile_secret_key`) to store Turnstile site key and secret key.
    *   **Reference `extrachill-custom/contact-form.php`:** Examine this file to understand the existing Cloudflare Turnstile implementation.
    *   **Frontend Integration:** Add the Turnstile widget to the "drop us a tip" form in `archive-festival_wire.php`. Dynamically retrieve site key using `get_option('ec_turnstile_site_key')`. Include necessary JavaScript for widget rendering and form submission handling.
    *   **Backend Validation:** Implement server-side validation in PHP (likely in `festival-wire.php` or `functions.php`) to verify the Turnstile response. Dynamically retrieve secret key using `get_option('ec_turnstile_secret_key')` in `wp_surgeon_verify_turnstile()`.
    *   **Email Sending:**  Use `wp_mail()` to send the tip content to the admin email address.

13. **Next Steps (After Plan Approval):**
    *   Implementation of the plan by the designated agent.
    *   Testing and refinement of the Festival Wire integration.