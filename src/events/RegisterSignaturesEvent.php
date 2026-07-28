<?php

declare(strict_types=1);

namespace viesrood\cookiekit\events;

use yii\base\Event;

/**
 * Raised so modules can add, override or remove vendor signatures.
 *
 * Setting a key to `false` removes a shipped signature. Anything else is
 * merged over the shipped entry, so a handler only has to supply what differs.
 *
 * @phpstan-import-type VendorSignature from \viesrood\cookiekit\helpers\SignatureMatcher
 */
class RegisterSignaturesEvent extends Event
{
    /**
     * @var array<string, VendorSignature|array<string, mixed>|false>
     */
    public array $signatures = [];
}
