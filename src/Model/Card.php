<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * A JSContact Card (RFC 9553, section 2, as updated by RFC 9982).
 *
 * Maps keyed by Id (emails, phones…) keep their keys: they identify an entry across
 * versions of the same Card. Their keys are strings, but PHP turns numeric ones ("1") into
 * integers. Sets (members, keywords) are lists of their keys.
 *
 * Properties this library does not model yet, unknown properties and vendor-specific
 * properties are kept verbatim in $extra, so that nothing is lost.
 */
final readonly class Card
{
    public const string KIND_INDIVIDUAL = 'individual';

    public const string KIND_GROUP = 'group';

    public const string KIND_ORG = 'org';

    public const string KIND_LOCATION = 'location';

    public const string KIND_DEVICE = 'device';

    public const string KIND_APPLICATION = 'application';

    /**
     * @param string|null                     $kind           null means "individual", the default
     * @param list<string>                    $members        The uid of each member of this group Card
     * @param array<array-key, Nickname>      $nicknames
     * @param array<array-key, EmailAddress>  $emails
     * @param array<array-key, Phone>         $phones
     * @param array<array-key, Address>       $addresses
     * @param array<array-key, OnlineService> $onlineServices
     * @param array<array-key, Link>          $links
     * @param array<array-key, Note>          $notes
     * @param list<string>                    $keywords
     * @param array<array-key, mixed>         $extra          Other properties, as JSON values
     */
    public function __construct(
        public ?string $uid = null,
        public ?string $prodId = null,
        public ?\DateTimeImmutable $created = null,
        public ?\DateTimeImmutable $updated = null,
        public ?string $kind = null,
        public ?string $language = null,
        public array $members = [],
        public ?Name $name = null,
        public array $nicknames = [],
        public array $emails = [],
        public array $phones = [],
        public array $addresses = [],
        public array $onlineServices = [],
        public array $links = [],
        public array $notes = [],
        public array $keywords = [],
        public array $extra = [],
    ) {
    }
}
