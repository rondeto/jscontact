<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * A phone number (RFC 9553, section 2.3.3).
 */
final readonly class Phone
{
    public const string FEATURE_MOBILE = 'mobile';

    public const string FEATURE_VOICE = 'voice';

    public const string FEATURE_TEXT = 'text';

    public const string FEATURE_VIDEO = 'video';

    public const string FEATURE_MAIN_NUMBER = 'main-number';

    public const string FEATURE_TEXTPHONE = 'textphone';

    public const string FEATURE_FAX = 'fax';

    public const string FEATURE_PAGER = 'pager';

    /**
     * @param string                  $number   A URI (tel:, sip:…) or free text
     * @param list<string>            $features
     * @param list<string>            $contexts
     * @param int|null                $pref     1 (most preferred) to 100
     * @param array<array-key, mixed> $extra    Other properties, as JSON values
     */
    public function __construct(
        public string $number,
        public array $features = [],
        public array $contexts = [],
        public ?int $pref = null,
        public ?string $label = null,
        public array $extra = [],
    ) {
    }
}
