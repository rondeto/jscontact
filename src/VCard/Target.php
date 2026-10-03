<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard;

use Rondeto\JSContact\VCard\Dialect\Android;
use Rondeto\JSContact\VCard\Dialect\Apple;
use Rondeto\JSContact\VCard\Dialect\Dialect;
use Rondeto\JSContact\VCard\Dialect\LegacyMessaging;

/**
 * What VCardEncoder writes for: a vCard version, and the dialects of the address book the
 * vCard is for.
 *
 * Dialects write in the order given, each rewriting what the previous ones left: with
 * LegacyMessaging before Apple, an AIM user name is written as X-AIM, not as Apple's
 * X-SOCIALPROFILE.
 */
final readonly class Target
{
    /**
     * @param list<Dialect> $dialects
     */
    public function __construct(
        public VCardVersion $version = VCardVersion::V40,
        public array $dialects = [],
    ) {
    }

    /**
     * Apple Contacts and iCloud, which import vCard 3.0. Also fits Google Contacts, which
     * exports the same properties.
     */
    public static function apple(): self
    {
        return new self(VCardVersion::V30, [new LegacyMessaging(), new Apple()]);
    }

    /**
     * The Android contacts app. It exports vCard 2.1, which VCardEncoder does not write:
     * vCard 3.0 is the closest.
     */
    public static function android(): self
    {
        return new self(VCardVersion::V30, [new LegacyMessaging(), new Android()]);
    }
}
