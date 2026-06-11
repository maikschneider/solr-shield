# Contributing to Solr Shield

Thanks for considering a contribution! Bug reports, ideas, and pull requests are all welcome.

## Local Development Setup

This is a standard Composer-based TYPO3 extension.

```bash
git clone <your-fork>
cd solr-shield
composer install
```

## Quality Tooling

Before opening a pull request, run the static-analysis suite and fix anything it flags:

```bash
composer ci:sca   # check everything (read-only)
composer fix      # auto-fix what can be fixed
```

`ci:sca` runs, in order:

| Step | Tool |
|------|------|
| `ci:composer:normalize` | `ergebnis/composer-normalize` |
| `ci:editorconfig:lint` | `armin/editorconfig-cli` |
| `ci:php:lint` | `php -l` |
| `ci:php:fixer` | `friendsofphp/php-cs-fixer` (TYPO3 CGL) |
| `ci:language:lint` | `move-elevator/composer-translation-validator` |
| `ci:php:stan` | `phpstan/phpstan` + `saschaegerer/phpstan-typo3` (level 7) |
| `ci:rector` | `ssch/typo3-rector` |
| `ci:typoscript:lint` | `helmich/typo3-typoscript-lint` |

The same suite runs in CI on every push and pull request (`.github/workflows/sca.yml`).

## Coding Standards

- PHP code follows the TYPO3 Coding Guidelines (enforced by php-cs-fixer).
- `declare(strict_types=1);` in every PHP file.
- New behaviour should be configurable via the site settings, not hardcoded.

## A Note on the JavaScript

`Resources/Public/JavaScript/solr-shield.js` is a work in progress. If you'd like to help finish the client-side bot-detection layer, that's a great place to start — open an issue first so we can align on the approach.

## Pull Requests

1. Branch from `main`.
2. Keep changes focused; one logical change per PR.
3. Update `CHANGELOG.md` under `## [Unreleased]`.
4. Make sure `composer ci:sca` is green.
