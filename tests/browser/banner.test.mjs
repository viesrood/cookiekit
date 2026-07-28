import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {extname, join, normalize} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from '../../scanner/node_modules/playwright/index.mjs';

const repo = normalize(join(fileURLToPath(new URL('.', import.meta.url)), '../..'));
const mime = {'.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css'};
const server = createServer(async (request, response) => {
    try {
        const path = normalize(join(repo, new URL(request.url, 'http://local').pathname));
        if (!path.startsWith(repo)) {
            response.writeHead(403).end();
            return;
        }
        response.writeHead(200, {'content-type': mime[extname(path)] || 'application/octet-stream'});
        response.end(await readFile(path));
    } catch {
        response.writeHead(404).end();
    }
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const address = server.address();
const base = `http://127.0.0.1:${address.port}/tests/browser/banner.html`;
const browser = await chromium.launch({headless: true});

async function freshPage(gpc = false) {
    const context = await browser.newContext();
    if (gpc) {
        await context.addInitScript(() => {
            Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', {
                configurable: true,
                get: () => true,
            });
        });
    }
    const page = await context.newPage();
    await page.goto(base);
    return {context, page};
}

try {
    {
        const {context, page} = await freshPage();
        assert.equal(await page.locator('[data-ck-banner]').isVisible(), true);
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-ck-banner]').isVisible(), true, 'initial consent cannot be dismissed');

        await page.getByRole('button', {name: 'Customize'}).click();
        assert.equal(await page.locator('[data-ck-panel]').isVisible(), true);
        await page.locator('#last-action').focus();
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('data-ck-category')), 'preferences');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-ck-banner]').isVisible(), true, 'Escape returns to the first layer');

        await page.getByRole('button', {name: 'Deny'}).first().click();
        assert.deepEqual(await page.evaluate(() => CookieKit.getConsent().c), ['necessary']);
        assert.equal(await page.evaluate(() => window.marketingLoaded || 0), 0);

        await page.locator('#settings-link').click();
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-cookiekit-root]').isHidden(), true);
        assert.equal(await page.evaluate(() => document.activeElement?.id), 'settings-link');
        await context.close();
    }

    {
        const {context, page} = await freshPage();
        await page.getByRole('button', {name: 'Accept all'}).first().click();
        assert.equal(await page.evaluate(() => CookieKit.hasConsent('marketing')), true);
        assert.equal(await page.evaluate(() => window.marketingLoaded), 1);
        await context.close();
    }

    {
        const {context, page} = await freshPage(true);
        assert.equal(await page.locator('[data-ck-banner] [data-ck-gpc]').isVisible(), true);
        await page.getByRole('button', {name: 'Customize'}).click();
        assert.equal(await page.locator('input[data-ck-category="marketing"]').isChecked(), false);
        await page.locator('input[data-ck-category="marketing"]').check();
        await page.getByRole('button', {name: 'Save'}).click();
        const consent = await page.evaluate(() => CookieKit.getConsent());
        assert.equal(consent.g, true);
        assert.equal(consent.go, true);
        assert.equal(await page.evaluate(() => CookieKit.getEffectiveConsent().c.includes('marketing')), true);
        await context.close();
    }
} finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
}

console.log('CookieKit browser contract: OK');
