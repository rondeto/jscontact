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
