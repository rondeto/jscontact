# rondeto/jscontact

A PHP library for [JSContact](https://www.rfc-editor.org/rfc/rfc9553.html), the JSON representation of
contact data, and for converting between vCard and JSContact.

> **Status: early development (`0.x`).** The public API may change in any release until `1.0`.

## Goals

- A **typed JSContact 2.0 model**: [RFC 9553](https://www.rfc-editor.org/rfc/rfc9553.html) as updated by
  [RFC 9982](https://www.rfc-editor.org/rfc/rfc9982.html), with JSON serialization, deserialization and
  validation. The model has no dependencies.
- **vCard ⇄ JSContact conversion** as specified by [RFC 9555](https://www.rfc-editor.org/rfc/rfc9555.html)
  (updated by RFC 9982), including the vCard properties added by
  [RFC 9554](https://www.rfc-editor.org/rfc/rfc9554.html). vCard text is read and written with
  [`sabre/vobject`](https://github.com/sabre-io/vobject). Reads vCard 2.1, 3.0 and 4.0; writes 3.0 and 4.0.

## Principles

- **All or nothing, property by property.** A property exposed as a typed object is supported completely:
  cardinality, every parameter, every component. A property that is not supported yet is never half-typed:
  it goes through the generic RFC 9555 mechanism (`vCardProps` / `vCardParams`).
- **Nothing is lost.** Whatever is not understood is kept and comes back on a vCard → JSContact → vCard
  round trip. Preservation is semantic (properties, parameters, values, groups), not byte for byte.
- **Lenient on read, strict on write, by default.** A bad value is reported as an issue, not a failed card.
- **Issues, not silent losses.** Every conversion reports what it could not read or had to fix. Strict
  modes refuse such input or output instead.
- **Stable identifiers.** Converting the same card twice yields the same JSContact map keys: the vCard
  `PROP-ID` when present, otherwise a positional key named after the vCard property (`EMAIL-1`, `EMAIL-2`,
  …), as in the RFC 9555 examples.

## Out of scope

- Vendor-specific vCard extensions beyond what the RFCs specify (for example `X-ABDATE` or
  `X-ADDRESSBOOKSERVER-KIND`). They are preserved through `vCardProps`, not interpreted.
- JSCalendar / iCalendar.
- JMAP, CardDAV or any other protocol.
- Storing, merging or deduplicating contacts.

## Progress

| Area                                             | Status      |
|--------------------------------------------------|-------------|
| Tooling and CI                                   | ✅ Done     |
| Model and JSON: core properties                  | ✅ Done     |
| vCard ⇄ JSContact: core properties               | ✅ Done     |
| Organizations and titles (ORG, TITLE, ROLE)      | ✅ Done     |
| Anniversaries (BDAY, ANNIVERSARY…)               | ✅ Done     |
| speakToAs (GRAMGENDER, PRONOUNS)                 | ✅ Done     |
| Media, keys, directories, calendars              | ✅ Done     |
| Languages, relations, personal information       | ⏳ Planned  |
| Card-level GEO and TZ                            | ⏳ Planned  |
| Localizations (LANGUAGE and ALTID alternatives)  | ⏳ Planned  |

Typed properties: `uid`, `prodId`, `created`, `updated`, `kind`, `language`, `members`, `name`, `speakToAs`, `nicknames`,
`organizations`, `titles`, `emails`, `phones`, `addresses`, `onlineServices`, `links`, `media`, `cryptoKeys`,
`directories`, `calendars`, `schedulingAddresses`, `notes`, `anniversaries`, `keywords`, and
the RFC 9555 properties `vCardName`, `vCardParams` and `vCardProps`.

Properties that are not modeled yet are kept verbatim in the `extra` array of their object, and written
back unchanged. vCard properties that are not converted yet (RELATED, LANG, EXPERTISE…) and vendor extensions are
kept verbatim in `vCardProps`, as RFC 9555 allows, and written back unchanged.

## Usage

```php
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;

// Reading is lenient: invalid values are skipped or corrected, and reported.
$result = (new JsonDecoder())->decode($json);
foreach ($result->issues as $issue) {
    echo $issue, "\n"; // e.g. "/emails/e1/pref: expected an integer from 1 to 100, ignored the value"
}
$card = $result->value;

// new JsonDecoder(strict: true) throws an InvalidCardException listing every issue instead.

// Writing is strict: an invalid Card throws an InvalidCardException listing every issue.
// new JsonEncoder(validate: false) writes it anyway.
$json = (new JsonEncoder())->encode(new Card(
    uid: 'urn:uuid:f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
    emails: ['e1' => new EmailAddress('jane@example.com', contexts: ['work'], pref: 1)],
));
```

The validation rules are [Symfony Validator](https://symfony.com/doc/current/validation.html) constraints on
the model classes: a Symfony application can validate a `Card` with its own validator, like any other object.

### vCard

```php
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Rondeto\JSContact\VCard\VCardVersion;

// One result per vCard of the text. Reading is lenient: what cannot be converted is kept
// in vCardProps and reported as an issue.
foreach ((new VCardDecoder())->decode(file_get_contents('contacts.vcf')) as $result) {
    $card = $result->value;
}

// Writing reports what vCard, or the version asked for, cannot hold.
$result = (new VCardEncoder())->encode($card, VCardVersion::V30);
file_put_contents('contact.vcf', $result->value);
```

Map keys come from the vCard `PROP-ID` parameter, or are named after the property otherwise (`EMAIL-1`,
`PHONE-2`…); writing a Card to vCard sets `PROP-ID`, so keys survive a round trip. Labels convert to and from
`X-ABLabel`, as RFC 9555 specifies.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)

## Acknowledgements

The test suite reuses examples from other works. Their origin, license and any changes are detailed in
[`tests/Fixtures/SOURCES.md`](tests/Fixtures/SOURCES.md).

- **RFC 9553**, "JSContact: A JSON Representation of Contact Data", by Robert Stepanek and Mario Loffredo:
  every JSON example, © 2024 IETF Trust and the document authors.
- **RFC 9555**, "JSContact: Converting from and to vCard", by Mario Loffredo and Robert Stepanek: the conversion
  examples, © 2024 IETF Trust and the document authors.
- **[cozy-vcard](https://github.com/cozy/cozy-vcard)**, by Cozy Cloud: vCards exported by real address books
  (MIT License).
