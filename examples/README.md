# Example templates

Four ready-to-copy consent banners and a cookie declaration, styled with
Tailwind. Craft never loads anything from this folder, so nothing here affects
your site until you copy it.

| | | |
|---|---|---|
| `bar.twig` | a strip along the bottom | least intrusive, blocks nothing |
| `corner.twig` | a card in the corner | room for a real sentence, still non-blocking |
| `sheet.twig` | a bottom sheet | slides up on a phone, a corner card on desktop |
| `modal.twig` | a dialog over the page | most insistent; CookieKit supplies focus handling |
| `declaration.twig` | the table for a policy page | not a banner; render with `declaration()` |

`panel.twig` is the preferences panel, shared by all four banners. It also
carries the handful of plain CSS rules they all need, so it belongs in the page
once. `declaration.twig` is independent of the rest: it needs no panel and no
CSS, only the banner script that `render()` already puts on the page.

## Install

```bash
mkdir -p templates/_cookiekit
cp -r vendor/viesrood/cookiekit/examples/templates/* templates/_cookiekit/
```

Then either render it yourself:

```twig
{{ craft.cookiekit.render({ template: '_cookiekit/bar', registerCss: false }) }}
```

or set it once and let automatic injection use it, under Settings, CookieKit:

- **Banner template**: `_cookiekit/bar`
- **Load the bundled stylesheet**: off
- **Inject banner automatically**: on

Either way `registerCss: false` (or the setting) is what keeps the plugin's own
stylesheet out of the page. The script stays: without it the banner is markup
that does nothing.

Make sure Tailwind scans the folder you copied into.

```js
// Tailwind 3, tailwind.config.js
content: ['./templates/**/*.twig'],
```

```css
/* Tailwind 4, in your stylesheet */
@source "../templates";
```

## Making them yours

Everything visual is a Tailwind class and safe to change. Two rules are not.

**Never put a display utility on an element the script toggles.** That means no
`flex`, `grid`, `block` or `inline-*` on anything carrying
`data-cookiekit-root`, `data-ck-banner`, `data-ck-panel` or `data-ck-details`.
The script opens and closes those with the `hidden` property, and in Tailwind 3
a display utility beats Preflight's `[hidden]` rule, so your panel would never
close again. Put the layout on an inner wrapper, which is what these templates
do. Tailwind 4 marks its own rule important and is already safe, and
`panel.twig` ships a guard rule so both majors behave the same.

**Leave the category checkbox as a real, enabled `<input>` hidden with
`sr-only`.** `sr-only` clips it without removing it from the tab order.
`hidden` or `display:none` would, and the script focuses the first input or
button in the panel when it opens, which then silently fails.

## Dark mode

Left out on purpose, because a banner that goes dark while the site stays light
is a bug rather than a feature, and `dark:` resolves differently depending on
your project's strategy. Add `dark:` variants to the colour classes once you
know which strategy you are on.

## The blocked-embed placeholder

When an embed is blocked, the script builds a placeholder in its place using the
class names `ck-placeholder`, `ck-btn` and `ck-btn--primary`. Those exist only
inside `cookiekit.js`, so Tailwind's scanner never finds them and `@source`
cannot help: they are not utilities. `panel.twig` therefore styles them in plain
CSS. Delete that block if you leave the bundled stylesheet switched on.

## Modal only

CookieKit 2 traps focus, restores it on close and locks page scrolling while
the preferences panel is open. Do not add a second focus trap to `modal.twig`.
The first layer covers the page by design and cannot be dismissed without a
choice.

Two things it deliberately does not do:

- **`inert` on the rest of the page.** It needs to know your page structure, and
  applied to the wrong element it inerts the dialog itself. Add it yourself if
  you want it, on the wrapper that holds your actual page content.
- **Escape on the first choice.** CookieKit does not dismiss a first consent
  request. Escape from the preferences panel returns to the first layer; after
  a valid choice exists it closes and restores focus.

A consent dialog that covers the page is fine, but only while refusing is
exactly as easy as accepting. That is why "Deny" sits next to "Accept all" here
rather than hidden behind "Adjust preferences". Do not move it.

## Content Security Policy

The `{% css %}` blocks land inline in the page. Under a strict `style-src` you
will need a nonce or hash, or move the CSS into your compiled stylesheet.
