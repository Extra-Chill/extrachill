# Connecting Your AI Client

Connect your own AI client to Extra Chill so it can find things and get work done on Extra Chill as you. You need an Extra Chill account. If you do not have one yet, [create one first](https://community.extrachill.com/login).

## Your connection address

Every AI client connects to the same address:

[https://auth.extrachill.com/wp-json/extrachill-mcp/v1/mcp](https://auth.extrachill.com/wp-json/extrachill-mcp/v1/mcp)

Copy it exactly. It is the only thing your client needs. The sign-in steps happen on their own once you add it.

## Adding Extra Chill to your AI app

**Claude (web or desktop app):**

1. Open Settings, then Connectors.
2. Choose Add custom connector.
3. Name it Extra Chill and paste your connection address.
4. Save it, then choose Connect and finish signing in.

**ChatGPT:**

1. Open Settings, then Apps and Connectors.
2. Under the advanced settings, turn on the option that lets you add your own connectors.
3. Choose Create, name it Extra Chill, and paste your connection address.
4. Leave sign-in set to the option that opens a sign-in page, save it, and finish signing in.

**Other AI apps, including ones that run in a terminal:** look for an option to add a custom connector or tool server, and give it your connection address. Step-by-step setup for those clients is at [extrachill.com/mcp](https://extrachill.com/mcp).

Menu names change from time to time, and some apps only offer custom connectors on certain plans. If you cannot find these options, check your AI app's own help pages for adding a custom connector.

## Signing in

The first time your client connects, it sends you to Extra Chill to sign in:

1. A sign-in page opens in your browser. Sign in with your usual Extra Chill account.
2. An approval page shows the name of the app asking to connect. Check that it is the app you just set up.
3. Approve, and you are sent back to your AI app. It is now connected as you.

You only do this once. Your app stays connected until you remove it or revoke its access.

## What you can do

Your client can help with the same things you can do when signed in:

- Update your profile, links, email, password, and notification preferences.
- Manage subscriptions to artists, venues, festivals, and scenes.
- Mark shows you are attending and import your concert history.
- Submit event sources, venue claims, and booking inquiries.
- Request artist access.

Artists can also manage the artist profiles and link pages they own. Venue operators and promoters can run guest lists and door check-in for their own events, and use the booking, settlement, and reporting features they have been granted access to. Extra Chill team members can reach their team tools.

Your client connects once and can search across the network. You do not need a separate connection for each Extra Chill site.

## Approving access

Connecting lets your client act **as your account**, with your permissions and no more. Access is checked for each action. Your client cannot manage another person's artist profile or a venue you do not have access to.

Connection approval covers your account access rather than a menu of individual permissions. Review requests carefully, especially when your client asks to delete or overwrite something. Approving an action changes real data.

## Connecting from another computer

If your AI client runs on a different computer from your browser, choose its device sign-in option when available. Open the verification page it gives you, enter the displayed code, and approve access. Keep the client running while you finish.

If approval finishes but your browser shows a connection-refused error, your client may be using a sign-in method that requires the browser and client to run on the same computer. Try device sign-in instead, or ask your client's support team which connection method it supports.

## Revoking access

Go to [your settings](https://community.extrachill.com/settings), then Security → Connected Apps. Revoking access immediately stops the client from renewing its connection. A session already in progress can continue for up to fifteen minutes before its current access expires.
