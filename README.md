# CookieKit

GDPR-compliant cookie consent for Craft CMS 5. Visitors can review all cookies and grant or deny consent per category. The banner is deliberately neutral in design and adapts to any house style through CSS custom properties.

**Features**

- Consent banner with per-category opt-in: necessary, preferences, statistics, marketing
- Preferences panel where visitors can review every cookie (name, provider, purpose, expiry)
- Cookie declaration managed in the control panel, or filled in by a scan
- Detection: finds the cookies your site actually sets, the trackers that are not blocked, and anything set before consent
- Optional server-side autoblocking for recognised trackers, with report mode and an explicit escape hatch
- Scripts and embeds stay blocked until consent is given
- Append-only consent proof with immutable declaration snapshots and automatic pruning
- Global Privacy Control (GPC), with an explicit visitor override
- Local daily analytics and CSV exports; counts only, no visitor profiles and no external service
- Action-oriented Craft control-panel dashboard
- Consent revisions: change your cookie usage and every visitor is asked for consent again
- Google Consent Mode v2
- Fully cache-safe, including with [Blitz](https://putyourlightson.com/plugins/blitz)

## Contents

1. [Installation](#installation)
2. [Quick start](#quick-start)
3. [Blocking scripts](#blocking-scripts)
4. [Blocking embeds (YouTube, maps)](#blocking-embeds-youtube-maps)
5. [Cookie declaration](#cookie-declaration)
6. [Styling](#styling)
7. [Twig API](#twig-api)
8. [JavaScript API](#javascript-api)
9. [Cached pages with Blitz](#cached-pages-with-blitz)
10. [Google Consent Mode v2](#google-consent-mode-v2)
11. [Automatic blocking](#automatic-blocking)
12. [Consent proof, analytics and revisions](#consent-proof-analytics-and-revisions)
13. [Global Privacy Control](#global-privacy-control)
14. [Language](#language)
15. [Building your own banner](#building-your-own-banner)
16. [Detection](#detection)
17. [Known limitations](#known-limitations)
18. [What this plugin does not do for you](#what-this-plugin-does-not-do-for-you)

## Installation

Requirements: Craft CMS 5.0+ and PHP 8.2+.

```bash
ddev composer require viesrood/cookiekit
ddev exec php craft plugin/install cookiekit
```

Developing locally from this repo? Add a path repository to your project's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../craftplugins/cookiekit" }
    ]
}
```

## Quick start

Three steps and the banner works.

**Step 1.** Render the banner in your layout, just before `</body>`:

```twig
{# templates/_layout.twig #}
<body>
    {# ... your site ... #}

    {{ craft.cookiekit.render() }}
</body>
```

Prefer not to touch templates? Enable "Inject banner automatically" in Settings → CookieKit instead.

**Step 2.** Add a link to your footer so visitors can change their choice. This is mandatory: withdrawing consent must be as easy as giving it.

```twig
<a href="#" data-cookiekit-show>Cookie settings</a>
```

**Step 3.** Fill in the cookie declaration in the CP under CookieKit → Cookies and block your scripts (next chapter). Skip that last part and your site still sets cookies, leaving you with a purely decorative banner.

## Blocking scripts

Set every script that places cookies to `type="text/plain"` and give it a category via `data-cookiekit`. The browser then won't execute it. As soon as the visitor accepts that category, CookieKit activates the script.

**Example: Google Analytics 4**

```twig
{# Before: #}
{# <script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXX"></script> #}

{# With CookieKit: #}
<script type="text/plain" data-cookiekit="statistics" async
        data-cookiekit-src="https://www.googletagmanager.com/gtag/js?id=G-XXXX"></script>

<script type="text/plain" data-cookiekit="statistics">
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-XXXX');
</script>
```

Note the attributes:

| Attribute | Meaning |
|---|---|
| `type="text/plain"` | Prevents the browser from executing the script |
| `data-cookiekit="..."` | The category: `preferences`, `statistics` or `marketing` |
| `data-cookiekit-src="..."` | For external scripts, instead of `src` (with `src` the browser downloads and runs the file anyway) |

**Example: Meta Pixel (marketing)**

```twig
<script type="text/plain" data-cookiekit="marketing">
    !function(f,b,e,v,n,t,s){/* ... pixel snippet ... */}(window, document, 'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', 'YOUR_PIXEL_ID');
    fbq('track', 'PageView');
</script>
```

## Blocking embeds (YouTube, maps)

For iframes, use `data-cookiekit-src` instead of `src`. While there is no consent, CookieKit automatically shows a placeholder with a button that opens the cookie settings. After consent the embed loads.

```twig
{# YouTube #}
<iframe data-cookiekit="marketing"
        data-cookiekit-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"
        width="560" height="315" allowfullscreen></iframe>

{# Google Maps #}
<iframe data-cookiekit="preferences"
        data-cookiekit-src="https://www.google.com/maps/embed?pb=..."
        width="600" height="450"></iframe>
```

## Cookie declaration

Manage all cookies in the CP under CookieKit → Cookies: name, category, provider, purpose and expiry. Visitors see this list in the preferences panel (expandable per category) and on your privacy page via:

```twig
{# templates/privacy.twig #}
<h2>Which cookies do we use?</h2>
{{ craft.cookiekit.declaration() }}
```

The declaration automatically includes a link to change preferences.

## Styling

**Configure nothing** and you get a clean, neutral banner (white, rounded corners, blue accent, system font).

**Level 1: CSS variables.** Override the custom properties in your own stylesheet and the banner follows your house style:

```css
.ck-root {
    --ck-bg: #1a1a2e;            /* banner and panel background */
    --ck-text: #ffffff;          /* text color */
    --ck-muted: #a0a0b8;         /* secondary text */
    --ck-border: #33334d;        /* borders and switch track */
    --ck-accent: #e94560;        /* primary button, links, active switch */
    --ck-accent-text: #ffffff;   /* text on the primary button */
    --ck-radius: 4px;            /* corner radius */
    --ck-font: 'Archivo', sans-serif;
    --ck-max-width: 640px;       /* banner and panel width */
    --ck-shadow: 0 8px 30px rgba(0, 0, 0, 0.35);
    --ck-overlay: rgba(0, 0, 0, 0.6); /* backdrop behind the panel */
    --ck-z: 99999;               /* z-index */
}
```

**Level 2: texts.** All texts go through Craft's static translations. Override them per project in `translations/en/cookiekit.php` (or `nl`, etc.):

```php
<?php
return [
    'We use cookies' => 'A quick word about cookies',
    'Accept all' => 'Fine, allow everything',
];
```

Dutch translations ship with the plugin.

**Level 3: your own template.** Four ready-made Tailwind banners ship in
`examples/templates`, from a subtle bottom bar to a modal that covers the page.
Copy one, keep the script, drop the stylesheet:

```twig
{{ craft.cookiekit.render({ template: '_cookiekit/bar', registerCss: false }) }}
```

See [Building your own banner](#building-your-own-banner) for the attributes the
script needs and the handful of things that will bite you.

**Level 4: everything yourself.** Skip the bundled CSS and JS and bring your own assets:

```twig
{{ craft.cookiekit.render({ template: '_cookiekit/banner', registerAssets: false }) }}
```

## Twig API

```twig
{{ craft.cookiekit.render() }}                      {# banner + preferences panel #}
{{ craft.cookiekit.declaration() }}                 {# cookie declaration as a table #}

{% if craft.cookiekit.hasConsent('marketing') %}    {# server-side check, see the Blitz chapter! #}
    {% include '_partials/personalised-block' %}
{% endif %}

{% set consent = craft.cookiekit.getConsent() %}    {# {id, revision, categories} or null #}
{% set statCookies = craft.cookiekit.cookies('statistics') %}
```

## JavaScript API

```js
CookieKit.show();                    // open the preferences panel
CookieKit.hide();
CookieKit.hasConsent('statistics');  // true/false
CookieKit.getConsent();              // {id, v, c: ['necessary', ...], t} or null
CookieKit.getEffectiveConsent();     // applies an active GPC signal
CookieKit.isGpcActive();
CookieKit.acceptAll();
CookieKit.denyAll();
CookieKit.withdraw();                // necessary only, logged as withdrawal
```

Events on `document`, useful to start functionality only after consent:

```js
// On every (new) consent choice
document.addEventListener('cookiekit:consent', (e) => {
    console.log('Granted:', e.detail.categories);
});

// Per category, fires as soon as that category is granted
document.addEventListener('cookiekit:marketing', () => {
    loadChatWidget();
});
document.addEventListener('cookiekit:statistics', () => {
    startHeatmapTracking();
});

document.addEventListener('cookiekit:consent-change', (e) => {
    console.log(e.detail.action, e.detail.revoked, e.detail.gpc);
});
```

## Cached pages with Blitz

CookieKit is designed to work with full-page caching such as [Blitz](https://putyourlightson.com/plugins/blitz#dynamic-content). The core principle: the cached HTML never contains visitor-specific consent state.

**What works out of the box, no action needed:**

- `craft.cookiekit.render()` only contains site-wide configuration (revision, categories, cookie list). Whether the banner is shown is decided by the JS per visitor, based on the consent cookie in the browser.
- Blocked scripts and iframes are static markup and therefore safe to cache. Activation happens client-side.
- Consent logging fetches its CSRF token at the moment of saving (via an AJAX request), so no token gets baked into the page for Blitz to freeze.
- The Google Consent Mode defaults are identical for everyone (everything denied); the personal update happens client-side.

**What to watch out for: `craft.cookiekit.hasConsent()` in cached templates.**

This server-side check reads the consent cookie of whichever visitor happens to fill the cache. Blitz stores the outcome and serves it to everyone afterwards. A visitor without consent could then get the block of someone who did consent, or the other way around.

The solution is the same pattern Blitz recommends for all dynamic content: pull the consent-dependent fragment out of the cache with a [dynamic include](https://putyourlightson.com/plugins/blitz#dynamic-content). It is rendered per visitor via AJAX while the rest of the page is served from the cache:

```twig
{# In your cached template: #}
{{ craft.blitz.includeDynamic('_includes/marketing-block') }}
```

```twig
{# templates/_includes/marketing-block.twig is rendered per visitor,
   so hasConsent() IS reliable here: #}
{% if craft.cookiekit.hasConsent('marketing') %}
    <div class="personalised-offer">
        {# personalised content #}
    </div>
{% endif %}
```

Prefer to avoid the extra AJAX request? Use the client-side variant. The page caches fully and the JS only reveals the block on consent:

```twig
<div data-needs-marketing hidden>
    {# personalised content #}
</div>

<script>
    document.addEventListener('cookiekit:marketing', () => {
        document.querySelectorAll('[data-needs-marketing]')
            .forEach((el) => { el.hidden = false; });
    });
</script>
```

Rule of thumb: `hasConsent()` only in templates that are rendered per visitor (dynamic includes, excluded URIs). Everywhere else, use the JS events.

## Google Consent Mode v2

Enable "Google Consent Mode v2" in the settings. CookieKit then sends:

1. A `consent default` signal (everything denied) in the `<head>`, before gtag.js loads.
2. A `consent update` as soon as the visitor chooses, and on every subsequent page view with existing consent. The mapping: marketing drives `ad_storage`, `ad_user_data` and `ad_personalization`; statistics drives `analytics_storage`; preferences drives `functionality_storage` and `personalization_storage`.

Two points of attention. Place your gtag or GTM snippet after `{{ head() }}` in your layout, so the defaults load first. And still block the gtag script itself with `data-cookiekit="statistics"`, Consent Mode is an addition to blocking, not a replacement.

## Automatic blocking

CookieKit can rewrite recognised non-essential scripts, iframes and tracking
pixels on the server, before the browser gets a chance to execute them. Use the
three-step setting deliberately:

1. **Off** changes nothing and is where a fresh install starts.
2. **Report only** lets Detection show what CookieKit recognises.
3. **Enforce for recognised providers** rewrites those resources with the same
   category data that Detection uses.

Unknown providers are never blocked on a guess. Existing `data-cookiekit`
markup, necessary resources and everything inside the CookieKit banner are left
alone. Add `data-cookiekit-ignore` to an element or an ancestor for a recognised
resource that must stay untouched:

```html
<div data-cookiekit-ignore>
    <script src="https://example-that-matches-a-project-signature.test/app.js"></script>
</div>
```

Automatic rewriting is a safety net, not a substitute for a scan. Tag-manager
containers remain opaque and malformed or unusual markup can require a manual
integration.

## Consent proof, analytics and revisions

**Proof.** Every choice and change is stored as an append-only event with its
uuid, action, revision, categories, timestamp, GPC state and a reference to the
exact declaration snapshot shown at that moment. CookieKit stores no IP
address, user agent or user id. View and export the audit trail under CP →
CookieKit → Consent log.

**Analytics.** Optional analytics count banner views and consent actions in one
local row per site and day. They contain no visitor identifier, URL, IP or user
agent and must be described as events rather than unique visitors. The dashboard
shows 30-day trends; users with export permission can download CSV.

**Revisions.** When your cookie usage changes materially (new processing purposes, a category newly in use), bump "Consent revision" in the settings. Existing consents no longer count and every visitor sees the banner again. Remember to clear your Blitz cache afterwards, otherwise cached pages still carry the old revision.

**Withdrawal.** When a visitor withdraws a category, the page reloads automatically. Scripts that have already loaded cannot be "unloaded"; a reload is the only way to make the withdrawal take effect immediately.

## Global Privacy Control

When `navigator.globalPrivacyControl` is active, CookieKit treats marketing as
denied and explains that in both banner layers. A visitor can still explicitly
enable marketing; the consent event records that deliberate override. If GPC is
enabled after an older marketing consent was stored, marketing is blocked and
the banner returns until the visitor confirms a current choice.

## Language

**The banner follows your site language.** On a front-end request Craft's
language is always the current site's, so the fix for a Dutch banner is one
field: Settings, Sites, your site, Language, `nl`. Dutch translations ship with
the plugin and load automatically.

A regional tag works too. Yii falls back from `nl-NL` or `nl-BE` to the plain
`nl` file, so there is nothing extra to install.

Two things that look like the translations are broken, but are not:

- **`<html lang="nl">` is yours, not the plugin's.** A site whose markup says
  Dutch while its Craft site language says English renders an English banner.
  That mismatch is the usual cause.
- **The cookie declaration is never translated.** Names, providers, purposes and
  lifetimes come out of the database exactly as they were typed.

### Rewording individual strings

Put a `translations/<language>/cookiekit.php` in your project root, next to
`config/` and `templates/`, keyed on the English source string. It overrides the
plugin's own translation key by key:

```php
<?php
return [
    'We use cookies' => 'Even over cookies',
];
```

### Forcing the language

For the case the setting exists for: a site whose Craft language cannot change,
but whose visitors read something else. Set **Banner language** to `nl`, or pass
it per call:

```twig
{{ craft.cookiekit.render({ language: 'nl' }) }}
```

The option beats the setting, and the setting beats the site language. Automatic
injection uses the setting too.

It translates the plugin's own texts only. A Dutch banner wrapped around English
cookie purposes reads worse than no forcing at all, so translate the declaration
first.

Two rules if you cache pages with Blitz. Keep the forced language constant:
deriving it from `Accept-Language` or a visitor preference bakes the first
visitor's language into the cache for everyone. And clear the cache after
changing it, the same as after bumping the revision.

On a multi-site install the setting is site-wide. Decide per site at the call
site instead, where `null` means "do not force":

```twig
{{ craft.cookiekit.render({ language: currentSite.handle == 'be' ? 'nl-BE' : null }) }}
```

## Building your own banner

The bundled banner is deliberately plain. Replacing it entirely is a supported
route, and worked examples ship with the plugin:

| | |
|---|---|
| `bar.twig` | a strip along the bottom, blocks nothing |
| `corner.twig` | a card in the corner |
| `sheet.twig` | a bottom sheet on a phone, a corner card on desktop |
| `modal.twig` | a dialog over the page, with focus trap and scroll lock |
| `declaration.twig` | not a banner: the cookie table for a policy page |

```bash
mkdir -p templates/_cookiekit
cp -r vendor/viesrood/cookiekit/examples/templates/* templates/_cookiekit/
```

```twig
{{ craft.cookiekit.render({ template: '_cookiekit/bar', registerCss: false }) }}
```

Or set it once under Settings, CookieKit, and let automatic injection use it:
**Banner template** `_cookiekit/bar`, **Load the bundled stylesheet** off.

See `examples/README.md` for the Tailwind setup and the design notes.

### Template variables

| | `render()` | `declaration()` |
|---|---|---|
| `settings` | yes | yes |
| `config`, a JSON **string** | yes | no |
| `cookiesByCategory` | yes | yes |
| `categories` | yes | yes |
| `categoryLabels` | yes | yes |
| `categoryDescriptions` | yes | yes |

### Empty categories

A category with no declared cookies is left out of the banner. Not just hidden:
it is not offered at all, so "Accept all" does not grant it either and any
script blocked under it stays blocked. A visitor cannot agree to something they
were never shown, and the alternative would let the two buttons disagree.

`necessary` always stays, because "Deny" and "Save preferences" grant it
regardless.

Two consequences worth knowing:

- **Declare the cookies before the switch appears.** If you block a YouTube
  embed as `marketing` but have not declared its cookies, visitors get a
  placeholder they cannot unlock. Declare them, or turn **Hide empty
  categories** off.
- **Visitors who already consented keep what they were given.** Their cookie
  still holds the category and there is no longer a switch to withdraw it. That
  is what the consent revision is for: bump it after the declaration changes
  materially and everyone is asked again.

The Cookies screen lists which categories are currently invisible, so the banner
never quietly has fewer switches than you expect.

### The attributes the script reads

This is the public contract. Everything else in a template is yours.

| Attribute | Goes on | Required | What it does |
|---|---|---|---|
| `data-cookiekit-root` | one wrapper | **yes** | The widget. Only the first one on the page is used. |
| `data-cookiekit-config` | the root | **yes** | Print it as `{{ config }}`, never with `\|raw`. |
| `data-ck-placeholder-text` | the root | no | Text shown in place of a blocked embed. |
| `data-ck-placeholder-button` | the root | no | Label of that placeholder's button. |
| `data-ck-banner` | inside the root | **yes** | Shown when there is no consent yet. |
| `data-ck-panel` | inside the root | **yes** | The preferences panel. Required even if you never show it. |
| `data-ck-action="accept-all"` | any element inside the root | yes | Consent to everything and close. |
| `data-ck-action="deny"` | idem | yes | Necessary only. |
| `data-ck-action="customize"` | idem | in practice | Open the panel. |
| `data-ck-action="save"` | in the panel | yes | Store what the switches say. |
| `data-ck-action="back"` | idem | no | Back to the banner. |
| `data-ck-action="close"` | idem | no | Close only when a valid choice already exists; otherwise return to the first layer. |
| `data-ck-gpc` | either layer | no | Shown only while Global Privacy Control is active. |
| `data-ck-category="..."` | an `<input type="checkbox">` in the root | for save | Read on save, written on open. `necessary` is forced on. |
| `data-ck-section` | around a toggle and its details | with the toggle | Scopes the toggle to its own table. |
| `data-ck-toggle-details` | a button in a section | no | Flips `aria-expanded` and the table. |
| `data-ck-details` | in the same section | with the toggle | Must start `hidden`. |
| `data-cookiekit-show` | anywhere on the page | no | Opens the panel. Works outside the root. |

### What will bite you

1. **Two roots on one page.** The script takes the first and the second is inert,
   with nothing to say so. Automatic injection plus a hand-placed `render()` is
   how it happens. Use one or the other.
2. **A missing `[data-ck-panel]`.** The script hides everything at page load for
   returning visitors and reaches into the panel without checking, so leaving it
   out throws before the consented scripts are unblocked. It breaks only for
   people who already said yes, which is the worst possible shape for a bug.
   Emit `<div data-ck-panel hidden></div>` even in a banner-only design.
3. **`|raw` on the config.** Twig's escaping round-trips through the attribute;
   `|raw` does not. An unparseable config makes the script bail out silently.
4. **A display utility on an element the script toggles.** In Tailwind 3, `flex`
   on the panel beats Preflight's `[hidden]` rule and the panel never closes.
   Keep `flex`, `grid` and `block` off the root, banner, panel and details, or
   ship the guard rule the examples use.
5. **`display:none` on a category checkbox.** It leaves the tab order and cannot
   be focused. Use `sr-only`.
6. **A typo in `data-ck-action`.** The click is swallowed before the value is
   read, so the button does nothing at all.
7. **`.ck-placeholder`, `.ck-btn` and `.ck-btn--primary` are hardcoded in the
   script.** No template contains them, so Tailwind never sees them. Write plain
   CSS, or leave the bundled stylesheet on.
8. **Actions must be inside the root.** Only `[data-cookiekit-show]` works
   anywhere on the page.
9. **Do not add a second focus trap.** CookieKit traps focus and locks page
   scrolling while the preferences panel is open. A custom modal should leave
   those responsibilities to the bundled script.

## Detection

You do not have to fill the declaration by hand. Under **CookieKit → Detection**
a scan tells you which cookies your site really sets, which trackers are loading
without being blocked, and whether anything is set before consent.

There are two scans, and they answer different questions.

### The built-in scan

```bash
php craft cookiekit/scan/urls    # which pages would be visited
php craft cookiekit/scan/run     # visit them
php craft cookiekit/scan/status  # last run and what is still open
```

It fetches your own pages, reads the `Set-Cookie` headers your server sends, and
walks the HTML for third-party scripts, iframes and pixels. Everything it finds
is matched against a database of about twenty known vendors, which supplies the
provider, purpose, category and expiry for each cookie they set.

It never runs JavaScript. So it sees `CraftSessionId` land for real, but it can
only *infer* `_ga` from the fact that it found the Analytics tag. The control
panel says which is which, and only measured cookies are ever added on their own.

### The browser scan

```bash
cd scanner && npm install && npx playwright install chromium
node scan.js https://example.nl --out scan.json
php craft cookiekit/scan/import scan.json
```

This opens a real browser and walks your site twice: once accepting nothing, and
once accepting everything. The first pass is the interesting one, because every
non-necessary cookie that appears there was set **before the visitor agreed to
anything**, which is the thing that actually gets sites fined.

It needs Node and Chromium, so run it from your own machine. Nothing extra has to
be installed on the server. See `scanner/README.md`.

For a site you cannot reach with a console, set `COOKIEKIT_SCAN_TOKEN`, point the
"Scan token" setting at it, and let the scanner post its results:

```bash
node scan.js https://example.nl --post --token "$COOKIEKIT_SCAN_TOKEN"
```

### What happens to what it finds

A finding is added to the declaration on its own only when all three are true:
it was actually observed, it is recognised (so the category and purpose rest on
something), and the declaration does not already cover it. Everything else waits
in the inbox for you, because a cookie declaration is a legal document and an
invented description is worse than an empty one.

Every automatic import is one batch and can be undone in one click, in the
control panel or with `php craft cookiekit/scan/revert <batch>`. Rows you have
edited since are left alone.

Turn the automatic part off with "Add measured cookies automatically" if you
would rather approve everything yourself.

### Trackers that are not blocked

The scan also reports third-party resources loading without `data-cookiekit`
markup, and hands you the exact snippet to paste over them, attributes and all.
Paste it, scan again, and the row disappears by itself.

### Extending the vendor database

Add or override entries in `config/cookiekit-signatures.php`. A partial entry is
merged over the shipped one, and a key set to `false` removes it:

```php
<?php
return [
    'my-crm' => [
        'label' => 'Our CRM',
        'provider' => 'Example B.V.',
        'category' => 'marketing',
        'blockAs' => 'marketing',
        'hosts' => ['track.example.com'],
        'cookies' => [
            [
                'name' => 'crm_id',
                'match' => 'exact',
                'declaredAs' => 'crm_id',
                'category' => 'marketing',
                'duration' => '1 year',
                'purpose' => 'Recognises you across visits.',
            ],
        ],
    ],
    'pinterest' => false,
];
```

Modules can do the same through `SignatureService::EVENT_REGISTER_SIGNATURES`.

## Known limitations

Things that are true today and worth knowing before they surprise you.

**Server-side consent checks are not GPC-aware.** `craft.cookiekit.hasConsent()`
in Twig reads the consent cookie and nothing else. The browser-side check does
subtract Global Privacy Control, so the two can disagree: a visitor who accepted
everything and later switched GPC on is marketing-denied in the browser while
Twig still says yes.

That is deliberate. Reading the `Sec-GPC` header server-side would make the page
vary per visitor, which is exactly what breaks full-page caching. So gate
marketing content on the client instead, where CookieKit already respects GPC:

```twig
{# GPC-aware, cache-safe #}
<script type="text/plain" data-cookiekit="marketing"
        data-cookiekit-src="https://example.com/pixel.js"></script>
```

Use `hasConsent()` in Twig for things that are not marketing, or accept that it
follows the stored cookie rather than the browser signal.

**Other current limitations**

- Report mode looks at a URL at most once an hour, so a tracker that only
  appears on a page nobody visits stays invisible until someone does. Run
  Detection for a deliberate sweep.
- Automatic blocking never touches a `<template>`, because scripts in there can
  never be unblocked again by the browser.
- Consent snapshots are never pruned. They hold no personal data and they are
  the proof artefact, but purging consent rows leaves their snapshots behind.
- The CSV export builds the whole file in memory and ignores the filters on
  screen. Fine for normal volumes, not for millions of rows.
- The dashboard and the consent log do not check per-site permissions, so an
  editor limited to one site can read another site's numbers by changing the
  query string.
- The consent log is not anonymous, and the plugin no longer calls it that.
  It keeps no IP address and no user agent, but the consent id is a
  pseudonymous identifier that ties one visitor's events together for as long
  as their cookie lives. That is exactly what makes a receipt usable as proof
  under art. 7 GDPR: without it you could show that somebody consented, not
  that this visitor did. Treat the table as personal data and set the retention
  period accordingly.
- With analytics enabled, the banner writes one key to `sessionStorage` before
  any choice is made, to avoid counting the same view twice. Analytics is off by
  default.

## What this plugin does not do for you

Be honest with yourself and your client: a banner alone does not make a site compliant.

- With automatic blocking off, CookieKit only blocks resources that you mark. With it on, only recognised signatures are rewritten. One unknown tracker can still set cookies without consent; Detection reduces that risk but cannot guarantee absence.
- **The built-in scan cannot execute JavaScript.** It therefore never sees `_ga`, `_fbp` or `VISITOR_INFO1_LIVE` land. Those names come from the shipped signature database, as an educated inference from the scripts your pages load, and are labelled as such. Only the browser scan observes them for real.
- **A tag container is opaque.** CookieKit can tell you Google Tag Manager is present. It cannot tell you which tags are configured inside it, because that lives in the GTM interface and not in your HTML.
- **The crawl is a sample.** Pages are discovered from live entries with a URI, capped and sampled per entry type. Anything behind a login, a form or a POST request is never visited.
- **Cookies that need a human are invisible** until one turns up: playing a video, opening a chat widget, submitting a form, dragging a map. Run the browser scan with `--headed` and click those things yourself.
- **With Blitz or any full-page cache** the scan either bypasses the cache, and then measures a variant no visitor receives, or reads the cached pre-consent variant. Both are useful, neither is the whole picture. "Bypass the page cache while scanning" chooses.
- **Suggestions go out of date.** Providers change cookie names, lifetimes and purposes without telling anyone. Every suggested category, purpose and expiry is a suggestion; you remain the controller.
- **Detection can prove a cookie was there, never that it is gone.** Findings expire on age, not on absence, and a declared cookie is never deleted automatically.
- **Out of scope entirely:** server-side tracking (Conversions API, server-side GTM), fingerprinting, CNAME-cloaked first-party trackers, and pixels that set no cookie.
- **Stylesheets cannot be blocked.** `cookiekit.js` swaps `<script>` and `[data-cookiekit-src]` elements. A `<link>` to Google Fonts is reported as a third-party transfer, but there is no mechanism to gate it. Self-host those fonts.
- A privacy policy and data processing agreements remain your own responsibility.

## Development checks

```bash
composer check
cd scanner && npm install && npx playwright install chromium && cd ..
composer test:browser
```

The browser contract covers first consent, refusal, script activation, focus
trapping, reopening, withdrawal-safe closing and Global Privacy Control.

## License

MIT
