<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * Registered values for the "phoneticSystem" property (RFC 9553, section 1.5.4).
 */
final class PhoneticSystem
{
    /** International Phonetic Alphabet. */
    public const string IPA = 'ipa';

    /** Cantonese romanization system "Jyutping". */
    public const string JYUT = 'jyut';

    /** Standard Mandarin romanization system "Hanyu Pinyin". */
    public const string PINY = 'piny';

    private function __construct()
    {
    }
}
