<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Json;

use Rondeto\JSContact\Conversion\IssueCollector;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\Validation\Syntax;

/**
 * Leniently reads the properties of one JSON object: a value of the wrong type is skipped
 * with an issue instead of failing the whole card.
 *
 * @internal
 */
final class JsonObject
{
    /** @var array<array-key, true> */
    private array $read = ['@type' => true];

    public function __construct(
        private readonly \stdClass $data,
        public readonly string $path,
        private readonly IssueCollector $issues,
    ) {
    }

    public function has(string $name): bool
    {
        return property_exists($this->data, $name);
    }

    /**
     * @param string|null $name The property, or a JSON Pointer relative to this object; null for the object itself
     */
    public function warn(?string $name, string $message): void
    {
        $this->issues->add(null === $name ? $this->path : $this->pathOf($name), $message);
    }

    /**
     * Whether the object's @var, if set, is the expected one. A case variant is accepted
     * with an issue.
     */
    public function isOfType(string $expected): bool
    {
        if (!$this->has('@type')) {
            return true;
        }

        $type = $this->data->{'@type'};
        if ($type === $expected) {
            return true;
        }

        if (\is_string($type) && 0 === strcasecmp($type, $expected)) {
            $this->warn('@type', \sprintf('read "%s" as "%s"', $type, $expected));

            return true;
        }

        $this->warn('@type', \sprintf('expected "%s", ignored the object', $expected));

        return false;
    }

    public function string(string $name): ?string
    {
        $value = $this->take($name);
        if (null === $value || \is_string($value)) {
            return $value;
        }

        $this->warn($name, 'expected a string, ignored the value');

        return null;
    }

    /**
     * A string that must be set: returns null, with an issue, when it is missing.
     */
    public function requiredString(string $name): ?string
    {
        if (!$this->has($name)) {
            $this->warn($name, 'missing mandatory property');

            return null;
        }

        return $this->string($name);
    }

    public function bool(string $name): ?bool
    {
        $value = $this->take($name);
        if (null === $value || \is_bool($value)) {
            return $value;
        }

        $this->warn($name, 'expected a boolean, ignored the value');

        return null;
    }

    /**
     * An UnsignedInt (RFC 9553, section 1.4.2).
     */
    public function int(string $name): ?int
    {
        $value = $this->take($name);
        if (null === $value) {
            return null;
        }

        if (!\is_int($value) || $value < 0) {
            $this->warn($name, 'expected an unsigned integer, ignored the value');

            return null;
        }

        return $value;
    }

    public function pref(): ?int
    {
        $value = $this->take('pref');
        if (null === $value) {
            return null;
        }

        if (!\is_int($value) || $value < 1 || $value > 100) {
            $this->warn('pref', 'expected an integer from 1 to 100, ignored the value');

            return null;
        }

        return $value;
    }

    public function dateTime(string $name): ?\DateTimeImmutable
    {
        $value = $this->string($name);
        if (null === $value) {
            return null;
        }

        $dateTime = Syntax::parseDateTime($value);
        if (null === $dateTime) {
            $this->warn($name, \sprintf('"%s" is not a date-time, ignored the value', $value));
        } elseif (!Syntax::isUtcDateTime($value)) {
            $this->warn($name, \sprintf('read "%s" as "%s"', $value, Syntax::formatUtcDateTime($dateTime)));
        }

        return $dateTime;
    }

    /**
     * An enumerated value. A case variant of a registered value is corrected with an issue.
     *
     * @param list<string> $known
     */
    public function enum(string $name, array $known): ?string
    {
        $value = $this->string($name);

        return null === $value ? null : $this->fixCase($name, $value, $known);
    }

    /**
     * A String[Boolean] set, as the list of its keys.
     *
     * @param list<string> $known Registered values, whose case variants get corrected
     *
     * @return list<string>
     */
    public function set(string $name, array $known = []): array
    {
        $value = $this->take($name);
        if (null === $value) {
            return [];
        }

        if (!$value instanceof \stdClass) {
            $this->warn($name, 'expected an object, ignored the value');

            return [];
        }

        $set = [];
        foreach (get_object_vars($value) as $key => $flag) {
            $key = (string) $key; // PHP turns numeric string keys into integers
            $keyPath = $name.'/'.$this->escape($key);
            if (true !== $flag) {
                $this->warn($keyPath, 'set values must be true, ignored the entry');
                continue;
            }

            $set[] = $this->fixCase($keyPath, $key, $known);
        }

        return array_values(array_unique($set));
    }

    /**
     * @return array<array-key, string>
     */
    public function stringMap(string $name): array
    {
        $value = $this->take($name);
        if (null === $value) {
            return [];
        }

        if (!$value instanceof \stdClass) {
            $this->warn($name, 'expected an object, ignored the value');

            return [];
        }

        $map = [];
        foreach (get_object_vars($value) as $key => $item) {
            if (\is_string($item)) {
                $map[(string) $key] = $item;
            } else {
                $this->warn($name.'/'.$this->escape((string) $key), 'expected a string, ignored the entry');
            }
        }

        return $map;
    }

    public function object(string $name): ?self
    {
        $value = $this->take($name);
        if (null === $value) {
            return null;
        }

        if (!$value instanceof \stdClass) {
            $this->warn($name, 'expected an object, ignored the value');

            return null;
        }

        return new self($value, $this->pathOf($name), $this->issues);
    }

    /**
     * @return array<int, self> indexed by position in the JSON array
     */
    public function objectList(string $name): array
    {
        $value = $this->take($name);
        if (null === $value) {
            return [];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            $this->warn($name, 'expected an array, ignored the value');

            return [];
        }

        $list = [];
        foreach ($value as $index => $item) {
            if ($item instanceof \stdClass) {
                $list[$index] = new self($item, $this->pathOf($name).'/'.$index, $this->issues);
            } else {
                $this->warn($name.'/'.$index, 'expected an object, ignored the entry');
            }
        }

        return $list;
    }

    /**
     * An Id[Object] map. Entries whose key is not a valid Id are skipped.
     *
     * @return array<array-key, self>
     */
    public function objectMap(string $name): array
    {
        $value = $this->take($name);
        if (null === $value) {
            return [];
        }

        if (!$value instanceof \stdClass) {
            $this->warn($name, 'expected an object, ignored the value');

            return [];
        }

        $map = [];
        foreach (get_object_vars($value) as $id => $item) {
            $id = (string) $id; // PHP turns numeric string keys into integers
            $itemPath = $name.'/'.$this->escape($id);
            if (!Syntax::isId($id)) {
                $this->warn($itemPath, 'not a valid Id, ignored the entry');
            } elseif (!$item instanceof \stdClass) {
                $this->warn($itemPath, 'expected an object, ignored the entry');
            } else {
                $map[$id] = new self($item, $this->pathOf($itemPath), $this->issues);
            }
        }

        return $map;
    }

    /**
     * The vCardParams property (RFC 9555, section 2.15.2).
     *
     * @return array<string, string|list<string>>
     */
    public function vCardParams(): array
    {
        $value = $this->take('vCardParams');
        if (null === $value) {
            return [];
        }

        if (!$value instanceof \stdClass) {
            $this->warn('vCardParams', 'expected an object, ignored the value');

            return [];
        }

        $parameters = [];
        foreach (get_object_vars($value) as $name => $parameter) {
            $parameter = $this->jCardParameter($parameter);
            if (null === $parameter) {
                $this->warn('vCardParams/'.$this->escape((string) $name), 'expected a string or a list of strings, ignored the parameter');
            } else {
                $parameters[strtolower((string) $name)] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * The vCardProps property (RFC 9555, section 2.15.1): jCard properties.
     *
     * @return list<VCardProperty>
     */
    public function vCardProps(): array
    {
        $value = $this->take('vCardProps');
        if (null === $value) {
            return [];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            $this->warn('vCardProps', 'expected an array, ignored the value');

            return [];
        }

        $properties = [];
        foreach ($value as $index => $item) {
            $property = $this->jCardProperty($item);
            if (null === $property) {
                $this->warn('vCardProps/'.$index, 'not a jCard property, ignored the entry');
            } else {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * Every property not read so far, kept verbatim. Invalid property names are dropped.
     *
     * @param list<string> $registered Registered properties this object may hold unmodeled
     *
     * @return array<array-key, mixed>
     */
    public function extra(array $registered = Registry::UNMODELED_COMMON_PROPERTIES): array
    {
        $modeled = array_map(strtolower(...), array_keys($this->read));
        $extra = [];
        foreach (get_object_vars($this->data) as $name => $value) {
            $name = (string) $name; // PHP turns numeric string keys into integers
            if (isset($this->read[$name])) {
                continue;
            }

            if (\in_array(strtolower($name), $modeled, true) || null !== Syntax::caseVariantOf($name, $registered)) {
                $this->warn($this->escape($name), 'property names are case-sensitive, ignored the property');
            } elseif ('extra' === $name) {
                $this->warn($name, '"extra" is a reserved property name, ignored the property');
            } elseif (!Syntax::isIanaName($name) && !Syntax::isVendorExtension($name)) {
                $this->warn($this->escape($name), 'not a valid property name, ignored the property');
            } else {
                $extra[$name] = $value;
            }
        }

        return $extra;
    }

    /**
     * Marks a property as read and returns its value; null if it is missing or null.
     */
    private function take(string $name): mixed
    {
        $this->read[$name] = true;

        return $this->data->{$name} ?? null;
    }

    /**
     * @param list<string> $known
     */
    private function fixCase(string $name, string $value, array $known): string
    {
        $expected = Syntax::caseVariantOf($value, $known);
        if (null === $expected) {
            return $value;
        }

        $this->warn($name, \sprintf('read "%s" as "%s"', $value, $expected));

        return $expected;
    }

    private function pathOf(string $name): string
    {
        return $this->path.'/'.$name;
    }

    private function escape(string $token): string
    {
        return strtr($token, ['~' => '~0', '/' => '~1']);
    }

    /**
     * @return string|list<string>|null
     */
    private function jCardParameter(mixed $value): string|array|null
    {
        if (\is_string($value)) {
            return $value;
        }

        if (!\is_array($value) || [] === $value) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                return null;
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * Reads a jCard property: [name, parameters, type, value, ...] (RFC 7095, section 3.3).
     */
    private function jCardProperty(mixed $value): ?VCardProperty
    {
        if (!\is_array($value) || !array_is_list($value) || \count($value) < 4) {
            return null;
        }

        [$name, $parameters, $type] = $value;
        if (!\is_string($name) || !\is_string($type) || !$parameters instanceof \stdClass) {
            return null;
        }

        $jCardParameters = [];
        foreach (get_object_vars($parameters) as $parameterName => $parameter) {
            $parameter = $this->jCardParameter($parameter);
            if (null === $parameter) {
                return null;
            }

            $jCardParameters[strtolower((string) $parameterName)] = $parameter;
        }

        return new VCardProperty(strtolower($name), $jCardParameters, strtolower($type), \array_slice($value, 3));
    }
}
