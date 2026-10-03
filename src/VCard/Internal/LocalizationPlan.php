<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Rondeto\JSContact\Validation\Syntax;
use Sabre\VObject\Property;

/**
 * Sorts out the localized alternatives of a vCard (RFC 9555, sections 2.3.11 and 2.3.15).
 *
 * Properties with the same name and ALTID are versions of one another, in the languages
 * their LANGUAGE parameters say, as are all GRAMGENDER properties; phonetic N and ADR also share the ALTID of their N or ADR.
 * In each family, the main version is the one without LANGUAGE, or in the language of the
 * Card, or the first one; it converts to the Card. Each other language converts to a
 * localization: the Card converted with the versions of that language instead.
 *
 * @internal
 */
final readonly class LocalizationPlan
{
    /**
     * @param list<Property>                $main          The properties of the Card itself
     * @param array<string, list<Property>> $languages     The properties of the Card in each other language
     * @param array<int, true>              $groupedAltIds Properties whose ALTID only relates versions, by object id
     * @param array<int, true>              $alternatives  Properties that are versions in another language, by object id
     * @param list<Property>                $duplicates    Versions repeated in the same language, kept verbatim
     * @param string|null                   $language      The language of the Card: its LANGUAGE property, or the one all the language-tagged properties share
     */
    public function __construct(
        public array $main,
        public array $languages,
        public array $groupedAltIds,
        public array $alternatives,
        public array $duplicates,
        public ?string $language,
    ) {
    }

    /**
     * @param list<Property> $properties
     */
    public static function of(array $properties): self
    {
        $readers = array_map(static fn (Property $property): PropertyReader => new PropertyReader($property), $properties);

        $cardLanguage = null;
        foreach ($readers as $reader) {
            if ('LANGUAGE' === $reader->name && 1 === preg_match(Syntax::LANGUAGE_TAG, trim($reader->text()))) {
                $cardLanguage ??= Syntax::canonicalLanguageTag(trim($reader->text()));
            }
        }

        // Families of versions, by name, ALTID, and whether they are phonetic.
        $families = [];
        $related = [];
        foreach ($readers as $index => $reader) {
            // A Card has one grammatical gender: GRAMGENDER properties are versions of it even
            // without ALTID.
            $altId = $reader->peek('ALTID') ?? ('GRAMGENDER' === $reader->name ? '' : null);
            if (null !== $altId && 'LANGUAGE' !== $reader->name) {
                $families[$reader->name.'|'.($reader->has('PHONETIC') ? 'phonetic' : '').'|'.$altId][] = $index;
                $related[$reader->name.'|'.$altId][] = $index;
            }
        }

        $mainOf = []; // Main version of each family, by family
        $alternativesOf = []; // Versions by language, by family
        $duplicates = [];
        foreach ($families as $family => $indexes) {
            $isPhonetic = str_contains($family, '|phonetic|');
            $languageOf = static fn (int $index): ?string => null === ($tag = $readers[$index]->peek('LANGUAGE')) ? null : Syntax::canonicalLanguageTag($tag);

            $main = null;
            foreach ($indexes as $index) {
                if (null === $languageOf($index)) {
                    $main ??= $index;
                }
            }

            foreach ($indexes as $index) {
                if (null !== $cardLanguage && $languageOf($index) === $cardLanguage) {
                    $main ??= $index;
                }
            }

            // A phonetic version in another language only localizes; any other property is
            // the Card's own version when it has no other.
            if (!$isPhonetic) {
                $main ??= $indexes[0];
            }

            $mainOf[$family] = $main;

            foreach ($indexes as $index) {
                if ($index === $main) {
                    continue;
                }

                $language = $languageOf($index);
                if (null === $language || isset($alternativesOf[$family][$language]) || $language === (null === $main ? null : $languageOf($main))) {
                    $duplicates[] = $index;
                } else {
                    $alternativesOf[$family][$language] = $index;
                }
            }
        }

        $alternatives = [];
        foreach ($alternativesOf as $versions) {
            foreach ($versions as $index) {
                $alternatives[$index] = true;
            }
        }

        // ALTID only relates versions: it converts to nothing when it does.
        $groupedAltIds = [];
        foreach ($related as $indexes) {
            if (\count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $groupedAltIds[spl_object_id($properties[$index])] = true;
                }
            }
        }

        $main = [];
        foreach ($properties as $index => $property) {
            if (!isset($alternatives[$index]) && !\in_array($index, $duplicates, true)) {
                $main[] = $property;
            }
        }

        $languages = [];
        foreach ($alternativesOf as $versions) {
            foreach (array_keys($versions) as $language) {
                $languages[$language] = self::localized($properties, $mainOf, $alternativesOf, $alternatives, $duplicates, $language);
            }
        }

        $language = $cardLanguage ?? self::sharedLanguage($readers, $alternatives, $duplicates);

        return new self(
            $main,
            $languages,
            $groupedAltIds,
            array_combine(array_map(static fn (int $index): int => spl_object_id($properties[$index]), array_keys($alternatives)), array_fill(0, \count($alternatives), true)),
            array_map(static fn (int $index): Property => $properties[$index], $duplicates),
            $language,
        );
    }

    /**
     * The properties of the Card in a language: the versions in that language take the place
     * of the main ones, or are added when there is no main one.
     *
     * @param list<Property>                    $properties
     * @param array<string, int|null>           $mainOf
     * @param array<string, array<string, int>> $alternativesOf
     * @param array<int, true>                  $alternatives
     * @param list<int>                         $duplicates
     *
     * @return list<Property>
     */
    private static function localized(array $properties, array $mainOf, array $alternativesOf, array $alternatives, array $duplicates, string $language): array
    {
        $replacing = [];
        $adding = [];
        foreach ($alternativesOf as $family => $versions) {
            if (isset($versions[$language])) {
                if (null === $mainOf[$family]) {
                    $adding[$versions[$language]] = true;
                } else {
                    $replacing[$mainOf[$family]] = $versions[$language];
                }
            }
        }

        $localized = [];
        foreach ($properties as $index => $property) {
            if (isset($replacing[$index])) {
                $localized[] = $properties[$replacing[$index]];
            } elseif (isset($adding[$index]) || (!isset($alternatives[$index]) && !\in_array($index, $duplicates, true))) {
                $localized[] = $property;
            }
        }

        return $localized;
    }

    /**
     * The language all the language-tagged properties of the Card share, if any (RFC 9555,
     * Figure 3).
     *
     * @param list<PropertyReader> $readers
     * @param array<int, true>     $alternatives
     * @param list<int>            $duplicates
     */
    private static function sharedLanguage(array $readers, array $alternatives, array $duplicates): ?string
    {
        $languages = [];
        foreach ($readers as $index => $reader) {
            $tag = $reader->peek('LANGUAGE');
            if (null !== $tag && !isset($alternatives[$index]) && !\in_array($index, $duplicates, true) && 1 === preg_match(Syntax::LANGUAGE_TAG, $tag)) {
                $languages[Syntax::canonicalLanguageTag($tag)] = true;
            }
        }

        return 1 === \count($languages) ? array_key_first($languages) : null;
    }
}
