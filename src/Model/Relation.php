<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How another Card relates to a Card, in Card::$relatedTo (RFC 9553, section 2.1.8).
 */
#[Constraint\ExtraProperties]
final class Relation
{
    public const string ACQUAINTANCE = 'acquaintance';

    public const string AGENT = 'agent';

    public const string CHILD = 'child';

    public const string CO_RESIDENT = 'co-resident';

    public const string CO_WORKER = 'co-worker';

    public const string COLLEAGUE = 'colleague';

    public const string CONTACT = 'contact';

    public const string CRUSH = 'crush';

    public const string DATE = 'date';

    public const string EMERGENCY = 'emergency';

    public const string FRIEND = 'friend';

    public const string KIN = 'kin';

    public const string ME = 'me';

    public const string MET = 'met';

    public const string MUSE = 'muse';

    public const string NEIGHBOR = 'neighbor';

    public const string PARENT = 'parent';

    public const string SIBLING = 'sibling';

    public const string SPOUSE = 'spouse';

    public const string SWEETHEART = 'sweetheart';

    /**
     * @param list<string>                       $relation    Relation types; none means the relationship is undefined
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        #[Constraint\RegisteredValue(Registry::RELATION_TYPES)]
        public array $relation = [],
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
