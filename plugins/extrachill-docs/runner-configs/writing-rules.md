# Extra Chill Documentation — Writing Rules

These rules are non-negotiable. They apply to every help article on docs.extrachill.com, every audit pass, and every agent run that produces user-facing documentation for the Extra Chill network.

This file is the canonical source. It is injected verbatim into every `Automattic/docs-agent` run executed against an Extra-Chill plugin repository. When these rules change, the next docs-agent run in every repo picks up the change automatically.

## Audience

Help articles are for end users — fans, artists, store customers, forum members, newsletter subscribers, event-goers. Never developers. Never engineers. Never operators of the platform.

If a non-technical reader has to know what something is to read the article, the article failed.

## Voice

- **Pure laymen abstractions.** Articles describe what a person is trying to do and what they get. They infer from how the product works without ever mentioning anything technical directly.
- **Benefit-focused.** Lead with why someone would care, not what the feature is.
- **Plain language.** Sixth-grade reading level. Short sentences. Active voice.
- **Friendly but not chatty.** Helpful neighbor explaining how something works, not a marketing brochure and not a manual.
- **No filler.** Cut every sentence that does not help the reader do the thing.

## Forbidden tokens — never name any of these

If an article mentions any of the following by name, the article failed and must be rewritten:

- **Plugins** by their package name (no "extrachill-artist-platform", no "WooCommerce", no "Sendy")
- **Tools** the platform uses internally (no "Data Machine", no "WordPress", no "Cloudflare", no "Redis")
- **Vendors and services** powering the platform (no "Sendy", no "OpenAI", no "Mailgun", no "Hetzner")
- **Protocols and APIs** (no "REST", no "API", no "webhook", no "OAuth", no "HTTP")
- **Hooks, filters, slugs** (no "action", no "filter", no "post type", no "taxonomy", no "term", no "meta key")
- **Libraries and packages** (no library names, no package names)
- **File paths and command-line tools** (no `/wp-content/`, no `wp-cli`, no shell commands)
- **Code** — ever. No code blocks. No inline code with backticks. No JSON. No YAML. No SQL.
- **Acronyms** that have not been spelled out (and most should be removed, not spelled out)
- **Internal product names** (no "the network", no "the platform" repeated as branding, no internal codenames)
- **Infrastructure terms** (no "server", no "database", no "cache", no "queue", no "cron")
- **Syncing, deploys, builds, releases** — these are invisible to the user
- **Developers, engineers, code, repositories** — the reader does not know or care that any of this exists

## Forbidden framings

- **"This plugin lets you …"** — there are no plugins from the user's perspective.
- **"The system tracks …"** — there is no system, there are features.
- **"Under the hood …"** — there is no under the hood. The reader is not looking under any hood.
- **"Technically, …"** — never explain technically anything.
- **"For developers, …"** — wrong audience.
- **"This feature was added in version …"** — versions are invisible.

## Positive framings

- **"When you submit an event, our team reviews it before it appears on the calendar."**
- **"Your link page has its own web address you can share anywhere — on social media, in your email signature, or printed on a flyer."**
- **"If you stop seeing the newsletter in your inbox, check your spam folder first, then make sure your email address is still correct in your account settings."**
- **"Following an artist means you'll see their new posts and shows on your home feed."**

Notice what these have in common:

- They describe what the **user does** and what the **user sees** or **gets back**.
- They never name a piece of software.
- They use concrete, everyday language.
- They are useful even to a reader who has never logged in before.

## Article structure

Every article should:

1. **Open with the benefit or the goal.** What is the user trying to accomplish? Why would they care?
2. **Give the steps or the explanation.** Numbered steps if it is a how-to. Short paragraphs if it is conceptual.
3. **Cover the edge cases briefly.** What if it does not work? What if they change their mind? What happens to their data?
4. **End cleanly.** No "in conclusion." No "we hope this helped." Just stop when the reader has what they need.

## When in doubt

- **Prefer the user's words.** If you would not say it to a friend over coffee, do not write it in the docs.
- **Prefer a screenshot to a paragraph.** A picture of the right button beats three sentences describing where the button is.
- **Prefer specificity.** "Click the heart icon at the top of the artist's page" is better than "Use the follow feature."
- **Prefer brevity.** When two articles say the same thing, merge them.

## How to read this file as an agent

You are generating user documentation. Before you write a single sentence, internalize these rules. Every sentence you produce will be reviewed against them. Sentences that name forbidden tokens will be rejected.

You are not writing technical documentation. You are writing help articles. Imagine the reader has never used a website before and just wants to do the one thing they came to do. Make that easy.

If reading the source code makes you want to name a plugin, a hook, or a file path, **translate it into what the user sees on their screen**, not what the developer wrote. The user does not see plugins or hooks. The user sees buttons, pages, emails, and outcomes. Write about those.
