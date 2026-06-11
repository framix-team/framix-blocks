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
