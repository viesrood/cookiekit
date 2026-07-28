/**
 * CookieKit 2: dependency-free consent, GPC and deferred resource activation.
 */
(function () {
    'use strict';

    var COOKIE = 'cookiekit_consent';
    var root = null;
    var config = null;
    var previous = [];
    var opener = null;
    var csrfPromise = null;
    var bodyOverflow = '';
    var modalLocked = false;

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function readRawCookie() {
        var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
        return match ? match[1] : null;
    }

    function getConsent() {
        var raw = readRawCookie();
        if (!raw) {
            return null;
        }
        try {
            var data = JSON.parse(decodeURIComponent(raw));
            if (!data || typeof data !== 'object' || !Array.isArray(data.c) || data.v < config.revision) {
                return null;
            }
            return data;
        } catch (e) {
            return null;
        }
    }

    function writeConsent(consent) {
        var value = encodeURIComponent(JSON.stringify(consent));
        var cookie = COOKIE + '=' + value +
            '; Max-Age=' + (config.duration * 86400) +
            '; Path=/; SameSite=Lax';
        if (location.protocol === 'https:') {
            cookie += '; Secure';
        }
        document.cookie = cookie;
    }

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    function isGpcActive() {
        return navigator.globalPrivacyControl === true;
    }

    function effectiveCategories(consent) {
        var categories = consent ? consent.c.slice() : ['necessary'];
        if (isGpcActive() && consent && !consent.go) {
            categories = categories.filter(function (category) {
                return category !== 'marketing';
            });
        }
        return categories;
    }

    function getEffectiveConsent() {
        var consent = getConsent();
        if (!consent) {
            return null;
        }
        var effective = {};
        Object.keys(consent).forEach(function (key) {
            effective[key] = consent[key];
        });
        effective.c = effectiveCategories(consent);
        effective.gpc = isGpcActive();
        return effective;
    }

    function csrf() {
        if (!csrfPromise) {
            csrfPromise = fetch(config.csrfUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            }).catch(function (error) {
                /* Memoising the rejection too would mean one failed token
                   request silently stops all consent logging for the rest of
                   the page's life, including the receipt for a choice the
                   visitor is about to make. Forget it and let the next call
                   try again. */
                csrfPromise = null;
                throw error;
            });
        }
        return csrfPromise;
    }

    function post(url, values) {
        if (!window.fetch) {
            return Promise.resolve();
        }
        return csrf().then(function (info) {
            var body = new URLSearchParams();
            if (info.csrfTokenName && info.csrfTokenValue) {
                body.append(info.csrfTokenName, info.csrfTokenValue);
            }
            Object.keys(values).forEach(function (key) {
                var value = values[key];
                if (Array.isArray(value)) {
                    value.forEach(function (item) {
                        body.append(key + '[]', item);
                    });
                } else {
                    body.append(key, String(value));
                }
            });
            return fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                body: body
            });
        }).catch(function () {
            // Consent itself is local. Logging and aggregate metrics are
            // intentionally best-effort and never block the visitor.
        });
    }

    function log(consent, action) {
        if (!config.logConsents) {
            return;
        }
        post(config.saveUrl, {
            consentId: consent.id,
            revision: consent.v,
            categories: consent.c,
            action: action,
            snapshotHash: config.snapshotHash || '',
            gpc: !!consent.g,
            gpcOverride: !!consent.go,
            locale: config.locale || ''
        });
    }

    function trackBannerView() {
        if (!config.analytics || !window.fetch) {
            return;
        }
        var key = 'cookiekit_view_' + config.revision + '_' + new Date().toISOString().slice(0, 10);
        try {
            if (window.sessionStorage.getItem(key)) {
                return;
            }
            window.sessionStorage.setItem(key, '1');
        } catch (e) {
            // A blocked sessionStorage should not block the banner.
        }
        post(config.trackUrl, {
            event: 'bannerViews',
            gpc: isGpcActive()
        });
    }

    /* Anything carrying data-cookiekit-src gets turned into a live src, and
       that attribute survives most HTML sanitizers untouched. Without this a
       `javascript:` URI pasted into a rich text field would execute in the
       site's own origin the moment `necessary` is granted, which is on the
       very first page load. Only real fetches are allowed through. */
    function isFetchableUrl(value) {
        if (!value) {
            return false;
        }
        var url = String(value).trim();
        if (url.indexOf('//') === 0) {
            return true;
        }
        if (/^https?:/i.test(url)) {
            return true;
        }
        /* Relative paths are fine; a scheme we do not know is not. */
        return !/^[a-z][a-z0-9+.-]*:/i.test(url);
    }

    function unblock(categories, change) {
        var scripts = document.querySelectorAll('script[data-cookiekit]');
        Array.prototype.forEach.call(scripts, function (el) {
            var cat = el.getAttribute('data-cookiekit');
            if (el.getAttribute('type') !== 'text/plain' || categories.indexOf(cat) === -1) {
                return;
            }
            var replacement = document.createElement('script');
            Array.prototype.forEach.call(el.attributes, function (attr) {
                if (attr.name === 'type' || attr.name === 'data-cookiekit') {
                    return;
                }
                if (attr.name === 'data-cookiekit-src') {
                    if (isFetchableUrl(attr.value)) {
                        replacement.src = attr.value;
                    }
                    return;
                }
                if (attr.name === 'data-cookiekit-type') {
                    /* A module recreated as a classic script makes `import` a
                       SyntaxError, so the original type comes back. */
                    replacement.type = attr.value;
                    return;
                }
                if (attr.name === 'data-cookiekit-srcset' || attr.name === 'data-cookiekit-data-src') {
                    return;
                }
                replacement.setAttribute(attr.name, attr.value);
            });
            if (el.nonce) {
                /* The browser blanks the nonce *attribute* once an element is
                   in the document and keeps the value on the property, so
                   copying attributes alone loses it and the replacement is
                   refused under a strict Content-Security-Policy. */
                replacement.nonce = el.nonce;
            }
            replacement.text = el.text || el.textContent;
            el.parentNode.replaceChild(replacement, el);
        });

        var embeds = document.querySelectorAll('[data-cookiekit-src]');
        Array.prototype.forEach.call(embeds, function (el) {
            if (el.tagName === 'SCRIPT') {
                return;
            }
            var cat = el.getAttribute('data-cookiekit');
            if (categories.indexOf(cat) === -1) {
                if (!el.hasAttribute('data-ck-silent')) {
                    addPlaceholder(el, cat);
                } else {
                    el.style.display = 'none';
                }
                return;
            }
            removePlaceholder(el);
            if (el.hasAttribute('data-cookiekit-srcset')) {
                el.setAttribute('srcset', el.getAttribute('data-cookiekit-srcset'));
                el.removeAttribute('data-cookiekit-srcset');
            }
            if (el.hasAttribute('data-cookiekit-data-src')) {
                el.setAttribute('data-src', el.getAttribute('data-cookiekit-data-src'));
                el.removeAttribute('data-cookiekit-data-src');
            }
            if (!el.getAttribute('src') && isFetchableUrl(el.getAttribute('data-cookiekit-src'))) {
                el.setAttribute('src', el.getAttribute('data-cookiekit-src'));
            }
        });

        categories.forEach(function (cat) {
            document.dispatchEvent(new CustomEvent('cookiekit:' + cat));
        });
        document.dispatchEvent(new CustomEvent('cookiekit:consent', {
            detail: {
                categories: categories.slice(),
                action: change ? change.action : null,
                revoked: change ? change.revoked.slice() : [],
                gpc: isGpcActive(),
                gpcOverride: !!(change && change.gpcOverride)
            }
        }));
    }

    function addPlaceholder(el, cat) {
        var prev = el.previousElementSibling;
        if (prev && prev.hasAttribute('data-ck-placeholder')) {
            return;
        }
        var placeholder = document.createElement('div');
        placeholder.setAttribute('data-ck-placeholder', cat || '');
        placeholder.className = 'ck-placeholder';

        var message = document.createElement('p');
        message.textContent = root.getAttribute('data-ck-placeholder-text') ||
            'Accept cookies to view this embedded content.';
        placeholder.appendChild(message);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'ck-btn ck-btn--primary';
        button.textContent = root.getAttribute('data-ck-placeholder-button') || 'Cookie settings';
        button.addEventListener('click', showPanel);
        placeholder.appendChild(button);

        el.style.display = 'none';
        el.parentNode.insertBefore(placeholder, el);
    }

    function removePlaceholder(el) {
        var prev = el.previousElementSibling;
        if (prev && prev.hasAttribute('data-ck-placeholder')) {
            prev.parentNode.removeChild(prev);
        }
        el.style.display = '';
    }

    function gcmUpdate(categories) {
        if (!config.gcm) {
            return;
        }
        window.dataLayer = window.dataLayer || [];
        var granted = function (cat) {
            return categories.indexOf(cat) !== -1 ? 'granted' : 'denied';
        };
        var gtag = function () {
            window.dataLayer.push(arguments);
        };
        gtag('consent', 'update', {
            ad_storage: granted('marketing'),
            ad_user_data: granted('marketing'),
            ad_personalization: granted('marketing'),
            analytics_storage: granted('statistics'),
            functionality_storage: granted('preferences'),
            personalization_storage: granted('preferences'),
            security_storage: 'granted'
        });
    }

    function banner() {
        return root.querySelector('[data-ck-banner]');
    }

    function panel() {
        return root.querySelector('[data-ck-panel]');
    }

    function setGpcMessage() {
        var notices = root.querySelectorAll('[data-ck-gpc]');
        Array.prototype.forEach.call(notices, function (notice) {
            notice.hidden = !isGpcActive();
        });
    }

    function focusFirst(container) {
        var first = container.querySelector(
            '[autofocus],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
        );
        if (first) {
            first.focus();
        }
    }

    function showBanner() {
        root.hidden = false;
        banner().hidden = false;
        panel().hidden = true;
        if (banner().getAttribute('aria-modal') === 'true') {
            openModal();
            focusFirst(banner());
        } else {
            closeModal();
        }
        setGpcMessage();
        trackBannerView();
    }

    function openModal() {
        if (modalLocked) {
            return;
        }
        /* On <html> as well as <body>: body overflow only propagates to the
           viewport while the root element's overflow is visible, so a site
           with `html { overflow-x: hidden }` would keep scrolling behind the
           dialog. The class carries the rule, see cookiekit.css. */
        bodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.documentElement.classList.add('ck-scroll-lock');
        modalLocked = true;
    }

    function closeModal() {
        if (!modalLocked) {
            return;
        }
        document.body.style.overflow = bodyOverflow;
        document.documentElement.classList.remove('ck-scroll-lock');
        modalLocked = false;
    }

    function showPanel() {
        opener = document.activeElement;
        root.hidden = false;
        banner().hidden = true;
        var el = panel();
        el.hidden = false;
        syncCheckboxes();
        setGpcMessage();
        openModal();
        focusFirst(el);
    }

    function hideAll(restoreFocus) {
        closeModal();
        root.hidden = true;
        banner().hidden = true;
        panel().hidden = true;
        if (restoreFocus && opener && typeof opener.focus === 'function') {
            opener.focus();
        }
    }

    function syncCheckboxes() {
        var current = getEffectiveConsent();
        var granted = current ? current.c : ['necessary'];
        var boxes = root.querySelectorAll('input[data-ck-category]');
        Array.prototype.forEach.call(boxes, function (box) {
            var cat = box.getAttribute('data-ck-category');
            if (cat === 'necessary') {
                box.checked = true;
                box.disabled = true;
                return;
            }
            box.checked = granted.indexOf(cat) !== -1;
        });
    }

    function selectedCategories() {
        var categories = ['necessary'];
        var boxes = root.querySelectorAll('input[data-ck-category]');
        Array.prototype.forEach.call(boxes, function (box) {
            var cat = box.getAttribute('data-ck-category');
            if (cat !== 'necessary' && box.checked) {
                categories.push(cat);
            }
        });
        return categories;
    }

    function save(categories, action) {
        if (categories.indexOf('necessary') === -1) {
            categories.unshift('necessary');
        }
        var existing = getConsent();
        var gpc = isGpcActive();
        var consent = {
            id: (existing && existing.id) || uuid(),
            v: config.revision,
            c: categories,
            t: new Date().toISOString(),
            g: gpc,
            go: gpc && categories.indexOf('marketing') !== -1
        };
        writeConsent(consent);
        var effective = effectiveCategories(consent);
        gcmUpdate(effective);
        log(consent, action);

        var revoked = previous.filter(function (cat) {
            return effective.indexOf(cat) === -1;
        });
        previous = effective.slice();
        hideAll(true);

        document.dispatchEvent(new CustomEvent('cookiekit:consent-change', {
            detail: {
                action: action,
                categories: effective.slice(),
                revoked: revoked,
                gpc: gpc,
                gpcOverride: consent.go
            }
        }));

        if (revoked.length > 0) {
            document.dispatchEvent(new CustomEvent('cookiekit:consent', {
                detail: {
                    categories: effective.slice(),
                    action: action,
                    revoked: revoked.slice(),
                    gpc: gpc,
                    gpcOverride: consent.go
                }
            }));
            window.location.reload();
            return;
        }
        unblock(effective, {
            action: action,
            revoked: revoked,
            gpcOverride: consent.go
        });
    }

    function trapFocus(event) {
        var pane = !panel().hidden
            ? panel()
            : (!banner().hidden && banner().getAttribute('aria-modal') === 'true' ? banner() : null);
        if (event.key !== 'Tab' || !pane) {
            return;
        }
        var focusable = pane.querySelectorAll(
            'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
        );
        if (!focusable.length) {
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function wire() {
        root.addEventListener('click', function (event) {
            var target = event.target.closest('[data-ck-action]');
            if (!target) {
                return;
            }
            event.preventDefault();
            switch (target.getAttribute('data-ck-action')) {
                case 'accept-all':
                    save(config.categories.slice(), 'acceptAll');
                    break;
                case 'deny':
                    save(['necessary'], 'denyAll');
                    break;
                case 'customize':
                    showPanel();
                    break;
                case 'save':
                    save(selectedCategories(), getConsent() ? 'changed' : 'custom');
                    break;
                case 'back':
                    showBanner();
                    focusFirst(banner());
                    break;
                case 'close':
                    if (getConsent()) {
                        hideAll(true);
                    } else {
                        showBanner();
                    }
                    break;
            }
        });

        root.addEventListener('keydown', function (event) {
            trapFocus(event);
            if (event.key !== 'Escape') {
                return;
            }
            if (!panel().hidden) {
                if (getConsent()) {
                    hideAll(true);
                } else {
                    showBanner();
                    focusFirst(banner());
                }
            }
        });

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-cookiekit-show]');
            if (trigger) {
                event.preventDefault();
                showPanel();
            }
        });

        var toggles = root.querySelectorAll('[data-ck-toggle-details]');
        Array.prototype.forEach.call(toggles, function (toggle) {
            toggle.addEventListener('click', function () {
                var details = toggle.closest('[data-ck-section]').querySelector('[data-ck-details]');
                var expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                details.hidden = expanded;
            });
        });
    }

    ready(function () {
        root = document.querySelector('[data-cookiekit-root]');
        if (!root) {
            return;
        }
        try {
            config = JSON.parse(root.getAttribute('data-cookiekit-config'));
        } catch (e) {
            return;
        }

        wire();
        var consent = getConsent();
        var effective = consent ? effectiveCategories(consent) : ['necessary'];
        previous = effective.slice();

        if (consent) {
            gcmUpdate(effective);
            unblock(effective);
            if (isGpcActive() && consent.c.indexOf('marketing') !== -1 && !consent.go) {
                showBanner();
            } else {
                hideAll(false);
            }
        } else {
            unblock(['necessary']);
            showBanner();
        }
    });

    window.CookieKit = {
        show: function () {
            if (root) {
                showPanel();
            }
        },
        hide: function () {
            /* Hides, always. Refusing to hide without a stored consent turned
               this into a dead button with nothing to explain it. The "no
               dismissing without a choice" rule lives in the close action. */
            if (root) {
                hideAll(true);
            }
        },
        getConsent: function () {
            return config ? getConsent() : null;
        },
        getEffectiveConsent: function () {
            return config ? getEffectiveConsent() : null;
        },
        hasConsent: function (category) {
            if (category === 'necessary') {
                return true;
            }
            var consent = config ? getEffectiveConsent() : null;
            return !!consent && consent.c.indexOf(category) !== -1;
        },
        isGpcActive: isGpcActive,
        acceptAll: function () {
            if (config) {
                save(config.categories.slice(), 'acceptAll');
            }
        },
        denyAll: function () {
            if (config) {
                save(['necessary'], 'denyAll');
            }
        },
        withdraw: function () {
            if (config) {
                save(['necessary'], 'withdrawn');
            }
        }
    };
})();
