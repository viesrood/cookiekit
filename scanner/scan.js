#!/usr/bin/env node

/**
 * CookieKit browser scanner.
 *
 * Opens a real browser, walks the site twice, and writes down which cookies
 * actually land. That is the one thing a server-side crawl structurally cannot
 * do: it never executes the JavaScript that sets `_ga` or `_fbp`.
 *
 * Two passes, and the first is the interesting one:
 *
 *   1. no consent    nothing is accepted. Every non-necessary cookie that shows
 *                    up here was set before the visitor agreed to anything.
 *   2. all accepted  consent granted, so the full inventory becomes visible.
 *
 * Cookie names, storage keys and hostnames are collected. Values never are.
 *
 * Usage:
 *   node scan.js https://example.test --out scan.json
 *   node scan.js https://example.test --token $COOKIEKIT_SCAN_TOKEN --post
 *   node scan.js https://example.test --urls urls.txt --max 25
 */

const fs = require('node:fs');
const { chromium } = require('playwright');


function parseArgs(argv) {
    const args = {
        site: null, out: null, token: null, urls: null,
        max: 25, post: false, headed: false, insecure: false, wait: 2500,
    };

    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];

        if (!arg.startsWith('--')) {
            args.site ||= arg;
            continue;
        }

        const [flag, inlineValue] = arg.replace(/^--/, '').split('=');
        const value = inlineValue ?? (argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true);

        switch (flag) {
            case 'out': args.out = value; break;
            case 'token': args.token = value; break;
            case 'urls': args.urls = value; break;
            case 'max': args.max = Number.parseInt(value, 10) || 25; break;
            case 'wait': args.wait = Number.parseInt(value, 10) || 2500; break;
            case 'post': args.post = true; break;
            case 'headed': args.headed = true; break;
            case 'insecure': args.insecure = true; break;
            default: break;
        }
    }

    return args;
}

/**
 * Asks the site which pages to visit, so the sampling rules live in one place
 * (the plugin) instead of being reinvented here.
 */
async function discoverUrls(site, token, max) {
    const endpoint = new URL('/actions/cookiekit/scan/urls', site);

    const response = await fetch(endpoint, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
    });

    if (!response.ok) {
        throw new Error(
            `Could not get the URL list from the site (HTTP ${response.status}). `
            + 'Pass --token, or supply the pages yourself with --urls.',
        );
    }

    const data = await response.json();

    return (data.urls || []).slice(0, max);
}

function readUrlsFile(path, site, max) {
    return fs.readFileSync(path, 'utf8')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '' && !line.startsWith('#'))
        .map((line) => new URL(line, site).toString())
        .slice(0, max);
}

/**
 * Everything this tool is allowed to look at, in one place: names and hosts,
 * never values.
 */
async function collect(context, page) {
    const cookies = (await context.cookies()).map((cookie) => ({
        name: cookie.name,
        domain: cookie.domain,
        expires: cookie.expires,
    }));

    const storage = await page.evaluate(() => {
        const keys = (store) => {
            try {
                return Object.keys(store);
            } catch {
                // A sandboxed or partitioned context can refuse access.
                return [];
            }
        };

        return { local: keys(window.localStorage), session: keys(window.sessionStorage) };
    });

    return { cookies, local: storage.local, session: storage.session };
}

/**
 * Reads what the page actually granted rather than assuming it was everything.
 *
 * A site can offer fewer than the four categories: an empty one is left out of
 * the banner and out of what "accept all" grants. Reporting the full list here
 * would label findings with consent the site never gave, which is exactly the
 * kind of quiet untruth the scan exists to catch.
 */
function readGranted(page) {
    return page.evaluate(() => {
        const consent = window.CookieKit && typeof window.CookieKit.getConsent === 'function'
            ? window.CookieKit.getConsent()
            : null;

        return consent && Array.isArray(consent.c) ? consent.c : [];
    });
}

async function grantConsent(page) {
    const accepted = await page.evaluate(() => {
        if (window.CookieKit && typeof window.CookieKit.acceptAll === 'function') {
            window.CookieKit.acceptAll();
            return true;
        }

        return false;
    });

    if (accepted) {
        return readGranted(page);
    }

    // No JavaScript API on the page: fall back to clicking the button a
    // visitor would click.
    const button = page.locator('[data-ck-action="accept-all"]').first();

    if (await button.count() > 0) {
        await button.click({ timeout: 2000 }).catch(() => {});
        return readGranted(page);
    }

    return [];
}

async function runPass(browser, urls, { accept, wait, insecure }) {
    // A local development site serves a self-signed certificate, which the
    // browser refuses by default. Only ever pass --insecure for a site you
    // control on your own machine.
    const context = await browser.newContext({ ignoreHTTPSErrors: insecure });
    const pages = [];
    let consent = accept ? [] : [];

    for (const url of urls) {
        const page = await context.newPage();

        try {
            await page.goto(url, { waitUntil: 'load', timeout: 30000 });

            if (accept) {
                const granted = await grantConsent(page);

                if (granted.length > 0) {
                    consent = granted;
                    // Reload so everything that was blocked gets its chance.
                    await page.reload({ waitUntil: 'load', timeout: 30000 });
                }
            }

            // Trackers commonly fire a beat after load.
            await page.waitForTimeout(wait);

            const collected = await collect(context, page);
            pages.push({ url, ...collected });
            process.stdout.write(`  ${accept ? 'accepted ' : 'no consent'}  ${url}  (${collected.cookies.length} cookies)\n`);
        } catch (error) {
            process.stderr.write(`  ! ${url}: ${error.message}\n`);
        } finally {
            await page.close();
        }
    }

    await context.close();

    return { name: accept ? 'allAccepted' : 'noConsent', consent, pages };
}

async function main() {
    const args = parseArgs(process.argv.slice(2));

    if (!args.site) {
        process.stderr.write('Usage: node scan.js <site-url> [--out scan.json] [--post] [--token TOKEN]\n');
        process.exit(64);
    }

    const site = args.site.replace(/\/+$/, '') + '/';
    const urls = args.urls
        ? readUrlsFile(args.urls, site, args.max)
        : await discoverUrls(site, args.token, args.max);

    if (urls.length === 0) {
        process.stderr.write('No URLs to scan.\n');
        process.exit(65);
    }

    process.stdout.write(`Scanning ${urls.length} page(s) of ${site}\n\n`);

    const browser = await chromium.launch({ headless: !args.headed });

    try {
        // The order matters: measure the untouched state first, because
        // granting consent is not something you can take back within a context.
        const noConsent = await runPass(browser, urls, { accept: false, wait: args.wait, insecure: args.insecure });
        const allAccepted = await runPass(browser, urls, { accept: true, wait: args.wait, insecure: args.insecure });

        const payload = {
            site,
            scannedAt: new Date().toISOString(),
            passes: [noConsent, allAccepted],
        };

        const names = new Set();
        for (const pass of payload.passes) {
            for (const page of pass.pages) {
                for (const cookie of page.cookies) {
                    names.add(cookie.name);
                }
            }
        }

        process.stdout.write(`\n${names.size} distinct cookie name(s): ${[...names].sort().join(', ')}\n`);

        const preConsent = new Set();
        for (const page of noConsent.pages) {
            for (const cookie of page.cookies) {
                preConsent.add(cookie.name);
            }
        }

        if (preConsent.size > 0) {
            process.stdout.write(`\nSet before any consent was given: ${[...preConsent].sort().join(', ')}\n`);
            process.stdout.write('CookieKit will tell you which of these are a problem.\n');
        }

        if (args.out) {
            fs.writeFileSync(args.out, JSON.stringify(payload, null, 2));
            process.stdout.write(`\nWritten to ${args.out}\n`);
            process.stdout.write(`Import it with: php craft cookiekit/scan/import ${args.out}\n`);
        }

        if (args.post) {
            if (!args.token) {
                throw new Error('--post needs --token');
            }

            const endpoint = new URL('/actions/cookiekit/scan/import', site);
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Authorization: `Bearer ${args.token}`,
                },
                body: JSON.stringify(payload),
            });

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(`Import failed (HTTP ${response.status}): ${JSON.stringify(result)}`);
            }

            process.stdout.write(
                `\nSent to ${site}: ${result.new ?? 0} new finding(s), `
                + `${result.imported ?? 0} added to the declaration.\n`,
            );
        }

        if (!args.out && !args.post) {
            process.stdout.write('\nNothing written. Use --out <file> or --post.\n');
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exit(1);
});
