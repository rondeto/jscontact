<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Dialect;

use Rondeto\JSContact\VCard\Dialect\Internal\VCardEdit;
use Sabre\VObject\Component\VCard;

/**
 * The instant messaging properties address books wrote before IMPP (RFC 4770), such as
 * X-AIM or X-JABBER, and still write: Android, Google Contacts, Evolution, KDE, Apple.
 *
 * Their value is a user name, not a URI: reading rewrites X-AIM:jdoe as
 * SOCIALPROFILE;VALUE=text;SERVICE-TYPE=AIM:jdoe (RFC 9554), an online service with a
 * service and a user. Writing does the reverse for the services below. The service names
 * are those Apple writes in X-SERVICE-TYPE.
 */
final readonly class LegacyMessaging implements Dialect
{
    /** Properties and the service they name. */
    private const array SERVICES = [
        'X-AIM' => 'AIM', 'X-GADUGADU' => 'GaduGadu', 'X-GOOGLE-TALK' => 'GoogleTalk', 'X-GTALK' => 'GoogleTalk',
        'X-ICQ' => 'ICQ', 'X-JABBER' => 'Jabber', 'X-MSN' => 'MSN', 'X-QQ' => 'QQ', 'X-SIP' => 'SIP',
        'X-SKYPE' => 'Skype', 'X-SKYPE-USERNAME' => 'Skype', 'X-YAHOO' => 'Yahoo',
    ];

    /** Services and the property written for them, where several are read. */
    private const array WRITTEN = ['googletalk' => 'X-GOOGLE-TALK', 'skype' => 'X-SKYPE'];

    public function read(VCard $vCard): array
    {
        foreach (VCardEdit::properties($vCard) as $property) {
            $service = self::SERVICES[(string) $property->name] ?? null;
            $user = trim((string) $property);
            if (null === $service || '' === $user) {
                continue;
            }

            $params = VCardEdit::parameters($property, ['VALUE', 'CHARSET']);
            VCardEdit::replace($vCard, $property, 'SOCIALPROFILE', $user, [...$params, 'VALUE' => ['text'], 'SERVICE-TYPE' => [$service]]);
        }

        return [];
    }

    public function write(VCard $vCard): array
    {
        $names = [];
        foreach (self::SERVICES as $name => $service) {
            $names[strtolower($service)] ??= self::WRITTEN[strtolower($service)] ?? $name;
        }

        foreach (VCardEdit::properties($vCard) as $property) {
            $service = strtolower(VCardEdit::parts($property, 'SERVICE-TYPE')[0] ?? '');
            $isUser = 'text' === strtolower(VCardEdit::parts($property, 'VALUE')[0] ?? '');
            if ('SOCIALPROFILE' === $property->name && $isUser && isset($names[$service])) {
                VCardEdit::replace($vCard, $property, $names[$service], (string) $property, VCardEdit::parameters($property, ['VALUE', 'SERVICE-TYPE']));
            }
        }

        return [];
    }
}
