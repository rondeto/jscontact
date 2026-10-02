<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Sabre\VObject\Property\Unknown;

/**
 * sabre/vobject workaround: a property of unknown type, written exactly as read. jCard keeps
 * the raw value of such properties (RFC 7095, section 5), which sabre/vobject would
 * otherwise escape again.
 *
 * @internal
 */
final class RawProperty extends Unknown
{
    private string $raw = '';

    /**
     * @param mixed $val Untyped in sabre/vobject 4, a string in 5: "mixed" suits both
     */
    public function setRawMimeDirValue(mixed $val): void
    {
        $this->raw = \is_string($val) ? $val : '';
        parent::setRawMimeDirValue($this->raw);
    }

    public function getRawMimeDirValue(): string
    {
        return $this->raw;
    }
}
