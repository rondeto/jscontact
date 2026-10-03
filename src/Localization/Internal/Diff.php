<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Localization\Internal;

/**
 * The patches that turn the JSON form of a Card into another one: a PatchObject (RFC 9553,
 * section 1.4.3).
 *
 * Patches go as deep as possible, so that they say what changes: into objects, and into
 * arrays of the same length (RFC 9553, Figure 20). Other arrays are replaced whole, as a
 * PatchObject cannot add or remove array elements.
 *
 * @internal
 */
final class Diff
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $ignored Top-level properties left out
     *
     * @return array<string, mixed> Values by path
     */
    public static function between(\stdClass $from, \stdClass $to, array $ignored = []): array
    {
        $patches = [];
        self::diff($from, $to, '', $patches, $ignored);

        return $patches;
    }

    /**
     * @param array<string, mixed> $patches
     * @param list<string>         $ignored
     */
    private static function diff(mixed $from, mixed $to, string $path, array &$patches, array $ignored = []): void
    {
        if ($from instanceof \stdClass && $to instanceof \stdClass) {
            $fromProperties = get_object_vars($from);
            $toProperties = get_object_vars($to);
            foreach ($toProperties as $name => $value) {
                if (!\in_array((string) $name, $ignored, true)) {
                    $child = self::join($path, (string) $name);
                    \array_key_exists($name, $fromProperties) ? self::diff($fromProperties[$name], $value, $child, $patches) : $patches[$child] = $value;
                }
            }

            foreach (array_keys($fromProperties) as $name) {
                if (!\array_key_exists($name, $toProperties) && !\in_array((string) $name, $ignored, true)) {
                    $patches[self::join($path, (string) $name)] = null;
                }
            }

            return;
        }

        if (\is_array($from) && \is_array($to) && \count($from) === \count($to) && array_is_list($from) && array_is_list($to)) {
            foreach ($to as $index => $value) {
                self::diff($from[$index], $value, self::join($path, (string) $index), $patches);
            }

            return;
        }

        if (json_encode($from) !== json_encode($to)) {
            $patches[$path] = $to;
        }
    }

    private static function join(string $path, string $token): string
    {
        return ('' === $path ? '' : $path.'/').Patch::escape($token);
    }
}
