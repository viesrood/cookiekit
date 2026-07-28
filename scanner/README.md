# CookieKit browser scanner

Measures which cookies a site **actually** sets, by opening it in a real browser.

The plugin's own scan (`php craft cookiekit/scan/run`) reads your HTML and your
response headers. That is enough to spot every third-party script and every
cookie your server sets, but it never executes JavaScript, so it cannot see
`_ga`, `_fbp` or `VISITOR_INFO1_LIVE` land. It infers those from the scripts it
finds, and labels them as inferred.

This tool closes that gap. It is optional: the plugin works without it.

## Install

Requires Node 18 or newer.

```bash
cd scanner
npm install
npx playwright install chromium
```

## Use

```bash
# Write a file and import it yourself
node scan.js https://example.nl --out scan.json
php craft cookiekit/scan/import scan.json

# Or send it straight to the site
node scan.js https://example.nl --post --token "$COOKIEKIT_SCAN_TOKEN"
```

| Flag | |
|---|---|
| `--out FILE` | write the result as JSON |
| `--post` | send the result to the site (needs `--token`) |
| `--token TOKEN` | the value of `COOKIEKIT_SCAN_TOKEN` on that site |
| `--urls FILE` | scan these pages instead of asking the site |
| `--max N` | how many pages to visit (default 25) |
| `--wait MS` | how long to linger per page (default 2500) |
| `--headed` | show the browser |
| `--insecure` | accept a self-signed certificate (local development only) |

Without `--urls` the scanner asks the site which pages to visit, so the sampling
rules live in the plugin instead of being reinvented here. That endpoint needs
`--token`.

Local development against DDEV:

```bash
node scan.js https://example.ddev.site --urls urls.txt --insecure --out scan.json
```

## What it does

Two passes over the same pages, and the first is the interesting one.

**Pass 1, no consent.** Nothing is accepted. Every non-necessary cookie that
turns up here was set before the visitor agreed to anything, which is the thing
that actually gets sites fined. CookieKit flags those in red.

**Pass 2, everything accepted.** Consent is granted through
`window.CookieKit.acceptAll()`, falling back to clicking the accept button.
The page is reloaded so blocked scripts get their chance, and the full cookie
inventory becomes visible.

Per page it records cookie names, `localStorage` and `sessionStorage` keys, and
the hosts involved. **Cookie values are never read and never sent.** The plugin
sanitises the payload again on arrival and refuses anything that looks like a
value.

## What it still cannot see

- Cookies that only appear after someone interacts: playing a video, opening a
  chat widget, submitting a form, dragging a map. Run with `--headed` and click
  those things yourself if they matter.
- Which tags are configured inside a container like Google Tag Manager. That
  lives in the GTM interface, not in your site.
- Pages behind a login, a paywall or a form.
