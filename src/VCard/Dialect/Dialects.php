<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Dialect;

/**
 * The dialects of this library.
 */
final class Dialects
{
    /**
     * Every dialect, for VCardDecoder: each reads only its own vendor properties, so reading
     * with all of them is safe when the address book a vCard comes from is unknown.
     *
     * @return list<Dialect>
     */
    public static function all(): array
    {
        return [new LegacyMessaging(), new Apple(), new Android()];
    }
}
