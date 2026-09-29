# rondeto/jscontact

A PHP library for [JSContact](https://www.rfc-editor.org/rfc/rfc9553.html), the JSON representation of
contact data, and for converting between vCard and JSContact.

> **Status: early development (`0.x`).** Nothing is usable yet, and the public API may change in any
> release until `1.0`.

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
- **Lenient on read, strict on write.** A bad value produces a warning, not a failed card.
- **Warnings, not silent losses.** Every conversion returns what it could not read or had to fix.
- **Stable identifiers.** Converting the same card twice yields the same JSContact map keys: the vCard
  `PROP-ID` when present, otherwise a positional key (`k1`, `k2`, …) per map.

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
| Model and JSON: core properties                  | ⏳ Planned  |
| vCard ⇄ JSContact: core properties               | ⏳ Planned  |
| Full RFC 9553 / 9554 / 9555 coverage             | ⏳ Planned  |

Core properties: `uid`, `prodId`, `kind`, `members`, `name`, `nicknames`, `emails`, `phones`,
`addresses`, `onlineServices`, `links`, `notes`, `keywords`.

## Requirements

- PHP 8.4 or later
- `sabre/vobject` 4.5.6+ or 5.x

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
