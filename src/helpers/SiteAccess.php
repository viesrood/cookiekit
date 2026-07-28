<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

use Craft;
use craft\elements\User;
use craft\models\Site;

/**
 * Which sites the person looking at a control panel screen may see.
 *
 * The consent log and the dashboard both take a `siteId` from the query string.
 * Without a check, an editor limited to one site could read another site's
 * receipts and numbers by typing a different id in the address bar. Craft
 * already models this as the `viewSite:<uid>` permission, so use that rather
 * than inventing a second idea of who may see what.
 */
final class SiteAccess
{
    /**
     * @return list<Site>
     */
    public static function allowedSites(): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        $sites = Craft::$app->getSites()->getAllSites();

        if (!$user instanceof User) {
            return [];
        }

        return array_values(array_filter(
            $sites,
            static fn(Site $site): bool => $user->can('viewSite:' . $site->uid),
        ));
    }

    /**
     * The requested site if it is allowed, otherwise the first one that is.
     *
     * Falling back rather than throwing keeps a bookmarked link with a stale
     * site id usable, while still never showing data the viewer may not see.
     */
    public static function resolve(mixed $requestedSiteId): ?Site
    {
        $allowed = self::allowedSites();

        if ($allowed === []) {
            return null;
        }

        if (is_numeric($requestedSiteId)) {
            foreach ($allowed as $site) {
                if ($site->id === (int)$requestedSiteId) {
                    return $site;
                }
            }
        }

        $current = Craft::$app->getSites()->getCurrentSite();

        foreach ($allowed as $site) {
            if ($site->id === $current->id) {
                return $site;
            }
        }

        return $allowed[0];
    }

    /**
     * The id to filter on, or null when every allowed site should be included.
     *
     * @return int|null
     */
    public static function filterId(mixed $requestedSiteId): ?int
    {
        if (!is_numeric($requestedSiteId)) {
            return null;
        }

        $site = self::resolve($requestedSiteId);

        return $site?->id;
    }
}
