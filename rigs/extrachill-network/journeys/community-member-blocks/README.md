# community-member-blocks

The community's member blocks, end to end on the real network boot. These are
the blocks extrachill-community#326 moved off `wp-native-client` onto core's
`@wordpress/abilities`, plus the Settings → Notifications screen behind the
"has invalid input" report (extrachill-users#473).

| Step | Who | What must work |
| --- | --- | --- |
| `settings-notifications-save` | member 801 | `/settings/#tab-notifications` loads preferences, turning off emails and saving confirms |
| `edit-profile-save` | member 801 | `/u/scenemember/edit/` loads, saving a custom title confirms |
| `leaderboard-logged-out` | anonymous | `/leaderboard/` shows page 1 from the server render, **Next** loads page 2 |
| `heatmap-logged-out-year` | anonymous | `/u/scenemember/` heatmap switches to 2025 without an error |

Why logged out matters: core's ability list and descriptor endpoints need the
`read` capability, so a block that discovers abilities before running them
cannot work for visitors. Page 1 of the leaderboard is server-rendered and
page 2 / past heatmap years call the public `/run` endpoints directly.

Each step pings `reached-fixture.php` (POST, so the anonymous page cache can't
answer) only after all its assertions pass. `grade.php` turns each marker into
a case and adds two persisted checks: notification emails really disabled and
the custom title really stored. The seed creates one fixture member (joined
2024, so past-year heatmap tabs exist) and four ranked neighbours, and sets
the leaderboard page to two rows per page. The member is a single-scenario
fixture, not a persona.

Regenerate `journey.json` with `node build-journey.mjs`.
