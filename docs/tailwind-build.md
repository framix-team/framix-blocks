# Tailwind build — conventions (framix-blocks engine)

The canonical home of the Tailwind build for the Framix block stack. The per-site
`framix-site-blocks` plugin carries the runnable config + blocks + committed
output; this core plugin carries the **engine** and these **conventions**. No
duplication — the engine lives here only; consumers invoke it.

## Engine

`engine/tw-build.sh -i <input.css> -o <output.css> [--watch]` — pure, project-
agnostic Tailwind v4 compiler. No `site.yml`/`FRX_*` knowledge; the caller cd's
into the layer and passes relative paths. Toolchain resolution (relative to CWD):

1. `node_modules/.bin/tailwindcss` (local `npm install`, pinned)
2. `npx -y @tailwindcss/cli@<TW_CLI_VERSION>` — offline in egress-denied runners.

`TW_CLI_VERSION` defaults to **`4.3.0`**, overridable via `FRX_TW_CLI_VERSION`.

## Directory contract (per site)

    <site-code>/<content>/plugins/framix-site-blocks/
      tailwind/
        src/input.css      token map (@theme) + @source globs
        tokens.css         design tokens, vendored from design-brain
        dist/blocks.css    compiled output — COMMITTED; servers never build
      blocks/<slug>/        block.json + render.php

`framix-site-blocks/tailwind/package.json` build script invokes the engine by
sibling path: `../../framix-blocks/engine/tw-build.sh -i src/input.css -o dist/blocks.css`.

## input.css rules

- No preflight; utilities unlayered — blocks style themselves, never rely on a reset.
- `@source "../../blocks/**/*.php";` — TWO levels up from `tailwind/src/` to reach
  the plugin root's `blocks/`. `../blocks` is the classic field bug: it scans a
  nonexistent `tailwind/blocks/` and emits a near-empty stylesheet.
- `@import "../tokens.css";` for `@theme` tokens. Prefer semantic utilities
  (`bg-primary`, `text-surface`) over arbitrary values; safelist runtime-built
  class names with `@source inline("…")`.

## Design tokens — single source

`design-brain` is the one origin. A sync vendors the token CSS into each consumer
that needs it (the `framix-site-blocks/tailwind/tokens.css` here; the theme's own
copy when the theme uses Tailwind). One origin, a copy per consumer — never a
cross-consumer import path.

## The pin is a frozen contract

`@tailwindcss/cli@4.3.0` — EXACT, never a dist-tag — moves in lockstep across FOUR
points: this engine's `TW_CLI_VERSION` default, the wp-agent scaffold
`package.json`, the wp-agent `tw-build.sh` wrapper default, and the FramixOS
runner-image pre-warm. The runner is egress-denied; a mismatched pre-warm fails
every platform build.

## Install-first

`framix-blocks` (this plugin) must be installed **before** `framix-site-blocks`:
both the block loader and the Tailwind build depend on it. The per-site build
resolves this engine by sibling path and refuses with guidance if it's absent.
