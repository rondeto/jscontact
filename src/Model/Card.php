<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

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
#[Constraint\GroupMembers]
#[Constraint\TitleOrganizations]
#[Constraint\Localizations]
#[Constraint\ExtraProperties(Registry::UNMODELED_CARD_PROPERTIES)]
final readonly class Card
{
    public const string KIND_INDIVIDUAL = 'individual';

    public const string KIND_GROUP = 'group';

    public const string KIND_ORG = 'org';

    public const string KIND_LOCATION = 'location';

    public const string KIND_DEVICE = 'device';

    public const string KIND_APPLICATION = 'application';

    /**
     * @param string|null                         $kind                null means "individual", the default
     * @param list<string>                        $members             The uid of each member of this group Card
     * @param array<array-key, Nickname>          $nicknames
     * @param array<array-key, Organization>      $organizations
     * @param array<array-key, Title>             $titles
     * @param array<array-key, Anniversary>       $anniversaries
     * @param array<array-key, Media>             $media
     * @param array<array-key, CryptoKey>         $cryptoKeys
     * @param array<array-key, Directory>         $directories
     * @param array<array-key, Calendar>          $calendars
     * @param array<array-key, SchedulingAddress> $schedulingAddresses
     * @param array<array-key, LanguagePref>      $preferredLanguages
     * @param array<array-key, Relation>          $relatedTo           The uid of each related Card, and how it relates
     * @param array<array-key, PersonalInfo>      $personalInfo
     * @param array<array-key, PatchObject>       $localizations       The Card in other languages, by language tag (RFC 9553, section 2.7.1); see Localizer
     * @param array<array-key, EmailAddress>      $emails
     * @param array<array-key, Phone>             $phones
     * @param array<array-key, Address>           $addresses
     * @param array<array-key, OnlineService>     $onlineServices
     * @param array<array-key, Link>              $links
     * @param array<array-key, Note>              $notes
     * @param list<string>                        $keywords
     * @param string|null                         $vCardName           The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>>  $vCardParams         vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param list<VCardProperty>                 $vCardProps          vCard properties this Card has no property for (RFC 9555, section 2.15.1)
     * @param array<array-key, mixed>             $extra               Other properties, as JSON values
     */
    public function __construct(
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $uid = null,
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $prodId = null,
        public ?\DateTimeImmutable $created = null,
        public ?\DateTimeImmutable $updated = null,
        #[Constraint\RegisteredValue(Registry::CARD_KINDS)]
        public ?string $kind = null,
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $language = null,
        #[Constraint\RegisteredValue]
        public array $members = [],
        #[Assert\Valid]
        public ?Name $name = null,
        #[Assert\Valid]
        public ?SpeakToAs $speakToAs = null,
        #[Constraint\IdKeys, Assert\Valid]
        public array $nicknames = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $organizations = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $titles = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $emails = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $phones = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $addresses = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $onlineServices = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $links = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $notes = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $anniversaries = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $media = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $cryptoKeys = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $directories = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $calendars = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $schedulingAddresses = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $preferredLanguages = [],
        #[Constraint\RelatedToKeys, Assert\Valid]
        public array $relatedTo = [],
        #[Constraint\IdKeys, Assert\Valid]
        public array $personalInfo = [],
        public array $localizations = [],
        #[Constraint\RegisteredValue]
        public array $keywords = [],
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        #[Assert\Valid]
        public array $vCardProps = [],
        public array $extra = [],
    ) {
    }
}
