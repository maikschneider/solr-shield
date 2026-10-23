# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial public extraction of the extension from its origin project.
- **URI Obfuscation** layer (server-side, JavaScript-free):
  - `AfterUriIsProcessedEventListener` encodes Solr `SearchUriBuilder` URLs (facets, pagination, sorting) into a signed `_ss` payload.
  - `AfterLinkIsGeneratedEventListener` encodes `typolink()`-generated Solr URLs, skipping unresolved routing-template links.
  - `HtmlOutputObfuscationMiddleware` renames `tx_solr[` form fields to `_s[` in the rendered HTML and injects a signed `_ss` token.
  - `SolrShieldMiddleware` validates and decodes incoming requests, rejecting unsigned direct `tx_solr` and suggest requests (`redirect` or `403`).
  - `TokenService` — stateless HMAC-SHA256 token derived from the TYPO3 `encryptionKey`.
- **Bot Detection** layer:
  - `BotDetectionService` scores a behavioural-signal payload server-side (two hard checks + eight scored checks against a configurable `securityLevel`).
- Per-site configuration via site settings (`solrShield.uriObfuscation.*`, `solrShield.botDetection.*`).
- Quality tooling: php-cs-fixer, PHPStan (+ phpstan-typo3), Rector, editorconfig-cli, composer-normalize, TypoScript lint, translation validation, and a GitHub Actions SCA workflow.
- Server-side bot-detection validator (`BotDetectionService`), shipped **disabled by default**.

- **Unit and functional tests.** Unit tests cover the token, the bot-detection scoring and both link listeners; functional tests run the middleware stack through frontend sub-requests: signed, unsigned and forged payloads, `_s[…]` form fields, the suggest page type, both reject actions, disabled obfuscation and the HTML rewrite. CI runs them for PHP 8.2–8.4 on TYPO3 13.4 and 14.3 and uploads coverage to Codecov ([#4](https://github.com/maikschneider/solr-shield/issues/4)).

### Changed

- **Tooling matches the other `maikschneider/*` extensions.** DDEV setup with `ddev init-typo3`, PHPStan level 8 without baseline, Rector and YAML lint in `composer sca` / `ci:sca`, SCA workflow inside DDEV with pull request annotations, TER release workflow and `packaging_exclude.php` ([#2](https://github.com/maikschneider/solr-shield/issues/2)).
- **Requires TYPO3 `^13.4 || ^14.3` and EXT:solr `^13.0 || ^14.0`.** EXT:solr 14 needs TYPO3 14.3; `dev-main` is no longer accepted ([#2](https://github.com/maikschneider/solr-shield/issues/2)).

### Fixed

- **Autocomplete works with URI obfuscation enabled.** EXT:solr's `data-suggest` URLs get a signed `_ss`, so suggest requests are no longer rejected, and signed `additionalFilters` now reach EXT:solr next to the typed `tx_solr[queryString]` ([#13](https://github.com/maikschneider/solr-shield/issues/13)).

### Notes

- The client-side JavaScript bot-detection layer is **not part of `main`** yet ([#8](https://github.com/maikschneider/solr-shield/issues/8)). `main` ships only the server-side validator, disabled by default, until that work is complete.
