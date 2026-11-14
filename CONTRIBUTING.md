# Contributing

Thank you for helping. This is a small plugin with a clear scope (see [docs/PRD.md](docs/PRD.md)); changes that keep it small are the easiest to accept.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/newsapi-pull.git
cd newsapi-pull
composer install
```

No WordPress install is needed: the tests run against a small stub layer in `tests/stubs/`.

## Tests and checks

```sh
composer test      # PHPUnit
composer analyse   # PHPStan, level 8
composer lint      # phpcs, PSR-12
composer check     # all three
```

CI runs the same checks on PHP 8.1, 8.2 and 8.3. Write 8.1-compatible code: no readonly classes, no DNF types, no `true` type, no constants in traits.

No test may call NewsAPI.org. HTTP is mocked through `WpStub::$responses`.

## Style

- PSR-12, `declare(strict_types=1);` in every file, namespace `IndianOpinion\NewsApiPull`.
- Typed exceptions, never silent failure, never the `@` operator.
- Escape every output, validate every input, check `manage_options` and a nonce on every admin action.
- Prose: short sentences, plain words, British spelling. No em dashes.

## Pull requests

- One change per pull request, with a test for each behaviour it adds or fixes.
- Update `CHANGELOG.md` under Unreleased.
- If a change touches a numbered case in the PRD, say which.
- Rules that will not change: drafts only, the environment rule for the free plan only gets stricter, the key never leaves the header, and the plain key is frozen.

A sign-off line (DCO) is not required.
