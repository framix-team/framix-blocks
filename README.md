# Framix Blocks

A host plugin for **server-rendered Gutenberg blocks** — the kind you write as a
plain `block.json` + `render.php` pair, with no build step and no per-block
JavaScript. Framix Blocks is the engine: it discovers blocks from directories
that any plugin can register, validates each one defensively, and registers it
with WordPress so the editor gets a live preview and an Inspector for free.

It ships **zero blocks of its own**. That is deliberate. Framix Blocks is the
project-agnostic runtime; the blocks themselves live wherever they belong — most
often in a small per-site plugin that registers its `blocks/` directory through
a single filter. The engine updates like any other WordPress plugin, straight
from GitHub Releases; your site's blocks stay in your own repository and ship on
your own cadence.

## How it works

On `init` (priority 5) the loader reads one filter:

```php
$dirs = apply_filters( 'framix_blocks_block_dirs', array() );
```

Each entry is an absolute path to a directory whose immediate subdirectories are
blocks — `<dir>/<slug>/block.json` plus `<dir>/<slug>/render.php`. For every
block found, the loader:

1. Validates `block.json` defensively. An invalid block is skipped and logged
   (gated behind `WP_DEBUG`); it never fatals, so one bad block can't break the
   editor for the rest.
2. Derives the render callback name from the block's `name`. `namespace/slug`
   becomes `<namespace>_<slug>_render` with dashes turned to underscores — so
   `framix/sample-card` resolves to `framix_sample_card_render`.
3. Requires `render.php`, which defines that function.
4. Calls `register_block_type()` on the directory, letting WordPress read the
   metadata and — via `supports.autoRegister` — generate the editor preview and
   Inspector.

The only JavaScript the plugin ships is two generic editor shims: a
`MediaUpload` picker for attributes declaring `"control": "media"` (storing an
attachment ID), and a sidebar CRUD interface for `"control": "repeater"` arrays.
Neither renders the block body — the server-rendered output stays canonical in
both the editor and on the front end.

## The core / site split

Framix Blocks is engine-only by design, so two concerns stay cleanly separated:

- **The engine** (this plugin) is project-agnostic. It is installed and updated
  like any WordPress plugin, from this repository's GitHub Releases. It never
  contains a specific site's blocks, so updating it can never overwrite them.
- **A site's blocks** live in that site's own code repository, in a companion
  plugin (by convention, `framix-site-blocks`) that registers its block
  directory through the `framix_blocks_block_dirs` filter and deploys with the
  rest of the site's code.

A companion plugin needs only this:

```php
<?php
/**
 * Plugin Name: Framix Site Blocks
 * Requires Plugins: framix-blocks
 * Update URI: false
 */

add_filter(
	'framix_blocks_block_dirs',
	static function ( array $dirs ): array {
		$dirs[] = __DIR__ . '/blocks';
		return $dirs;
	}
);
```

`Requires Plugins: framix-blocks` lets WordPress enforce the dependency both
ways. `Update URI: false` keeps a site-specific plugin from ever being offered a
wp.org "update" for a colliding slug.

## A minimal block

A block is a directory with two files. The metadata:

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "framix/hero",
  "title": "Hero",
  "category": "framix",
  "textdomain": "framix-blocks",
  "attributes": {
    "heading": { "type": "string", "default": "", "label": "Heading" }
  },
  "supports": { "autoRegister": true },
  "style": "file:./style.css"
}
```

And the renderer — the function name follows directly from the block's `name`:

```php
<?php
// blocks/hero/render.php

if ( ! function_exists( 'framix_hero_render' ) ) {
	function framix_hero_render( $attributes, $content = '', $block = null ) {
		$heading = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
		$wrapper = get_block_wrapper_attributes( array( 'class' => 'framix-hero' ) );

		ob_start();
		?>
		<section <?php echo $wrapper; // escaped by get_block_wrapper_attributes() ?>>
			<?php if ( '' !== $heading ) : ?>
				<h2 class="framix-hero__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}
}
```

Drop that pair under a directory you've registered through the filter, and the
block is live — editor preview, Inspector, and front-end render, no build
tooling involved. A complete reference block (`framix/sample-card`) demonstrating
the media and repeater controls ships with the `framix-site-blocks` scaffold.

## Default images for media controls

A `"control": "media"` attribute stores an attachment ID and gets a
`MediaUpload` picker in the Inspector. Often you want it to start with an
image already chosen — a placeholder hero, a default avatar — without asking
every editor to pick one. Declare a `media` object on the attribute and ship
the file in the block's `assets/` directory:

```json
{
  "attributes": {
    "image": {
      "type": "integer",
      "default": 0,
      "control": "media",
      "media": {
        "default_asset": "assets/hero-default.webp",
        "alt": "Abstract gradient backdrop"
      }
    }
  }
}
```

`default_asset` is a path relative to the block directory, and the schema
`default` stays `0` — the engine fills in the real value. At registration the
engine sideloads the file into the site's media library the first time it
sees it, then rewrites the attribute's `default` to the new attachment ID. The
editor opens with the image pre-selected in the `MediaUpload` control, and your
`render.php` resolves the ID through `wp_get_attachment_image()` exactly as it
would for an editor-chosen image — there is no separate code path for the
default.

Resolution is **per environment** and **idempotent**. The engine keys each
asset by content hash (sha256), so the sideload happens once: every later
registration reuses the same attachment, on that environment. Staging and
production each resolve to their own attachment ID — IDs are never carried
across environments, which is why the default is expressed as a file in the
repository rather than a baked-in number. Replace the file with new content and
the next registration sideloads the new version (the old attachment is left
untouched — the engine never deletes attachments).

If anything goes wrong — the file is missing, unreadable, or the sideload
fails — the attribute simply keeps its `0` default and the block renders
without a default image. A bad asset is logged (under `WP_DEBUG`) and skipped;
it never fatals and never blocks the rest of the page. Operators can find the
hash-to-ID map in the `framix_blocks_media_defaults` option.

**Rasters only — not SVG.** `default_asset` accepts `webp`, `png`, `jpg`,
`jpeg`, `gif`, and `avif`. WordPress refuses SVG uploads, so SVGs can't be
sideloaded; for decorative or inline art, ship the SVG in `assets/` and
reference it directly with `framix_block_asset_url()` instead of routing it
through a media control.

### Asset-tree rules

Any file you ship under a block's `assets/` directory is vetted at
registration. The rules, so a malformed tree never trips the validator:

- **Allowed extensions only:** `webp`, `png`, `jpg`, `jpeg`, `gif`, `svg`,
  `avif`, `woff`, `woff2`, `ttf`, `otf`, `json`. Anything else — `.php`,
  `.js`, `.html`, dotfiles — is rejected.
- **No extensionless files.** `LICENSE`, `Makefile`, and friends have no
  allowlisted extension and are refused; keep them out of `assets/`.
- **No symlinks.** A symlink can point a friendly-looking name at something
  dangerous outside the tree, so they're rejected outright.
- **20 MB cap** on the total size of the directory.

A block whose `assets/` tree breaks a rule is skipped and logged, like any
other validation failure — it can't take the editor down with it.

## Updates

Framix Blocks updates natively through the WordPress updater, using
[Plugin Update Checker v5](https://github.com/YahnisElsts/plugin-update-checker)
(vendored) against this repository's GitHub Releases. New tagged releases appear
in **Dashboard → Updates** and update in place like any other plugin — no
external service, no authentication.

Each release publishes a `framix-blocks.zip` asset with a stable download URL:

```
https://github.com/framix-team/framix-blocks/releases/download/v<X.Y.Z>/framix-blocks.zip
```

You can install or pin a version directly from that URL.

## Requirements

- WordPress 7.0 or newer
- PHP 8.1 or newer

## Where this fits — FramixOS and the Framix WP Agent

Framix Blocks is one component of a larger, deliberately enterprise-grade way of
operating WordPress. [FramixOS](https://framix.net) and the
[Framix WP Agent](https://github.com/framix-team/framix-wp-agent-claude-code-plugin)
(a Claude Code plugin) drive that process: site code is canonical in git, work
happens staging-first, every production write passes through explicit
confirmation and fresh-backup gates, and plugin updates are applied
agent-driven through the WordPress-native updater rather than improvised against
a live site.

This plugin is built for that model. Because the engine is project-agnostic and
self-updating, while a site's blocks live in the site's own repository, the two
move independently and safely: the agent can roll the engine forward from a
tagged release without touching a site's blocks, and a site's blocks ship
through the same reviewed, git-canonical pipeline as the rest of its code. You
can use Framix Blocks on its own — it is a self-contained, standard WordPress
plugin — but it was designed as the block runtime for that operating process.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
