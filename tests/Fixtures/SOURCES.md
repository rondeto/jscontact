# Fixture sources

Every fixture copied from elsewhere is listed here, with its origin, its license and any change made to it.

## `Rfc9553/`

JSON examples from [RFC 9553](https://www.rfc-editor.org/rfc/rfc9553.html), "JSContact: A JSON Representation of
Contact Data", by R. Stepanek and M. Loffredo. `figure-NN.json` is Figure NN.

Copyright (c) 2024 IETF Trust and the persons identified as the document authors. Used under the
[IETF Trust Legal Provisions](https://trustee.ietf.org/license-info).

Changes:

- Figures that show properties rather than a whole object are wrapped in `{ }`. Figure 18 shows a property of a
  Name, so it is also wrapped in `"name"`.
- Figure 3 is typed by hand: its caption appears twice in the RFC text, which confused the extraction.
- Figure 35 splits its `data:` URI over several lines "only for demonstration purposes" (RFC 9553): the lines are
  joined.
- Figure 36 misses the closing brace of `directories`: it is added.
- Figures 2 and 5 are ABNF, not JSON: they are not included.

## `Rfc9555/`

vCard and JSON examples from [RFC 9555](https://www.rfc-editor.org/rfc/rfc9555.html), "JSContact: Converting from
and to vCard", by M. Loffredo and R. Stepanek. `figure-NN.vcf` is the vCard of Figure NN and `figure-NN.json` the
JSContact it converts to. Only the figures of the properties this library converts are included.

Copyright (c) 2024 IETF Trust and the persons identified as the document authors. Used under the
[IETF Trust Legal Provisions](https://trustee.ietf.org/license-info).

Changes:

- Folded lines are folded with a single space, as RFC 6350 requires; the RFC indents them further for readability.
- Map keys follow this library's naming (`PHONE-1`, `EMAIL-1`, `OS-1`, `TITLE-1`) where the figure uses other ones:
  `p1` in Figures 1 and 40, `t1` in Figures 3 and 4, `email1` in Figure 46, `os1` in Figure 47. RFC 9555 leaves the
  choice of keys to implementations.
- Figures 3 and 4: the title has `"kind": "title"`, as TITLE converts to in section 2.9.6 (Figure 27).
- Figure 9: the death date has `"day": 15` where the figure repeats `"year"` (the vCard says `DEATHDATE:19960415`).
- Figure 15: the address components are in the order of the ADR value, which section 2.6.1 requires; the figure lists
  the street number and name first.
- Figure 20: `vCardName` is set to `"socialprofile"`, which section 2.7.5 allows, so that the property converts back
  to SOCIALPROFILE.
- Figure 24: no `uid`, since the vCard has no UID and RFC 9982 forbids generating one.
- Figures 27 and 40: the group is kept in `vCardParams`, which section 2.3.9 allows, so that it survives a round trip.

`tests/VCard/Rfc9555JsonToVCardTest.php` also uses Figures 48 to 53. Figure 53 follows
[erratum 8786](https://www.rfc-editor.org/errata/eid8786): the street number is the 11th ADR component, and the
street name the 12th, as RFC 9554 defines.

## `CozyVcard/`

vCards exported by Android, Apple, Google, iOS and Firefox OS address books, from the test suite of
[cozy-vcard](https://github.com/cozy/cozy-vcard/tree/master/test), commit `f4420db849c966ead553fb0985a077b4201a20a5`.

Copyright (c) 2012 Cozy Cloud. MIT License, see [`CozyVcard/LICENSE`](CozyVcard/LICENSE).

Unchanged. `google-full.vcf` has a broken line folding at line 27, which the tests expect to be reported.
