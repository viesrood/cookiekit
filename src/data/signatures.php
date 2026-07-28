<?php

/**
 * The shipped signature database.
 *
 * Every entry maps a third party to the resources that give it away and the
 * cookies it is known to set. The scan uses this in two directions:
 *
 * - a resource on one of these hosts means the vendor is present, and its
 *   cookies become *inferred* findings (we did not observe them, we know the
 *   script that sets them is loaded)
 * - an observed cookie name matched here gets a provider, a category, a
 *   purpose and an expiry, which is what makes automatic import defensible
 *
 * `hosts` accepts a leading `*.` wildcard. `paths` and `inline` are full PCRE
 * patterns, delimiters included, so they can be copied straight into a test.
 *
 * If a signature declares `paths`, a host match without a path match counts for
 * nothing. That is how GA4 and Tag Manager stay apart on googletagmanager.com.
 *
 * `blockAs` is the category the resource should be blocked under. A value of
 * `necessary` means it needs no blocking, so it never raises an "unblocked"
 * finding. `container` marks a tag manager: we can see it is there, never what
 * is configured inside it.
 *
 * Projects can add to, override or remove entries in `config/cookiekit-signatures.php`
 * or through SignatureService::EVENT_REGISTER_SIGNATURES.
 *
 * @see \viesrood\cookiekit\services\SignatureService
 * @see \viesrood\cookiekit\helpers\SignatureMatcher
 */

declare(strict_types=1);

return [
    // ---------------------------------------------------------------- first party

    'craft-cms' => [
        'label' => 'Craft CMS',
        'provider' => 'This website',
        'category' => 'necessary',
        'container' => false,
        'blockAs' => 'necessary',
        'hosts' => [],
        'paths' => [],
        'inline' => [],
        'cookies' => [
            [
                'name' => 'CraftSessionId',
                'match' => 'exact',
                'declaredAs' => 'CraftSessionId',
                'category' => 'necessary',
                'duration' => 'Session',
                'purpose' => 'Keeps track of your session so the website can remember what you do from page to page.',
            ],
            [
                'name' => 'CRAFT_CSRF_TOKEN',
                'match' => 'exact',
                'declaredAs' => 'CRAFT_CSRF_TOKEN',
                'category' => 'necessary',
                'duration' => 'Session',
                'purpose' => 'Protects forms on this website against cross-site request forgery.',
            ],
        ],
        'storage' => [],
    ],

    'cookiekit' => [
        'label' => 'CookieKit',
        'provider' => 'This website',
        'category' => 'necessary',
        'container' => false,
        'blockAs' => 'necessary',
        'hosts' => [],
        'paths' => [],
        'inline' => [],
        'cookies' => [
            [
                'name' => 'cookiekit_consent',
                'match' => 'exact',
                'declaredAs' => 'cookiekit_consent',
                'category' => 'necessary',
                'duration' => '1 year',
                'purpose' => 'Stores which cookie categories you accepted, so you are not asked again on every page.',
            ],
        ],
        'storage' => [],
    ],

    // ---------------------------------------------------------------- google

    'google-analytics-4' => [
        'label' => 'Google Analytics 4',
        'provider' => 'Google Ireland Ltd.',
        'category' => 'statistics',
        'container' => false,
        'blockAs' => 'statistics',
        'hosts' => [
            'www.googletagmanager.com',
            'www.google-analytics.com',
            'ssl.google-analytics.com',
            'analytics.google.com',
            '*.analytics.google.com',
        ],
        'paths' => [
            '#^/gtag/js#',
            '#^/g/collect#',
            '#^/j/collect#',
            '#^/collect#',
            '#^/analytics\.js#',
            '#^/ga\.js#',
        ],
        'inline' => [
            '/gtag\s*\(\s*[\'"]config[\'"]\s*,\s*[\'"]G-[A-Z0-9]+/i',
            '/gtag\s*\(\s*[\'"]js[\'"]\s*,\s*new\s+Date/i',
        ],
        'cookies' => [
            [
                'name' => '_ga',
                'match' => 'exact',
                'declaredAs' => '_ga',
                'category' => 'statistics',
                'duration' => '2 years',
                'purpose' => 'Distinguishes visitors by assigning a randomly generated number as an identifier.',
            ],
            [
                'name' => '_ga_',
                'match' => 'prefix',
                'declaredAs' => '_ga_*',
                'category' => 'statistics',
                'duration' => '2 years',
                'purpose' => 'Keeps the session state for one Google Analytics 4 property.',
            ],
            [
                'name' => '_gid',
                'match' => 'exact',
                'declaredAs' => '_gid',
                'category' => 'statistics',
                'duration' => '1 day',
                'purpose' => 'Distinguishes visitors for the duration of a day.',
            ],
            [
                'name' => '_gat',
                'match' => 'prefix',
                'declaredAs' => '_gat*',
                'category' => 'statistics',
                'duration' => '1 minute',
                'purpose' => 'Throttles the number of requests sent to Google Analytics.',
            ],
            [
                'name' => '_gac_',
                'match' => 'prefix',
                'declaredAs' => '_gac_*',
                'category' => 'marketing',
                'duration' => '3 months',
                'purpose' => 'Holds campaign information for the visitor, shared with Google Ads.',
            ],
        ],
        'storage' => [],
    ],

    'google-tag-manager' => [
        'label' => 'Google Tag Manager',
        'provider' => 'Google Ireland Ltd.',
        'category' => 'statistics',
        // A container: the tags inside it live in the GTM interface, not in
        // this website's HTML, so no scan can enumerate them.
        'container' => true,
        'blockAs' => 'statistics',
        'hosts' => ['www.googletagmanager.com'],
        'paths' => ['#^/gtm\.js#', '#^/ns\.html#'],
        // Deliberately not `dataLayer.push(`: the standard gtag bootstrap
        // defines `function gtag(){dataLayer.push(arguments);}`, so that
        // pattern fires on every site running plain Analytics and sends you
        // hunting for a container that does not exist. A real container always
        // gives itself away by its GTM- id or its loader URL.
        'inline' => ['/GTM-[A-Z0-9]{4,}/'],
        'cookies' => [],
        'storage' => [],
    ],

    'google-ads' => [
        'label' => 'Google Ads',
        'provider' => 'Google Ireland Ltd.',
        'category' => 'marketing',
        'container' => false,
        'blockAs' => 'marketing',
        'hosts' => [
            'www.googleadservices.com',
            'googleads.g.doubleclick.net',
            'stats.g.doubleclick.net',
            'td.doubleclick.net',
            '*.doubleclick.net',
            'pagead2.googlesyndication.com',
            '*.googlesyndication.com',
        ],
        'paths' => [],
        'inline' => ['/AW-[0-9]{6,}/', '/gtag\s*\(\s*[\'"]config[\'"]\s*,\s*[\'"]AW-/i'],
        'cookies' => [
            [
                'name' => '_gcl_au',
                'match' => 'exact',
                'declaredAs' => '_gcl_au',
                'category' => 'marketing',
                'duration' => '3 months',
                'purpose' => 'Used by the Google Ads conversion linker to measure how effective an ad click was.',
            ],
            [
                'name' => '_gcl_',
                'match' => 'prefix',
                'declaredAs' => '_gcl_*',
                'category' => 'marketing',
                'duration' => '3 months',
                'purpose' => 'Stores Google Ads click information for conversion attribution.',
            ],
            [
                'name' => 'IDE',
                'match' => 'exact',
                'declaredAs' => 'IDE',
                'category' => 'marketing',
                'duration' => '13 months',
                'purpose' => 'Registers and reports your actions after viewing or clicking an advertisement.',
            ],
            [
                'name' => 'test_cookie',
                'match' => 'exact',
                'declaredAs' => 'test_cookie',
                'category' => 'marketing',
                'duration' => '15 minutes',
                'purpose' => 'Checks whether your browser accepts cookies at all.',
            ],
        ],
        'storage' => [],
    ],

    'google-maps' => [
        'label' => 'Google Maps',
        'provider' => 'Google Ireland Ltd.',
        'category' => 'preferences',
        'container' => false,
        'blockAs' => 'preferences',
        'hosts' => ['maps.googleapis.com', 'maps.google.com', 'maps.gstatic.com', 'www.google.com'],
        'paths' => ['#^/maps#', '#^/maps/embed#'],
        'inline' => ['#maps\.googleapis\.com/maps/api#'],
        'cookies' => [
            [
                'name' => 'NID',
                'match' => 'exact',
                'declaredAs' => 'NID',
                'category' => 'marketing',
                'duration' => '6 months',
                'purpose' => 'Stores your Google preferences and can be used to personalise advertising.',
            ],
        ],
        'storage' => [],
    ],

    'google-recaptcha' => [
        'label' => 'Google reCAPTCHA',
        'provider' => 'Google Ireland Ltd.',
        'category' => 'necessary',
        'container' => false,
        // Spam and abuse protection on your own forms: needs no consent, and
        // therefore never raises an "unblocked" finding.
        'blockAs' => 'necessary',
        'hosts' => ['www.google.com', 'www.gstatic.com', 'www.recaptcha.net', 'recaptcha.net'],
        'paths' => ['#^/recaptcha/#'],
        'inline' => ['/\bgrecaptcha\s*\./'],
        'cookies' => [
            [
                'name' => '_GRECAPTCHA',
                'match' => 'exact',
                'declaredAs' => '_GRECAPTCHA',
                'category' => 'necessary',
                'duration' => '6 months',
                'purpose' => 'Used by reCAPTCHA to tell humans and bots apart when a form is submitted.',
            ],
        ],
        'storage' => [],
    ],

    'google-fonts' => [
        'label' => 'Google Fonts',
        'provider' => 'Google Ireland Ltd.',
        'category' => 'necessary',
        'container' => false,
        'blockAs' => 'necessary',
        'hosts' => ['fonts.googleapis.com', 'fonts.gstatic.com'],
        'paths' => [],
        'inline' => [],
        // Sets no cookies, but every font request still sends the visitor's IP
        // address to Google. Reported as a third-party transfer, not as consent.
        'cookies' => [],
        'storage' => [],
    ],

    // ---------------------------------------------------------------- social and embeds

    'facebook-pixel' => [
        'label' => 'Meta Pixel',
        'provider' => 'Meta Platforms Ireland Ltd.',
        'category' => 'marketing',
        'container' => false,
        'blockAs' => 'marketing',
        'hosts' => ['connect.facebook.net', 'www.facebook.com', 'web.facebook.com', '*.facebook.com'],
        'paths' => ['#/[a-z]{2}_[A-Z]{2}/fbevents\.js#', '#^/tr(\?|/|$)#', '#^/signals/config/#', '#^/plugins/#'],
        'inline' => [
            '/\bfbq\s*\(\s*[\'"]init[\'"]/',
            '#connect\.facebook\.net/[a-z]{2}_[A-Z]{2}/fbevents\.js#',
        ],
        'cookies' => [
            [
                'name' => '_fbp',
                'match' => 'exact',
                'declaredAs' => '_fbp',
                'category' => 'marketing',
                'duration' => '3 months',
                'purpose' => 'Identifies browsers for advertising and site analytics by Meta.',
            ],
            [
                'name' => '_fbc',
                'match' => 'exact',
                'declaredAs' => '_fbc',
                'category' => 'marketing',
                'duration' => '3 months',
                'purpose' => 'Stores the last advertisement click, so a conversion can be attributed to it.',
            ],
            [
                'name' => 'fr',
                'match' => 'exact',
                'declaredAs' => 'fr',
                'category' => 'marketing',
                'duration' => '3 months',
                'purpose' => 'Used by Meta to deliver, measure and improve the relevance of advertisements.',
            ],
        ],
        'storage' => [],
    ],

    'youtube' => [
        'label' => 'YouTube',
        'provider' => 'Google Ireland Ltd.',
        'category' => 'marketing',
        'container' => false,
        'blockAs' => 'marketing',
        'hosts' => [
            'www.youtube.com',
            'youtube.com',
            'www.youtube-nocookie.com',
            'youtube-nocookie.com',
            's.ytimg.com',
            'i.ytimg.com',
        ],
        'paths' => [],
        'inline' => ['#youtube\.com/iframe_api#', '/\bYT\.Player\b/'],
        'cookies' => [
            [
                'name' => 'VISITOR_INFO1_LIVE',
                'match' => 'exact',
                'declaredAs' => 'VISITOR_INFO1_LIVE',
                'category' => 'marketing',
                'duration' => '6 months',
                'purpose' => 'Estimates your bandwidth on pages with an embedded YouTube video.',
            ],
            [
                'name' => 'VISITOR_PRIVACY_METADATA',
                'match' => 'exact',
                'declaredAs' => 'VISITOR_PRIVACY_METADATA',
                'category' => 'marketing',
                'duration' => '6 months',
                'purpose' => 'Stores your cookie choice for the YouTube domain.',
            ],
            [
                'name' => 'YSC',
                'match' => 'exact',
                'declaredAs' => 'YSC',
                'category' => 'marketing',
                'duration' => 'Session',
                'purpose' => 'Registers a unique id to keep statistics of which videos you have watched.',
            ],
            [
                'name' => '__Secure-YEC',
                'match' => 'exact',
                'declaredAs' => '__Secure-YEC',
                'category' => 'marketing',
                'duration' => '13 months',
                'purpose' => 'Stores your YouTube player preferences and consent state.',
            ],
        ],
        'storage' => [
            [
                'key' => 'yt-remote-',
                'match' => 'prefix',
                'type' => 'local',
                'category' => 'marketing',
                'purpose' => 'Stores the connection state of the YouTube player.',
            ],
            [
                'key' => 'yt-player-',
                'match' => 'prefix',
                'type' => 'local',
                'category' => 'marketing',
                'purpose' => 'Stores your YouTube playback preferences.',
            ],
            [
                'key' => 'yt-remote-session-app',
                'match' => 'exact',
                'type' => 'session',
                'category' => 'marketing',
                'purpose' => 'Session state of the YouTube player.',
            ],
        ],
    ],

    'vimeo' => [
        'label' => 'Vimeo',
        'provider' => 'Vimeo Inc.',
        'category' => 'marketing',
        'container' => false,
        'blockAs' => 'marketing',
        'hosts' => ['player.vimeo.com', 'vimeo.com', 'f.vimeocdn.com', '*.vimeocdn.com'],
        'paths' => [],
        'inline' => ['#player\.vimeo\.com/api#'],
        'cookies' => [
            [
                'name' => 'vuid',
                'match' => 'exact',
                'declaredAs' => 'vuid',
                'category' => 'statistics',
                'duration' => '2 years',
                'purpose' => 'Collects viewing statistics for videos embedded from Vimeo.',
            ],
            [
                'name' => 'player',
                'match' => 'exact',
                'declaredAs' => 'player',
                'category' => 'preferences',
                'duration' => '1 year',
                'purpose' => 'Remembers your player settings, such as volume and quality.',
            ],
        ],
        'storage' => [],
    ],

    'instagram' => [
        'label' => 'Instagram',
        'provider' => 'Meta Platforms Ireland Ltd.',
        'category' => 'marketing',
        'container' => false,
        'blockAs' => 'marketing',
        'hosts' => ['www.instagram.com', 'instagram.com', '*.cdninstagram.com'],
        'paths' => [],
        'inline' => ['#instagram\.com/embed\.js#'],
        'cookies' => [
            [
                'name' => 'mid',
                'match' => 'exact',
                'declaredAs' => 'mid',
                'category' => 'marketing',
                'duration' => '2 years',
                'purpose' => 'Identifies your browser across Instagram embeds.',
            ],
            [
                'name' => 'ig_did',
                'match' => 'exact',
                'declaredAs' => 'ig_did',
                'category' => 'marketing',
                'duration' => '2 years',
                'purpose' => 'Registers a unique device id for Instagram.',
            ],
        ],
        'storage' => [],
    ],

    'linkedin' => [
        'label' => 'LinkedIn',
        'provider' => 'LinkedIn Ireland Unlimited Company',
        'category' => 'marketing',
        'container' => false,
        'blockAs' => 'marketing',
        'hosts' => ['platform.linkedin.com', 'snap.licdn.com', 'www.linkedin.com', 'px.ads.linkedin.com'],
        'paths' => [],
        'inline' => ['/_linkedin_partner_id/'],
        'cookies' => [
            [
                'name' => 'bcookie',
                'match' => 'exact',
                'declaredAs' => 'bcookie',
                'category' => 'marketing',
                'duration' => '1 year',
                'purpose' => 'Browser id cookie used by LinkedIn to recognise devices.',
            ],
            [
                'name' => 'lidc',
                'match' => 'exact',
                'declaredAs' => 'lidc',
                'category' => 'marketing',
                'duration' => '1 day',
                'purpose' => 'Used by LinkedIn for routing and to register which of its servers served you.',
            ],
            [
                'name' => 'UserMatchHistory',
                'match' => 'exact',
                'declaredAs' => 'UserMatchHistory',
                'category' => 'marketing',
                'duration' => '30 days',
                'purpose' => 'Synchronises the LinkedIn advertising id for retargeting.',
            ],
            [
                'name' => 'AnalyticsSyncHistory',
                'match' => 'exact',
                'declaredAs' => 'AnalyticsSyncHistory',
                'category' => 'marketing',
                'duration' => '30 days',
                'purpose' => 'Records when you were synchronised with LinkedIn analytics.',
            ],
            [
                'name' => 'li_sugr',
                'match' => 'exact',
                'declaredAs' => 'li_sugr',
                'category' => 'marketing',
                'duration' => '3 months',
                'purpose' => 'Used by LinkedIn to make a probabilistic match of your identity.',
            ],
        ],
        'storage' => [],
    ],

    'spotify' => [
        'label' => 'Spotify',
        'provider' => 'Spotify AB',
        'category' => 'marketing',
        'container' => false,
        'blockAs' => 'marketing',
        'hosts' => ['open.spotify.com', 'embed.spotify.com', 'embed-podcast.spotify.com'],
        'paths' => [],
        'inline' => [],
        'cookies' => [
            [
                'name' => 'sp_t',
                'match' => 'exact',
                'declaredAs' => 'sp_t',
                'category' => 'marketing',
                'duration' => '1 year',
                'purpose' => 'Identifies your browser for the embedded Spotify player.',
            ],
            [
                'name' => 'sp_landing',
                'match' => 'exact',
                'declaredAs' => 'sp_landing',
                'category' => 'marketing',
                'duration' => '1 day',
                'purpose' => 'Registers which page you arrived on before opening the Spotify player.',
            ],
        ],
        'storage' => [],
    ],

    'pinterest' => [
        'label' => 'Pinterest',
        'provider' => 'Pinterest Europe Ltd.',
        'category' => 'marketing',
        'container' => false,
        'blockAs' => 'marketing',
        'hosts' => ['assets.pinterest.com', 'ct.pinterest.com', 'www.pinterest.com', 's.pinimg.com'],
        'paths' => [],
        'inline' => ['/\bpintrk\s*\(/'],
        'cookies' => [
            [
                'name' => '_pinterest_ct_ua',
                'match' => 'exact',
                'declaredAs' => '_pinterest_ct_ua',
                'category' => 'marketing',
                'duration' => '1 year',
                'purpose' => 'Used by Pinterest to measure conversions across websites.',
            ],
            [
                'name' => '_pin_unauth',
                'match' => 'exact',
                'declaredAs' => '_pin_unauth',
                'category' => 'marketing',
                'duration' => '1 year',
                'purpose' => 'Groups actions of visitors who are not logged in to Pinterest.',
            ],
        ],
        'storage' => [],
    ],

    // ---------------------------------------------------------------- analytics and tooling

    'hotjar' => [
        'label' => 'Hotjar',
        'provider' => 'Hotjar Ltd.',
        'category' => 'statistics',
        'container' => false,
        'blockAs' => 'statistics',
        'hosts' => ['static.hotjar.com', 'script.hotjar.com', 'vars.hotjar.com', '*.hotjar.com'],
        'paths' => [],
        'inline' => ['/\bhjid\s*:/', '/\b_hjSettings\b/'],
        'cookies' => [
            [
                'name' => '_hjSessionUser_',
                'match' => 'prefix',
                'declaredAs' => '_hjSessionUser_*',
                'category' => 'statistics',
                'duration' => '1 year',
                'purpose' => 'Stores a Hotjar user id so repeat visits are recognised as the same visitor.',
            ],
            [
                'name' => '_hjSession_',
                'match' => 'prefix',
                'declaredAs' => '_hjSession_*',
                'category' => 'statistics',
                'duration' => '30 minutes',
                'purpose' => 'Holds the current Hotjar session.',
            ],
            [
                'name' => '_hjFirstSeen',
                'match' => 'exact',
                'declaredAs' => '_hjFirstSeen',
                'category' => 'statistics',
                'duration' => 'Session',
                'purpose' => 'Registers whether this is your first Hotjar session.',
            ],
            [
                'name' => '_hj',
                'match' => 'prefix',
                'declaredAs' => '_hj*',
                'category' => 'statistics',
                'duration' => 'Session',
                'purpose' => 'Supporting cookie for Hotjar session recording and sampling.',
            ],
        ],
        'storage' => [],
    ],

    'microsoft-clarity' => [
        'label' => 'Microsoft Clarity',
        'provider' => 'Microsoft Ireland Operations Ltd.',
        'category' => 'statistics',
        'container' => false,
        'blockAs' => 'statistics',
        'hosts' => ['www.clarity.ms', '*.clarity.ms'],
        'paths' => [],
        'inline' => ['/\bclarity\s*\(/'],
        'cookies' => [
            [
                'name' => '_clck',
                'match' => 'exact',
                'declaredAs' => '_clck',
                'category' => 'statistics',
                'duration' => '1 year',
                'purpose' => 'Keeps a Clarity user id so behaviour across pages can be attributed to one visitor.',
            ],
            [
                'name' => '_clsk',
                'match' => 'exact',
                'declaredAs' => '_clsk',
                'category' => 'statistics',
                'duration' => '1 day',
                'purpose' => 'Combines several page views into one Clarity session recording.',
            ],
            [
                'name' => 'CLID',
                'match' => 'exact',
                'declaredAs' => 'CLID',
                'category' => 'statistics',
                'duration' => '1 year',
                'purpose' => 'Identifies the first time Clarity saw this browser.',
            ],
            [
                'name' => 'MUID',
                'match' => 'exact',
                'declaredAs' => 'MUID',
                'category' => 'marketing',
                'duration' => '1 year',
                'purpose' => 'Microsoft browser id, also used for advertising and analytics.',
            ],
        ],
        'storage' => [],
    ],

    'adobe-typekit' => [
        'label' => 'Adobe Fonts (Typekit)',
        'provider' => 'Adobe Systems Software Ireland Ltd.',
        'category' => 'necessary',
        'container' => false,
        'blockAs' => 'necessary',
        'hosts' => ['use.typekit.net', 'p.typekit.net'],
        'paths' => [],
        'inline' => [],
        'cookies' => [],
        'storage' => [],
    ],

    'eventix' => [
        'label' => 'Eventix',
        'provider' => 'Eventix B.V.',
        'category' => 'preferences',
        'container' => false,
        'blockAs' => 'preferences',
        'hosts' => ['shop.eventix.io', '*.eventix.io'],
        'paths' => [],
        'inline' => [],
        // The ticket shop runs in its own frame; which cookies it sets there
        // is not visible from this website's HTML.
        'cookies' => [],
        'storage' => [],
    ],

    'cookiefirst' => [
        'label' => 'CookieFirst',
        'provider' => 'Digital Data Solutions B.V.',
        'category' => 'necessary',
        'container' => false,
        'blockAs' => 'necessary',
        'hosts' => ['consent.cookiefirst.com', 'cookiefirst.com', '*.cookiefirst.com'],
        'paths' => [],
        'inline' => [],
        'cookies' => [
            [
                'name' => 'cookiefirst-consent',
                'match' => 'exact',
                'declaredAs' => 'cookiefirst-consent',
                'category' => 'necessary',
                'duration' => '1 year',
                'purpose' => 'Consent state of the CookieFirst banner. Seeing this alongside CookieKit means two consent managers are running on the same website.',
            ],
        ],
        'storage' => [],
    ],
];
