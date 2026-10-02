<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

/**
 * Applies the PatchObject formed by JSPROP properties to a Card's JSON form (RFC 9553,
 * section 1.4.3; RFC 9555, section 3.2.1).
 *
 * @internal
 */
final class Patch
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $patches Values by JSON pointer, relative to the Card
     *
     * @return string|null Why the PatchObject is invalid, or null once it is applied
     */
    public static function apply(\stdClass $card, array $patches): ?string
    {
        $paths = [];
        foreach (array_keys($patches) as $pointer) {
            $tokens = array_map(
                static fn (string $token): string => strtr($token, ['~1' => '/', '~0' => '~']),
                explode('/', ltrim((string) $pointer, '/')),
            );
            if ([] !== array_filter($tokens, static fn (string $token): bool => '' === $token)) {
                return \sprintf('"%s" is not a valid pointer', $pointer);
            }

            $paths[(string) $pointer] = $tokens;
        }

        foreach ($paths as $pointer => $tokens) {
            foreach ($paths as $other => $otherTokens) {
                if ($pointer !== $other && \array_slice($otherTokens, 0, \count($tokens)) === $tokens) {
                    return \sprintf('"%s" is a prefix of "%s"', $pointer, $other);
                }
            }
        }

        // Check every patch before applying any: a PatchObject applies entirely or not at all.
        $targets = [];
        foreach ($paths as $pointer => $tokens) {
            $name = array_pop($tokens);
            $parent = $card;
            foreach ($tokens as $token) {
                $parent = $parent instanceof \stdClass && property_exists($parent, $token) ? $parent->{$token} : null;
            }

            if (!$parent instanceof \stdClass) {
                return \sprintf('the parent of "%s" does not exist, or is not an object', $pointer);
            }

            $targets[$pointer] = [$parent, $name];
        }

        foreach ($targets as $pointer => [$parent, $name]) {
            if (null === $patches[$pointer]) {
                unset($parent->{$name});
            } else {
                $parent->{$name} = $patches[$pointer];
            }
        }

        return null;
    }
}
