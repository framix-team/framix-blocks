# Auto-WPML from block.json (framix-blocks core)

Flag translatable attributes in block.json. Core aggregates every
framix-registered block's flags and feeds WPML at runtime. No hand-written
`wpml-config.xml` per site.

## Convention

Plain string attribute:

    "title":   { "type": "string", "translatable": true, "label": "Title" }
    "excerpt": { "type": "string", "control": "textarea", "translatable": true }

Array-of-objects (repeater) attribute - declare the inner fields to translate:

    "items": {
      "type": "array",
      "control": "repeater",
      "translatable": { "fields": [ "label", "body" ] }
    }

Attributes without a translatable flag are not translated. `translatable:false`
(or absent) is the default.

## How it works

The loader collects each owned block's translatable descriptor at registration.
On WPML's `wpml_config_array` filter (fired by `WPML_Config::load_config_run()`
on admin config-rebuild pages, after `init`), the loader renders those
descriptors to a `wpml-config.xml` string and parses them back through WPML's own
`WPML_XML2Array` transform - guaranteeing the exact `gutenberg-block` array shape
WPML's Gutenberg integration consumes. The result is baked into the
`wpml-gutenberg-config` option, and the Advanced Translation Editor then exposes
those attributes as translatable strings.

Because it runs at runtime over whatever blocks the loader registered, it covers
the per-site companion plugin's blocks automatically - the core ships no blocks
of its own.

## Refreshing after you add a flag

WPML caches the parsed config. After adding or changing a `translatable` flag,
trigger a config rebuild: visit any WPML/Plugins/Themes admin page, or run
`wp eval 'WPML_Config::load_config_run();'`.
