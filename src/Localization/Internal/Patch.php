<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Localization\Internal;

/**
 * Applies a PatchObject to the JSON form of a Card (RFC 9553, section 1.4.3), all patches
 * or none.
 *
 * @internal
 */
final class Patch
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $patches      Values by path, relative to the Card
     * @param bool                    $intoArrays   Whether a path may point into an array; JSPROP pointers may not (RFC 9555, section 3.2.1)
     * @param bool                    $leadingSlash Whether a leading "/" is ignored, as for JSPROP; PatchObject paths have an implicit one
     *
     * @return string|null Why the PatchObject is invalid, or null once it is applied
     */
    public static function apply(\stdClass $card, array $patches, bool $intoArrays = true, bool $leadingSlash = false): ?string
    {
        $paths = [];
        foreach (array_keys($patches) as $path) {
            $path = (string) $path; // PHP turns numeric string keys into integers
            $tokens = self::tokens($leadingSlash ? ltrim($path, '/') : $path);
            if (null === $tokens) {
                return \sprintf('"%s" is not a valid path', $path);
            }

            $paths[$path] = $tokens;
        }

        foreach ($paths as $path => $tokens) {
            foreach ($paths as $other => $otherTokens) {
                if ($path !== $other && \array_slice($otherTokens, 0, \count($tokens)) === $tokens) {
                    return \sprintf('"%s" is a prefix of "%s"', $path, $other);
                }
            }
        }

        // Check every patch before applying any (RFC 9553, section 1.4.3).
        $targets = [];
        foreach ($paths as $path => $tokens) {
            $last = array_pop($tokens);
            $parent = $card;
            foreach ($tokens as $token) {
                $parent = self::child($parent, $token, $intoArrays);
                if (null === $parent) {
                    return \sprintf('"%s" points into a value that does not exist', $path);
                }
            }

            if (\is_array($parent)) {
                if (!$intoArrays || !self::isIndex($last) || !\array_key_exists((int) $last, $parent)) {
                    return \sprintf('"%s" points to an array element that does not exist', $path);
                }

                if (null === $patches[$path]) {
                    return \sprintf('"%s" cannot remove an array element', $path);
                }
            } elseif (!$parent instanceof \stdClass) {
                return \sprintf('"%s" points into a value that is not an object', $path);
            }

            $targets[] = [$tokens, $last, $patches[$path]];
        }

        foreach ($targets as [$tokens, $last, $value]) {
            self::set($card, $tokens, $last, $value);
        }

        return null;
    }

    /**
     * The reference tokens of a path, unescaped (RFC 6901, section 4); null if a token is
     * the "-" array index, which PatchObjects forbid.
     *
     * @return non-empty-list<string>|null
     */
    public static function tokens(string $path): ?array
    {
        $tokens = [];
        foreach (explode('/', $path) as $token) {
            if ('-' === $token) {
                return null;
            }

            $tokens[] = strtr($token, ['~1' => '/', '~0' => '~']);
        }

        return $tokens;
    }

    /**
     * Escapes a reference token (RFC 6901, section 4).
     */
    public static function escape(string $token): string
    {
        return strtr($token, ['~' => '~0', '/' => '~1']);
    }

    private static function child(mixed $value, string $token, bool $intoArrays): mixed
    {
        if ($value instanceof \stdClass) {
            return property_exists($value, $token) ? $value->{$token} : null;
        }

        if ($intoArrays && \is_array($value) && self::isIndex($token)) {
            return $value[(int) $token] ?? null;
        }

        return null;
    }

    private static function isIndex(string $token): bool
    {
        return 1 === preg_match('/^(0|[1-9]\d*)$/', $token);
    }

    /**
     * Sets or, for null, removes the value. Arrays are values in PHP: they are copied along
     * the path, then written back.
     *
     * @param list<string> $tokens
     */
    private static function set(mixed &$node, array $tokens, string $last, mixed $value): void
    {
        if ([] !== $tokens) {
            $token = array_shift($tokens);
            if ($node instanceof \stdClass) {
                $child = $node->{$token};
                self::set($child, $tokens, $last, $value);
                $node->{$token} = $child;
            } elseif (\is_array($node)) {
                self::set($node[(int) $token], $tokens, $last, $value);
            }

            return;
        }

        if ($node instanceof \stdClass) {
            if (null === $value) {
                unset($node->{$last});
            } else {
                $node->{$last} = $value;
            }
        } elseif (\is_array($node)) {
            $node[(int) $last] = $value;
        }
    }
}
