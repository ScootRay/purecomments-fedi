# Pure Comments (Fediverse-preserving fork)

Fork of [Pure Comments](https://purecomments.org/) by [Kev Quirk](https://kevquirk.com), based on **v1.8.4** (last release that still includes Fediverse / Bridgy backfeed).

## Why this fork exists

Kev announced ([Pure Comments and the Fediverse](https://kevquirk.com/pure-comments-and-the-fediverse), 24 Oct 2026) that the next upstream release will **remove all Fediverse functionality** while keeping the Webmention endpoint for blog-to-blog notifications.

This fork keeps the Fediverse / Bridgy integration from 1.8.4 and tracks upstream Pure Comments for non-Fediverse fixes and features.

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

## Install / update notes

- Do **not** use the in-app “Apply Update” against official Pure Comments releases once Fediverse is removed upstream — that would overwrite this fork’s Fedi layer.
- Deploy from this repository (or a release tag here) instead.
- Keep your live `config.php` and `db/` out of git (never commit them).

## Baseline

Initial commit matches the live install snapshot of Pure Comments **1.8.4** with Fediverse code intact (`comment.php` Bridgy publish links, webmention handling, reaction facepiles, settings tab, etc.).
