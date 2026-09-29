<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Json;

/**
 * The input cannot be read as a Card at all: it is not JSON, or not a Card object.
 */
final class DecodingException extends \InvalidArgumentException
{
}
