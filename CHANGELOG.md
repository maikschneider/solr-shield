# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-10-23

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
- **Bot detection.** `solr-shield.js` collects behavioural signals (elapsed time, mouse, touch, scroll and key events, `navigator.webdriver`, screen and viewport size) and sends them as `_solr_bot` with Solr form submissions and `_ss` links. The site set includes the script only when `solrShield.botDetection.enabled` is set, which stays off by default because visitors without JavaScript can no longer search once it is on ([#8](https://github.com/maikschneider/solr-shield/issues/8)).
- **Documentation on docs.typo3.org**: introduction, installation, site settings, how the signed parameter, the form rewrite and the middleware work, and known problems. The README is now a short overview that links there ([#5](https://github.com/maikschneider/solr-shield/issues/5)).
- **Integration chapter in the documentation**: what to keep in mind with custom templates, forms, autocomplete, JavaScript, URL placeholders, copied EXT:solr scripts and non-HTML responses, plus a test checklist ([#17](https://github.com/maikschneider/solr-shield/issues/17)).
- Server-side bot-detection validator (`BotDetectionService`), shipped **disabled by default**.
- **Extension icon** in the style of the other `maikschneider/*` extensions: a shield with a magnifying glass, white on black ([#3](https://github.com/maikschneider/solr-shield/issues/3)).
- **Unit and functional tests.** Unit tests cover the token, the bot-detection scoring and both link listeners; functional tests run the middleware stack through frontend sub-requests: signed, unsigned and forged payloads, `_s[…]` form fields, the suggest page type, both reject actions, disabled obfuscation and the HTML rewrite. CI runs them for PHP 8.2–8.4 on TYPO3 13.4 and 14.3 and uploads coverage to Codecov ([#4](https://github.com/maikschneider/solr-shield/issues/4)).

### Changed

- **Tooling matches the other `maikschneider/*` extensions.** DDEV setup with `ddev init-typo3`, PHPStan level 8 without baseline, Rector and YAML lint in `composer sca` / `ci:sca`, SCA workflow inside DDEV with pull request annotations, TER release workflow and `packaging_exclude.php` ([#2](https://github.com/maikschneider/solr-shield/issues/2)).
- **Requires TYPO3 `^13.4 || ^14.3` and EXT:solr `^13.0 || ^14.0`.** EXT:solr 14 needs TYPO3 14.3; `dev-main` is no longer accepted ([#2](https://github.com/maikschneider/solr-shield/issues/2)).
- **Rejected requests get a `403` by default.** `solrShield.uriObfuscation.rejectAction` now defaults to `403`; set it to `redirect` to send visitors back instead ([#7](https://github.com/maikschneider/solr-shield/issues/7)).

### Fixed

- **No open redirect on rejection.** With `rejectAction: redirect`, the `Referer` is only followed when it has the scheme, host and port of the request; any other referer redirects to the site root ([#7](https://github.com/maikschneider/solr-shield/issues/7)).
- **Bot detection rejects what it claims to.** A `navigator.webdriver` flag or the headless 800×600 screen now rejects on its own instead of counting as one failed check out of ten; non-numeric client values count as zero instead of ending in a `TypeError` (HTTP 500); `securityLevel` is clamped to 0–10 ([#8](https://github.com/maikschneider/solr-shield/issues/8)).
- **Autocomplete works with URI obfuscation enabled.** EXT:solr's `data-suggest` URLs get a signed `_ss`, so suggest requests are no longer rejected, and signed `additionalFilters` now reach EXT:solr next to the typed `tx_solr[queryString]` ([#13](https://github.com/maikschneider/solr-shield/issues/13)).
- **Date and numeric range facets work with URI obfuscation and bot detection.** EXT:solr's range facet scripts look for `___FROM___` and `___TO___` in a URL template that now sits inside `_ss`, and navigate past `solr-shield.js`. The HTML middleware swaps them for patched copies for EXT:solr 13 and 14 that fill the range into the payload and navigate through a signed link click ([#8](https://github.com/maikschneider/solr-shield/issues/8)).
