<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation;

/**
 * Registered JSContact names this library knows about (RFC 9553, section 3; RFC 9555, section 4).
 *
 * @internal
 */
final class Registry
{
    /**
     * Card properties that are registered but not modeled yet: they are kept verbatim in
     * Card::$extra.
     */
    public const array UNMODELED_CARD_PROPERTIES = [
        'relatedTo', 'speakToAs', 'preferredLanguages', 'calendars',
        'schedulingAddresses', 'cryptoKeys', 'directories', 'media', 'localizations', 'personalInfo',
    ];

    /**
     * Registered properties that every object type may hold but that are not modeled yet.
     */
    public const array UNMODELED_COMMON_PROPERTIES = [];

    public const array CARD_KINDS = ['individual', 'group', 'org', 'location', 'device', 'application'];

    public const array CONTEXTS = ['private', 'work'];

    public const array ADDRESS_CONTEXTS = ['private', 'work', 'billing', 'delivery'];

    public const array PHONE_FEATURES = ['mobile', 'voice', 'text', 'video', 'main-number', 'textphone', 'fax', 'pager'];

    public const array LINK_KINDS = ['contact'];

    public const array TITLE_KINDS = ['title', 'role'];

    public const array ANNIVERSARY_KINDS = ['birth', 'death', 'wedding'];

    public const array PHONETIC_SYSTEMS = ['ipa', 'jyut', 'piny'];

    public const array NAME_COMPONENT_KINDS = ['title', 'given', 'given2', 'surname', 'surname2', 'credential', 'generation', 'separator'];

    public const array ADDRESS_COMPONENT_KINDS = [
        'room', 'apartment', 'floor', 'building', 'number', 'name', 'block', 'subdistrict', 'district',
        'locality', 'region', 'postcode', 'country', 'direction', 'landmark', 'postOfficeBox', 'separator',
    ];

    private function __construct()
    {
    }
}
