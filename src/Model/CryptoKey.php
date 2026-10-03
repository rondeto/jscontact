<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A public key or certificate (RFC 9553, section 2.6.1).
 */
#[Constraint\ExtraProperties]
final class CryptoKey
{
    /**
     * @param string                             $uri         A URI, which may be a "data:" URI embedding the resource
     * @param string|null                        $kind        No kinds are registered
     * @param string|null                        $mediaType   The media type of the resource, such as "image/jpeg"
     * @param list<string>                       $contexts
     * @param int|null                           $pref        1 (most preferred) to 100
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        #[Assert\Regex(Syntax::URI, message: '{{ value }} is not a URI')]
        public string $uri,
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $kind = null,
        #[Assert\Regex(Syntax::MEDIA_TYPE, message: 'not a media type')]
        public ?string $mediaType = null,
        #[Constraint\RegisteredValue(Registry::CONTEXTS)]
        public array $contexts = [],
        #[Assert\Range(notInRangeMessage: 'must be between 1 and 100', min: 1, max: 100)]
        public ?int $pref = null,
        public ?string $label = null,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
