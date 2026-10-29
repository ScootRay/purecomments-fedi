# Changelog

This fork keeps Pure Comments 1.8.4 and its Fediverse / Bridgy support. The entries below are tags on this repository. They are not upstream Pure Comments releases.

## v1.8.4-fedi.1 (2026-10-29)

First backport onto the Fediverse-preserving fork. It brings the non-Fediverse webmention fixes from upstream 1.8.5. The Fediverse removal, the database purge and the mention rewrite from that release are not included. Schema changes here only add a column and a table.

- **Apply Update is locked.** The admin Apply Update button is disabled and a direct request is refused. That action downloads the stock upstream release, which would replace this fork. Opening the database afterward would run upstream `ensure_schema()` and permanently delete Fediverse / Bridgy rows. Deploy a tag from this repository instead. The updates page may still list a newer upstream version. Leave it alone. Restoring a named backup from that page still works.

- **Re-sent webmentions stay published.** A row that is already published stays published when the same source is sent again, even if it would now be held for moderation. A pending row can still be published by a later send. The moderation email goes out only the first time a new webmention lands as pending, so a re-send does not send another email.

- **Blocked webmention sources.** A `blocked_webmentions` table stores domains and source URLs. A blocked source is answered with HTTP 202 and status `ignored` and is not saved as a comment. Deleting a webmention (or a thread) from the dashboard adds each exact source URL to that list. Only an http(s) URL with a real path is added, so deleting one Bridgy like does not block all of brid.gy or a whole Fediverse instance.

- **Webmention titles.** A new webmention stores a short title taken from the page's social title, microformats name or document title, capped at 150 characters. The `title` column is added with `ALTER TABLE` only. Existing rows are left as they are until that source is sent again. A title already stored is kept when a later send has none. Admin search matches the title as well as the name and the body. Titles are not backfilled by fetching old source URLs.

- **Blocked sources field in settings.** The Webmentions tab has a textarea: one domain or one source URL per line. Saving that tab writes the list to `blocked_webmentions` and replaces whatever was stored, so removing a line and saving unblocks it. Deleted webmentions show up there by exact URL, including Fediverse likes, boosts and replies. The Fediverse profile URL and the auto-approve switches for reactions and for replies are unchanged.

- **Admin textarea spacing.** Textareas in the admin stylesheet have a bottom margin so the new field does not sit flush against the buttons below it.

- **A re-sent webmention cannot become its own parent.** Parent lookup could match the comment's own source URL and set `parent_id` to that comment's id, which dropped the reply out of its thread. If a re-send would do that, the update keeps the parent already stored.

Not brought over from upstream 1.8.5: the `ensure_schema()` purge of likes, reposts, replies and Bridgy rows, the rewrite of existing mentions and the curl backfill of missing titles.

## v1.8.4-fedi (2026-10-25)

Fork baseline. This tag is an import of Pure Comments 1.8.4 with Fediverse / Bridgy left in place: Bridgy publish links, webmention handling, reaction facepiles and the Webmentions settings tab. Later fixes land as backports on top of this tag. They are not taken by installing a stock upstream release.
