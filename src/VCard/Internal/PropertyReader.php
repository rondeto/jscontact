<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Rondeto\JSContact\Model\VCardProperty;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Property;
use Sabre\VObject\Property\Text;
use Sabre\VObject\Property\Unknown;

/**
 * Reads one vCard property, keeping track of the parameters and TYPE values converted so
 * far: whatever is left is kept in vCardParams.
 *
 * @internal
 */
final class PropertyReader
{
    /** Parameters about the encoding of the value, which conversion makes irrelevant. */
    private const array ENCODING_PARAMETERS = ['VALUE', 'ENCODING', 'CHARSET'];

    public readonly string $name;

    /** Lowercase, as in jCard. */
    public readonly ?string $group;

    /** @var array<string, list<string>> Values by uppercase parameter name */
    private array $parameters = [];

    /** @var array<string, true> */
    private array $read = [];

    /** @var list<string> Lowercase TYPE values not read yet */
    private array $types = [];

    public function __construct(
        public readonly Property $property,
    ) {
        $this->name = strtoupper($property->name ?? '');
        $this->group = null === $property->group || '' === $property->group ? null : strtolower($property->group);

        foreach ($property->parameters() as $name => $parameter) {
            $name = strtoupper((string) $name);
            $values = array_values(array_filter($parameter->getParts(), static fn (string $value): bool => '' !== $value));
            $this->parameters[$name] = [...$this->parameters[$name] ?? [], ...$values];
        }

        // TYPE="voice,home" is one quoted value holding two types (RFC 9555, Figure 21).
        foreach ($this->parameters['TYPE'] ?? [] as $type) {
            foreach (explode(',', $type) as $item) {
                if ('' !== trim($item)) {
                    $this->types[] = strtolower(trim($item));
                }
            }
        }

        $this->types = array_values(array_unique($this->types));
    }

    public function has(string $name): bool
    {
        return isset($this->parameters[$name]);
    }

    /**
     * The first value of a parameter, without marking it as read.
     */
    public function peek(string $name): ?string
    {
        return $this->parameters[$name][0] ?? null;
    }

    /**
     * All values of a parameter, without marking it as read.
     *
     * @return list<string>
     */
    public function peekAll(string $name): array
    {
        return $this->parameters[$name] ?? [];
    }

    /**
     * The first value of a parameter, marking the parameter as read.
     */
    public function parameter(string $name): ?string
    {
        $this->read[$name] = true;

        return $this->parameters[$name][0] ?? null;
    }

    /**
     * The TYPE values not read yet, lowercase.
     *
     * @return list<string>
     */
    public function unreadTypes(): array
    {
        return $this->types;
    }

    /**
     * Whether TYPE has the value, marking it as read if so.
     */
    public function takeType(string $type): bool
    {
        if (!\in_array($type, $this->types, true)) {
            return false;
        }

        $this->types = array_values(array_diff($this->types, [$type]));

        return true;
    }

    /**
     * Unread parameters, other than those about value encoding, and the group, in jCard form.
     *
     * @return array<string, string|list<string>>
     */
    public function unreadParameters(): array
    {
        $unread = [];
        foreach ($this->parameters as $name => $values) {
            if ('TYPE' === $name || isset($this->read[$name]) || \in_array($name, self::ENCODING_PARAMETERS, true) || [] === $values) {
                continue;
            }

            $unread[strtolower($name)] = 1 === \count($values) ? $values[0] : $values;
        }

        if ([] !== $this->types) {
            $unread['type'] = 1 === \count($this->types) ? $this->types[0] : $this->types;
        }

        if (null !== $this->group) {
            $unread['group'] = $this->group;
        }

        return $unread;
    }

    /**
     * Unread parameter names, for error messages.
     *
     * @return list<string>
     */
    public function unreadParameterNames(): array
    {
        return array_keys(array_diff_key($this->unreadParameters(), ['group' => true]));
    }

    /**
     * The value as text, unescaped.
     */
    public function text(): string
    {
        // sabre/vobject workaround: sabre splits text values on unescaped commas, and then
        // returns them escaped again, "\n" included. A single text value has no list
        // separator, so its parts are joined back.
        $parts = $this->property->getParts();
        if ($this->property instanceof Text && !$this->property instanceof Unknown && \count($parts) > 1) {
            return implode($this->property->delimiter, array_map(static fn (mixed $part): string => \is_string($part) ? $part : '', $parts));
        }

        try {
            return $this->property->__toString();
        } catch (\Throwable) {
            // An invalid value for its type, such as a REV that is not a timestamp.
            return $this->property->getRawMimeDirValue();
        }
    }

    /**
     * The values of a text-list property, such as CATEGORIES or NICKNAME.
     *
     * @return list<string>
     */
    public function textList(): array
    {
        return array_values(array_filter($this->property->getParts(), static fn (mixed $value): bool => \is_string($value) && '' !== $value));
    }

    /**
     * The components of a structured value, from its raw form when known (see Parser).
     *
     * @return list<list<string>>
     */
    public function structured(?string $raw): array
    {
        if (null !== $raw) {
            return VCardText::splitStructured($raw);
        }

        // sabre/vobject workaround (fallback): without the raw value, escaped commas cannot be
        // told apart from list separators, since sabre has already unescaped them.
        return array_map(
            static fn (mixed $component): array => \is_string($component) && '' !== $component ? explode(',', $component) : [],
            array_values($this->property->getParts()),
        );
    }

    /**
     * The property in jCard form, to keep it verbatim in vCardProps.
     *
     * @param string|null $raw The raw value, which jCard keeps for unknown types (RFC 7095, section 5)
     */
    public function toVCardProperty(?string $raw = null): VCardProperty
    {
        try {
            $jCard = $this->property->jsonSerialize();
        } catch (InvalidDataException) {
            // An invalid value for its type, such as a BDAY that is not a date.
            $jCard = [$this->name, [], 'unknown', $this->property->getRawMimeDirValue()];
        }

        // sabre/vobject workaround: sabre unescapes the values of unknown properties, and
        // joins the parts of quoted-printable ones with commas; the raw value is the right one.
        if ('unknown' === ($jCard[2] ?? null) && null !== $raw) {
            $jCard = [$jCard[0], $jCard[1], 'unknown', $raw];
        }

        // VALUE is in the jCard type. Only a quoted-printable value is decoded: its ENCODING and
        // CHARSET no longer apply, while a base64 value still needs its ENCODING.
        $isQuotedPrintable = 'quoted-printable' === strtolower($this->peek('ENCODING') ?? '');
        $parameters = [];
        foreach ($this->parameters as $name => $values) {
            $isDecoded = 'VALUE' === $name || ($isQuotedPrintable && \in_array($name, ['ENCODING', 'CHARSET'], true));
            if (!$isDecoded && [] !== $values) {
                $parameters[strtolower($name)] = 1 === \count($values) ? $values[0] : $values;
            }
        }

        if (null !== $this->group) {
            $parameters['group'] = $this->group;
        }

        /** @var non-empty-list<mixed> $values */
        $values = \array_slice(array_values($jCard), 3) ?: [''];

        return new VCardProperty(strtolower($this->name), $parameters, \is_string($jCard[2] ?? null) ? $jCard[2] : 'unknown', $values);
    }
}
