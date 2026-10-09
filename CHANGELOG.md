# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/): until `1.0`, a minor release may break the public API.

## [Unreleased]

### Fixed

- `VCardDecoder` and `VCardEncoder` no longer fail with a `TypeError` on a parameter named by a number
  (`PHOTO;ENCODING=b;TYPE=image/png;0=v2-federated:…`, written by Nextcloud for its federated contacts):
  PHP makes it an integer key. It is kept as any other unknown parameter.

## [0.2.2] - 2026-10-05

### Fixed

- `VCardDecoder` no longer turns an empty `EMAIL` or `TEL` (`EMAIL;TYPE=HOME:`, written by Nextcloud
  Contacts) into an address or a number with an empty value: it keeps the property verbatim and reports
  it, as it does for an empty `ADR` or `NICKNAME`.

## [0.2.1] - 2026-10-03

### Fixed

- `VCardDecoder` no longer loses the whole card on a `VALUE` parameter listing several types
  (`URL;VALUE=uri,text:…`, written by ez-vcard): it keeps the first one and reports it.

## [0.2.0] - 2026-10-03

### Added

- `Target`: the vCard version and dialects `VCardEncoder` writes for, with `Target::apple()` and
  `Target::android()` ready-made.
- `Dialects::all()`: every dialect, to read vCards from an unknown address book.

### Changed

- **Breaking**: `VCardEncoder::encode()` and `convert()` take a `Target` instead of a `VCardVersion`, and the
  `VCardEncoder` constructor no longer takes dialects. Dialects write in the order given, no longer in
  reverse.
- Model classes are no longer read-only: edit a Card by setting its properties.

### Fixed

- The `Apple` dialect writes the built-in labels it kept, such as `_$!<Spouse>!$_`, as Apple does, instead of
  the readable labels they were read as.
- The Apple label or Android type a relation was read with no longer outlives a change of the relation: a
  spouse turned friend is written as a friend.
- `VCardEncoder` leaves out, and reports, an `X-ABLabel` of `vCardProps` whose group has no other property,
  such as the label of a relation removed from the Card.

## [0.1.0] - 2026-10-03

First release.

### Added

- **JSContact model**: typed, immutable classes for every property of a JSContact 2.0 Card
  ([RFC 9553](https://www.rfc-editor.org/rfc/rfc9553.html), as updated by
  [RFC 9982](https://www.rfc-editor.org/rfc/rfc9982.html)), localizations included. Properties the
  model does not know are kept in `extra`.
- **JSON**:
  - `JsonDecoder` reads Cards leniently. Invalid values are skipped or corrected, and reported as issues
    pointing into the JSON. With `strict: true`, it throws an `InvalidCardException` instead.
  - `JsonEncoder` refuses invalid Cards. With `validate: false`, it writes them anyway.
- **Validation**: `CardValidator`, built on [Symfony Validator](https://symfony.com/doc/current/validation.html)
  constraints declared on the model classes.
- **vCard**:
  - `VCardDecoder` reads vCard 2.1, 3.0 and 4.0, and `VCardEncoder` writes vCard 3.0 and 4.0, following
    [RFC 9555](https://www.rfc-editor.org/rfc/rfc9555.html). This includes the properties of
    [RFC 9554](https://www.rfc-editor.org/rfc/rfc9554.html), `ALTID`/`LANGUAGE` versions and phonetic names
    and addresses.
  - What cannot be converted is kept in `vCardProps`, `vCardParams` and `JSPROP`, and reported as an issue.
  - Map keys come from `PROP-ID`, or are named after the property (`EMAIL-1`), so that converting the same
    vCard twice gives the same keys.
- **Localizations**: `Localizer` gives a Card in a language, and builds the localization between a Card and
  its localized version.
- **vCard dialects**, opt-in rewrites of vendor properties to and from the RFC ones:
  - `Apple`: `X-ABRELATEDNAMES`, `X-ABDATE`, `X-ABADR`, `X-SOCIALPROFILE`, `X-ABShowAs`,
    `X-ADDRESSBOOKSERVER-*`, built-in labels, and dates without a year. Google Contacts writes these too.
  - `Android`: relations, wedding anniversaries and nicknames in `X-ANDROID-CUSTOM`, and dates without a year.
  - `LegacyMessaging`: `X-AIM`, `X-ICQ`, `X-JABBER`, `X-MSN`, `X-YAHOO`, `X-SKYPE`, `X-QQ`, `X-GOOGLE-TALK`…

### Known limitations

- Apple and Android phonetic names (`X-PHONETIC-*`) are kept verbatim: they give no phonetic system or
  script, which JSContact requires.
- Some sabre/vobject behaviors are worked around, see the `sabre/vobject workaround:` comments. The
  workarounds go once sabre/vobject 5 ships the fixes.

[Unreleased]: https://github.com/rondeto/jscontact/compare/v0.2.2...HEAD
[0.2.2]: https://github.com/rondeto/jscontact/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/rondeto/jscontact/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/rondeto/jscontact/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/rondeto/jscontact/releases/tag/v0.1.0
