# Standard block supports (framix-blocks core)

Every block registered through the framix-blocks loader automatically gains a
curated, conservative set of editor controls. You do not declare these in your
block.json. They are injected at registration via the core `block_type_metadata`
filter, scoped to framix-owned blocks only (third-party blocks are untouched).

## The curated set

- spacing: margin, padding, blockGap
- dimensions: minHeight
- border: color, radius, style, width
- typography: fontSize, lineHeight
- color: text, background
- retained: anchor, reusable

## Opt out per block

Declare the top-level support key in your block.json `supports` to take full
control of it. The block's value replaces the injected default wholesale (a
shallow, block-wins merge - no deep merge):

    "supports": { "color": false }                    // no color controls
    "supports": { "spacing": { "padding": false } }   // padding off; margin/blockGap also off,
                                                       // because declaring spacing takes full
                                                       // control of the whole spacing key

## Token binding is per site

Core enables the controls. It does NOT ship presets and does NOT force
`custom:false`. Bind the controls to the design system in the site `theme.json`
`settings` so the editor renders token dropdowns, not free input. Recommended
shape (fill the preset values from the site design tokens):

    {
      "version": 3,
      "settings": {
        "spacing": {
          "padding": true,
          "margin": true,
          "blockGap": true,
          "units": [ "px", "rem" ],
          "spacingSizes": [
            { "slug": "20", "size": "0.5rem", "name": "XS" },
            { "slug": "40", "size": "1rem",   "name": "S" },
            { "slug": "60", "size": "2rem",   "name": "M" },
            { "slug": "80", "size": "4rem",   "name": "L" }
          ],
          "customSpacingSize": false
        },
        "typography": {
          "fontSizes": [
            { "slug": "small",  "size": "0.875rem", "name": "Small" },
            { "slug": "medium", "size": "1rem",     "name": "Medium" },
            { "slug": "large",  "size": "1.5rem",   "name": "Large" }
          ],
          "customFontSize": false,
          "lineHeight": true
        },
        "color": {
          "palette": [
            { "slug": "primary",    "color": "var(--wp--preset--color--primary)",    "name": "Primary" },
            { "slug": "surface",    "color": "var(--wp--preset--color--surface)",    "name": "Surface" },
            { "slug": "foreground", "color": "var(--wp--preset--color--foreground)", "name": "Foreground" }
          ],
          "custom": false,
          "customGradient": false,
          "text": true,
          "background": true
        },
        "border": { "color": true, "radius": true, "style": true, "width": true },
        "dimensions": { "minHeight": true }
      }
    }

Use design-system tokens, not raw hex or px, when you fill these in. Keep borders
at 1px. Per site, extend the existing theme.json presets to cover these and set
`custom:false` where token-only is intended.
