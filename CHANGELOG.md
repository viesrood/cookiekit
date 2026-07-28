# Changelog

## 1.0.0 - 2026-07-28

First public release.

### Consent

- Consent banner with per-category opt-in: necessary, preferences, statistics, marketing.
- Preferences panel with per-category toggles and the full cookie details behind each one.
- The first-layer actions carry equal visual weight, and an initial consent request cannot be dismissed without a choice.
- Consent revisions: bump the revision and every visitor is asked again.
- Global Privacy Control is honoured, and a visitor who overrides it has that override recorded.
- Empty categories are left out of the banner, controlled by a `Hide empty categories` setting that is on by default. A category with nothing declared under it was a switch with no content. A hidden category is not offered at all, so `Accept all` does not grant it either. `necessary` is always offered, because `Deny` and `Save preferences` grant it either way.
- Banner language setting and a `language` render option, for a site whose Craft language is not the language its visitors read. It translates the plugin's own texts only, never the declaration.

### Proof

- Append-only consent receipts, each linked to an immutable snapshot of the declaration, policy, language and duration that was actually shown. A receipt on its own says a visitor agreed; the snapshot says to what.
- The log is pseudonymous, not anonymous, and says so. It holds no IP address and no user agent, but the consent id is a UUID in the visitor's own cookie that ties one person's receipts together, which is exactly what makes a receipt provable under art. 7 GDPR.
- Streaming CSV export that carries the filters from the screen you exported it from, with formula injection neutralised on the way out.
- Bounded retention: receipts, findings, daily counters and orphaned snapshots are all cleaned up by garbage collection, each step isolated from the others.
- Permanent deletion sits behind its own `cookiekit:purgeData` permission and is logged with who asked for it.

### Blocking

- Manual blocking via `type="text/plain"` + `data-cookiekit` for scripts, and `data-cookiekit-src` with a placeholder for embeds.
- Server-side automatic blocking for recognised scripts, iframes and tracking pixels, in three modes: off, report only, enforce. `data-cookiekit-ignore` opts a tag out.
- Enforcing rewrites only the opening tags it blocks and leaves every other byte of the document alone, so JSON-LD, inline SVG and non-ASCII characters survive untouched. A golden test asserts that.
- Report mode runs the detector on the pages visitors actually request and puts what enforcing would have acted on into the Detection inbox, without changing the output. Throttled to one look per URL per hour.
- CSP nonces, `type="module"`, `srcset` and lazyloader attributes are all preserved and restored on consent. Only http, https, protocol-relative and relative URLs are ever restored to a live `src`.

### Detection

- `php craft cookiekit/scan/run` crawls your own pages, reads `Set-Cookie` headers, and recognises third-party scripts, iframes and pixels against a database of around 20 vendors.
- Browser scanner (`scanner/scan.js`, Playwright): opens the site twice, once accepting nothing and once accepting everything, and measures which cookies really land. Runs from your own machine.
- Pre-consent detection: a non-necessary cookie present while nothing was accepted is its own alert.
- Detection screen with compliance alerts, an inbox with a per-row category, bulk add and ignore, and an undo for the last automatic import.
- Recognised findings that were actually observed are added to the declaration on their own, in one revertable batch. Anything unrecognised waits for you.
- Console commands: `scan/run`, `scan/urls`, `scan/import`, `scan/status`, `scan/revert`, `scan/prune`. Token-protected endpoints for scanning over the network, closed unless a token is configured.
- Project-level signature overrides via `config/cookiekit-signatures.php` and `SignatureService::EVENT_REGISTER_SIGNATURES`.

### Control panel

- Craft-native health dashboard with setup actions, scan state and local activity totals.
- Cookie declaration management, with a record of where each row came from, when a scan last confirmed it, and which import it belongs to. Cookie names are unique.
- Privacy-minimal daily analytics for banner views and choices: counters only, no visitor identifiers, no external service.
- Site and date filters throughout, and site access resolved through Craft's own `viewSite` permission so an editor limited to one site cannot read another's.

### For developers

- Twig API (`craft.cookiekit.*`) and JavaScript API (`window.CookieKit`), including `getEffectiveConsent()`, `isGpcActive()`, `withdraw()` and a detailed `cookiekit:consent-change` event.
- `registerCss`, `registerJs` and `registerAssets` render options, plus `BannerJsAsset` and `BannerCssAsset`, so a custom banner can keep the script and drop the stylesheet.
- `Banner template` setting, so automatic injection can render your own template.
- Four ready-to-copy Tailwind banners in `examples/templates` (bottom bar, corner card, bottom sheet, blocking modal) and a Tailwind cookie declaration table. They share one preferences panel, and the modal adds the focus trap and scroll lock the plugin deliberately does not provide.
- `categoryLabels` and `categoryDescriptions` are passed into banner and declaration templates.
- Theming via CSS custom properties and template overrides.
- Keyboard focus trapping, focus restoration, scroll locking and reduced-motion support in the default UI.
- Rate limiting on both public endpoints, so neither can be used to fill the disk or drown genuine receipts in forged ones.
- Dutch translations.
- Test suite (`composer test`) and static analysis (`composer analyse`), both green.
