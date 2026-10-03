# rondeto/jscontact

A PHP library for [JSContact](https://www.rfc-editor.org/rfc/rfc9553.html), the JSON format for contact data,
and for converting between vCard and JSContact.

> **Status: early development (`0.x`).** The public API may change in any release until `1.0`.

## Why JSContact?

vCard (`.vcf` files) is how address books have exchanged contacts for decades. It is a line-based text
format that has grown through three versions, and every address book reads and writes it a little
differently.

JSContact is its successor, standardized by the IETF in 2024: the same contact data as plain JSON, with a
precise model and an official mapping from and to vCard. JMAP, the JSON-based protocol meant to replace IMAP
and CardDAV, uses it for contacts.

This library gives you:

- **A typed JSContact model**: PHP objects for a contact card, read from and written to JSON, and validated.
- **vCard ⇄ JSContact conversion**: read `.vcf` files from any address book (vCard 2.1, 3.0 and 4.0) into
  that model, and write it back as vCard 3.0 or 4.0.

It is useful to import contacts from anywhere into a clean model, to expose contacts as JSON, or to export
them as vCards for a given address book.

## Installation

```sh
composer require rondeto/jscontact
```

## Quick start

```php
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\VCard\VCardDecoder;

$vCard = <<<VCF
    BEGIN:VCARD
    VERSION:3.0
    UID:urn:uuid:f81d4fae-7dec-11d0-a765-00a0c91e6bf6
    FN:Jane Doe
    N:Doe;Jane;;;
    EMAIL;TYPE=INTERNET,WORK,PREF:jane@example.com
    TEL;TYPE=CELL:+33 6 12 34 56 78
    END:VCARD
    VCF;

$card = (new VCardDecoder())->decode($vCard)[0]->value;

echo (new JsonEncoder())->encode($card, pretty: true);
```

```json
{
    "@type": "Card",
    "version": "2.0",
    "uid": "urn:uuid:f81d4fae-7dec-11d0-a765-00a0c91e6bf6",
    "name": {
        "components": [
            { "kind": "surname", "value": "Doe" },
            { "kind": "given", "value": "Jane" }
        ],
        "full": "Jane Doe"
    },
    "emails": {
        "EMAIL-1": {
            "address": "jane@example.com",
            "contexts": { "work": true },
            "pref": 1,
            "vCardParams": { "type": "internet" }
        }
    },
    "phones": {
        "PHONE-1": {
            "number": "+33 6 12 34 56 78",
            "features": { "mobile": true }
        }
    }
}
```

`@type` and `version` are always written for you. `TYPE=INTERNET` has no JSContact equivalent, so it is kept
in `vCardParams` and comes back when the Card is written to vCard again.

## Usage

### The model

A contact is a `Rondeto\JSContact\Model\Card`, a plain object whose properties follow the JSContact
specification: `name`, `emails`, `phones`, `addresses`, `organizations`, `relatedTo`, and so on. Every
class lives in [`src/Model`](src/Model).

```php
echo $card->name?->full; // Jane Doe

foreach ($card->emails as $id => $email) {
    echo $id, ' ', $email->address, ' ', implode(',', $email->contexts); // EMAIL-1 jane@example.com work
}
```

A few things differ from vCard, and may surprise you at first:

- **Lists are maps with ids.** `emails`, `phones`, `addresses`… are keyed by an identifier, not by position,
  so that other objects and updates can refer to an entry. This library keeps these ids stable: converting
  the same vCard twice gives the same keys (see [Identifiers](#identifiers)).
- **`contexts` and `features` replace vCard's `TYPE`.** `TYPE=WORK` becomes `contexts: ['work']`,
  `TYPE=CELL` becomes `features: ['mobile']`, and `TYPE=PREF` becomes `pref: 1` (1 is most preferred, 100
  least).
- **Nothing a vCard holds is dropped.** What has no typed property yet is kept as is, in `vCardProps` (whole
  vCard properties) or `vCardParams` (parameters of a property), and written back to vCard as it was.

### JSON

```php
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;

$result = (new JsonDecoder())->decode($json);
$card = $result->value;

$json = (new JsonEncoder())->encode(new Card(
    uid: 'urn:uuid:f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
    emails: ['e1' => new EmailAddress('jane@example.com', contexts: ['work'], pref: 1)],
));
```

### vCard

```php
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\Target;
use Rondeto\JSContact\VCard\VCardEncoder;
use Rondeto\JSContact\VCard\VCardVersion;

// A .vcf file may hold several vCards: one result per vCard.
foreach ((new VCardDecoder())->decode(file_get_contents('contacts.vcf')) as $result) {
    $card = $result->value;
}

// Writes vCard 4.0 by default.
$result = (new VCardEncoder())->encode($card, new Target(VCardVersion::V30));
file_put_contents('contact.vcf', $result->value);
```

### Editing a Card

Model objects are plain PHP objects: change their properties directly.

```php
use Rondeto\JSContact\Model\Context;
use Rondeto\JSContact\Model\Phone;

$card = (new VCardDecoder())->decode($vCard)[0]->value;

$card->name->full = 'Jane Doe';
$card->emails['EMAIL-1']->address = 'jane@example.com';
$card->phones['work'] = new Phone('+33 1 23 45 67 89', contexts: [Context::WORK]);
unset($card->notes['NOTE-1']);

$vCard = (new VCardEncoder())->encode($card)->value;
```

Choose the key of a new entry (`'work'` above): `$card->phones[] = …` would give it the key `0`. Keys
identify an entry across versions of a Card, see [Identifiers](#identifiers).

Changes are not checked when they are made: the encoders validate the Card when they write it, or call
`CardValidator` yourself.

#### Copying a Card

`clone $card` copies the Card, not the objects inside it: changing `$copy->name->full` also changes the
original. To get an independent copy, use [`myclabs/deep-copy`](https://github.com/myclabs/DeepCopy):

```bash
composer require myclabs/deep-copy
```

```php
use DeepCopy\DeepCopy;

$copy = (new DeepCopy())->copy($card);
$copy->name->full = 'John Doe'; // $card is unchanged
```

### Issues and strict mode

Contact data found in the wild is often slightly broken. Every decoder and encoder returns a `Result`: the
converted `value`, and the `issues` met on the way. Each `Issue` has a `path`, a
[JSON Pointer](https://www.rfc-editor.org/rfc/rfc6901.html) into the Card, and a `message`.

```php
foreach ($result->issues as $issue) {
    echo $issue, "\n"; // e.g. "/emails/e1/pref: expected an integer from 1 to 100, ignored the value"
}
```

By default, reading is lenient and writing is strict:

|                | Default                                                     | Other mode                                                        |
|----------------|-------------------------------------------------------------|-------------------------------------------------------------------|
| `JsonDecoder`  | Skips or corrects invalid values, and reports them           | `strict: true` throws an `InvalidCardException` on any issue      |
| `VCardDecoder` | Keeps what it cannot convert in `vCardProps`, and reports it | `strict: true` throws an `InvalidCardException` on any issue      |
| `JsonEncoder`  | Throws an `InvalidCardException` for an invalid Card          | `validate: false` writes it anyway                                |
| `VCardEncoder` | Throws an `InvalidCardException` for an invalid Card; reports what vCard, or the version asked for, cannot hold | `validate: false` writes it anyway |

The validation rules are [Symfony Validator](https://symfony.com/doc/current/validation.html) constraints on
the model classes: a Symfony application can validate a `Card` with its own validator, like any other object.

### Address book dialects

Address books also write properties of their own that no specification defines. Apple's Address Book, for
example, writes a spouse as `X-ABRELATEDNAMES` with the label `_$!<Spouse>!$_`, where vCard 4.0 has
`RELATED;TYPE=spouse`. By default, such properties are kept verbatim in `vCardProps`, but not understood.

A `Dialect` teaches the converter one family of these properties: it rewrites them as the standard
properties they mean before reading, and back after writing. Dialects are opt-in.

To read, pass the dialects to the decoder. The address book a vCard comes from is seldom known, and each
dialect reads only its own properties: `Dialects::all()` reads them all.

To write, the address book the vCard is for is known: pass a `Target` to `encode()`, the vCard version and
the dialects that address book reads. `Target::apple()` and `Target::android()` are ready-made.

```php
use Rondeto\JSContact\VCard\Dialect\Dialects;
use Rondeto\JSContact\VCard\Target;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;

$results = (new VCardDecoder(dialects: Dialects::all()))->decode($vCards); // the spouse is now in relatedTo
$result = (new VCardEncoder())->encode($card, Target::apple());           // and back to X-ABRELATEDNAMES
```

| Dialect           | For                                                                                                                                                         |
|-------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Apple`           | Apple's Address Book, whose properties other address books, such as Google Contacts, also export: related names, dates, addresses, social profiles, groups, built-in labels, dates without a year |
| `Android`         | The Android contacts app: relations, anniversaries and nicknames in `X-ANDROID-CUSTOM`, dates without a year in vCard 3.0                                   |
| `LegacyMessaging` | Instant messaging properties from before vCard had `IMPP`: `X-AIM`, `X-ICQ`, `X-JABBER`, `X-SKYPE`…                                                         |

| Target              | Writes                                                                                                   |
|---------------------|----------------------------------------------------------------------------------------------------------|
| `Target::apple()`   | vCard 3.0 with `LegacyMessaging` and `Apple`, for Apple Contacts and iCloud, and Google Contacts          |
| `Target::android()` | vCard 3.0 with `LegacyMessaging` and `Android`: Android exports vCard 2.1, which the encoder does not write |

For any other combination, build one: `new Target(VCardVersion::V30, [new LegacyMessaging()])`. Dialects
write in the order given, each rewriting what the previous ones left: `LegacyMessaging` comes before `Apple`,
or Apple's `X-SOCIALPROFILE` would take the AIM user names that address books expect as `X-AIM`.

### Localizations

A Card can carry versions of itself in other languages, for example a name in Japanese script and its Latin
transcription. JSContact stores them in `localizations`, as patches to apply to the Card, one per language.
In vCard, they are the versions of a property that share an `ALTID` with another `LANGUAGE`; the converter
maps one to the other.

```php
use Rondeto\JSContact\Localization\Localizer;

// The Card in French: its "fr" localization applied, or the Card itself without one.
$french = (new Localizer())->localize($card, 'fr')->value;

// The other way: the patch that turns a Card into its French version, to store in localizations.
$patch = (new Localizer())->localization($card, $french);
```

A localization vCard cannot hold, such as a translated label, is written as a `JSPROP` property, which vCard
4.0 defines to carry JSContact data, and reported as an issue.

## How it works

### Specifications

| RFC                                                     | What it defines                                         | In this library                     |
|---------------------------------------------------------|---------------------------------------------------------|-------------------------------------|
| [RFC 9553](https://www.rfc-editor.org/rfc/rfc9553.html) | JSContact: the JSON model                               | `Model`, `Json`, `Localization`     |
| [RFC 9554](https://www.rfc-editor.org/rfc/rfc9554.html) | New vCard properties and parameters, to match JSContact | `VCard`                             |
| [RFC 9555](https://www.rfc-editor.org/rfc/rfc9555.html) | Conversion between vCard and JSContact                  | `VCard`                             |
| [RFC 9982](https://www.rfc-editor.org/rfc/rfc9982.html) | JSContact 2.0: updates to the three above               | Everywhere: the model is version 2.0 |

vCard text is parsed and serialized with [`sabre/vobject`](https://github.com/sabre-io/vobject).

### Principles

- **Complete or generic, property by property.** A property exposed as a typed object is supported
  completely: every parameter, every component. A property that is not supported yet is never half-typed:
  it goes through the generic `vCardProps` / `vCardParams` mechanism of RFC 9555.
- **Nothing is lost.** Whatever is not understood is kept, and comes back on a vCard → JSContact → vCard
  round trip. Preservation is semantic (properties, parameters, values, groups), not byte for byte.
- **Issues, not silent losses.** Every conversion reports what it could not read or had to fix.

### Identifiers

Converting the same vCard twice gives the same keys: the vCard `PROP-ID` parameter when there is one,
otherwise a key named after the vCard property and its position (`EMAIL-1`, `PHONE-2`…), as in the RFC 9555
examples. Writing a Card to vCard sets `PROP-ID` on every property, so keys survive a round trip. Labels
are written as `X-ABLabel`, as RFC 9555 specifies.

## Out of scope

- Address book specific properties beyond what the RFCs define, unless a [dialect](#address-book-dialects)
  covers them. They are kept in `vCardProps`, not interpreted.
- JSCalendar / iCalendar.
- JMAP, CardDAV or any other protocol.
- Storing, merging or deduplicating contacts.

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
