# Contributing

## Setup

```sh
make install
```

## Before pushing

```sh
make qa
```

This applies Rector and PHP-CS-Fixer, then runs PHPStan (level `max`) and PHPUnit. CI runs the same
checks in dry-run mode, on PHP 8.4 and 8.5, and once with the lowest allowed dependency versions.

## Tests

Examples are the specification. Every example from RFC 9553, RFC 9555 and RFC 9982 must be covered by a
test, named after its figure (for example `testRfc9555Figure12`).

Test fixtures must record their origin and license in `tests/Fixtures/SOURCES.md`. Only copy files whose
license allows it.

## Commits

Commits follow [Conventional Commits](https://www.conventionalcommits.org/): `feat`, `fix`, `refactor`,
`test`, `docs`, `chore`, `ci`.
