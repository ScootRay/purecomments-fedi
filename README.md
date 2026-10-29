# Pure Comments (Fediverse-preserving fork)

Fork of [Pure Comments](https://purecomments.org/) by [Kev Quirk](https://kevquirk.com). It stays on **v1.8.4** (the last release that still includes Fediverse / Bridgy backfeed) and takes later fixes only as backports. See `CHANGELOG.md`.

## About this fork

Kev announced ([Pure Comments and the Fediverse](https://kevquirk.com/pure-comments-and-the-fediverse), 24 Oct 2026) that the next upstream release would remove all Fediverse functionality and keep the webmention endpoint for blog-to-blog notifications.

This repository is that fork. Tag `v1.8.4-fedi` is Pure Comments 1.8.4 with Fediverse / Bridgy left intact: Bridgy publish links, webmention handling, reaction facepiles and the Webmentions settings tab. Tag `v1.8.4-fedi.1` (29 Oct 2026) is the first backport of the useful webmention fixes from upstream 1.8.5. It does not include the Fediverse removal.

### Do not upgrade to stock 1.8.5

Upstream 1.8.5 mainly removes Fediverse support. It does not include security fixes.

`ensure_schema()` runs every time the database is opened. On stock 1.8.5 that function permanently deletes rows whose type is `like`, `repost` or `reply`, plus any row whose source URL contains `brid.gy`. Child replies under those rows are deleted too. That removes Bridgy backfeed from Bluesky and Mastodon (likes, boosts and replies) along with any other reply stored as type `reply`. It then rewrites the mentions that remain: images are stripped, the text is cut down to a short excerpt and the type is set to `webmention`. One request against a stock 1.8.5 install is enough to do this and later requests run it again.

Do not point a Fediverse install at stock 1.8.5. Take upstream fixes by backporting them onto this fork.

### Upgrading this fork safely

1. Back up the SQLite database (`db/`) and a copy of the code before you change anything.
2. Deploy a tag from this repository such as `v1.8.4-fedi.1`. Do not unpack an upstream zip over the top.
3. Never use the built-in **Apply Update** button to pull stock upstream. On this fork the button is disabled and a direct request is refused, because that download would replace the Fediverse layer and the next database open would delete the rows described above. The updates page may still list a newer upstream version. Leave it alone. Restoring a named backup from that page still works.
4. Keep live `config.php` and `db/` out of git.

### nginx and `/api`

Apache already routes `/api/*` to the PHP front controller (`api/index.php`) via `api/.htaccess`. nginx does not read `.htaccess`. If this app sits behind nginx, `/api/*` has to reach that front controller or `POST /api/webmention` returns 404 and webmentions never arrive. For example:

```nginx
location /api/ {
    rewrite ^/api/(.*)$ /api/index.php?endpoint=$1 last;
}
```

That same front controller also serves `GET /api/comments/<slug>` and `POST /api/submit-comment`.

### Future upstream fixes

When upstream publishes something you want:

1. Read the diff. Skip anything that deletes Fediverse or Bridgy rows, rewrites mentions or removes Bridgy.
2. Backport the rest onto this fork as its own commit. Schema changes stay additive (`ALTER TABLE ... ADD COLUMN` and `CREATE TABLE IF NOT EXISTS`). Do not copy upstream `ensure_schema()` as-is. That function also fetches old source pages to backfill titles. This fork does not.
3. Tag the result (`v1.8.4-fedi.2` and so on) and deploy that tag only after a backup.

## Upstream

- Project site: https://purecomments.org/
- Docs: https://docs.purecomments.org/
- Upstream source: https://github.com/kevquirk/purecomments (archived; also on Codeberg)

Add upstream as a git remote when cloning:

```bash
git remote add upstream https://github.com/kevquirk/purecomments.git
# or Codeberg, whichever carries the next releases
```

## License

Same as upstream: see `LICENSE.md` (Pure Comments License v1.1). Attribution to Kev Quirk must remain.
