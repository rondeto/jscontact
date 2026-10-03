<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Dialect;

use Rondeto\JSContact\Conversion\Issue;
use Sabre\VObject\Component\VCard;

/**
 * The vCard variant of an address book vendor, such as Apple's X-ABRELATEDNAMES or
 * Android's X-ANDROID-CUSTOM: properties the RFCs do not define, but whose meaning they
 * have a property for.
 *
 * A dialect rewrites vCards, not Cards: read() turns the vendor properties into the RFC
 * ones before the conversion to JSContact, write() turns them back after the conversion to
 * vCard. What a dialect does not rewrite is converted as usual, so kept verbatim when the
 * RFCs do not define it.
 */
interface Dialect
{
    /**
     * Rewrites the vendor properties of a vCard as the RFC properties they mean.
     *
     * @return list<Issue> What could not be rewritten faithfully; the path is empty, as
     *                     there is no Card yet
     */
    public function read(VCard $vCard): array;

    /**
     * Rewrites RFC properties of a vCard as the vendor properties this vendor reads.
     *
     * @return list<Issue> What could not be rewritten faithfully, at the root of the Card
     */
    public function write(VCard $vCard): array;
}
