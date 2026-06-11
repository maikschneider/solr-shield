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

### Notes

- The client-side JavaScript bot-detection layer is **not part of `main`**. It is under development on the `feature/js-bot-detection` branch (JavaScript + TypoScript wiring + detailed docs). `main` ships only the server-side validator, disabled by default, until that work is complete.
