<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

/**
 * vCard 3.0 and 2.1 name the format of PHOTO, LOGO, SOUND and KEY with a TYPE parameter
 * value ("JPEG", "PGP"…), where vCard 4.0 and JSContact use media types (RFC 2426,
 * sections 3.1.4, 3.5.3, 3.6.6 and 3.7.1).
 *
 * @internal
 */
final class MediaTypes
{
    /** Media type of each known format. */
    private const array FORMATS = [
        'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'bmp' => 'image/bmp', 'tiff' => 'image/tiff', 'webp' => 'image/webp', 'heic' => 'image/heic',
        'wave' => 'audio/wav', 'wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'mpeg' => 'audio/mpeg',
        'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'pcm' => 'audio/basic',
        'pgp' => 'application/pgp-keys', 'x509' => 'application/pkix-cert',
    ];

    private function __construct()
    {
    }

    /**
     * The media type of a format, if known.
     */
    public static function fromFormat(string $format): ?string
    {
        return self::FORMATS[strtolower($format)] ?? null;
    }

    /**
     * The format to write as TYPE in vCard 3.0, if the media type has one.
     */
    public static function toFormat(string $mediaType): ?string
    {
        $mediaType = strtolower(trim(explode(';', $mediaType)[0]));
        $format = array_search($mediaType, self::FORMATS, true);

        return false === $format ? null : strtoupper($format);
    }
}
