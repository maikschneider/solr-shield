---
project: solr-shield
task: Extract solr-shield from sme-website-typo3 into a standalone, public-release TYPO3 extension
effort: E3
phase: execute
progress: 0/18
mode: algorithm
started: 2026-06-11
updated: 2026-06-11
---

# Solr Shield — Ideal State Articulation

## Problem

`solr-shield` lives inside the `sme-website-typo3` monorepo (`packages/solr-shield`). It was written as a vendor package against a single agency site and cannot be published as-is: its `composer.json` is a copy of an unrelated extension (Codeception acceptance stack, `phar-composer`, a `create-libs` step pulling in `php-css-parser`/`php-font-lib`, the full base-distribution dev dependency list), its `README.md` documents an older design (`UserFunc/TokenRenderer`, `_sst` token param, 10-minute token expiry) that no longer matches the shipped code, and it has no license, changelog, quality tooling, or CI. The client-side JavaScript bot-detection layer is a work in progress and is known not to function yet.

## Vision

A clean, standalone repository at `~/Sites/solr-shield` that another TYPO3 developer can `composer require` and trust — accurate README that matches the actual code, a slim and correct `composer.json`, GPL-2.0 license, and the same calibre of quality tooling (php-cs-fixer, PHPStan, Rector, editorconfig, composer-normalize, TypoScript lint, translation validation) and CI that the `tca-api` reference extension carries. The WIP JavaScript is preserved honestly and labelled as such, not hidden or half-fixed.

## Out of Scope

- Fixing or completing the client-side JavaScript bot detection — the user explicitly states it is still in progress; it is preserved verbatim and documented as WIP.
- Adding a test suite (PHPUnit/functional) — quality tooling means linters + static analysis, not new tests.
- Publishing to Packagist or pushing to a remote — local repo + initial commit only.
- Changing the security design or behaviour of the PHP middleware/event-listeners.
- Touching the original `packages/solr-shield` source (extraction is a copy, not a move).

## Constraints

- TYPO3 conventions: Composer-first, extension key `solr_shield`, PSR-4 namespace `MaikSchneider\SolrShield\`, TYPO3 CGL via php-cs-fixer.
- bun/TypeScript rules do not apply — this is a PHP/TYPO3 extension; tooling is Composer-based.
- No agency-identifying data may ship (verified: source scan for sme/xima/dkfz/secrets returned clean).
- Mirror `tca-api` conventions for all quality config and CI, scaled down to this extension's surface.
- Personal vendor: author Maik Schneider, email schneider.maik@me.com, license GPL-2.0-or-later.

## Goal

Produce `~/Sites/solr-shield` as an initialised git repository containing the extracted extension code, a slim correct `composer.json`, an accurate README matching the shipped PHP code, GPL-2.0 license + supporting docs, and configured quality tooling + CI mirroring `tca-api` — with the WIP JavaScript preserved and clearly labelled — committed as an initial commit.

## Criteria

- [ ] ISC-1: `~/Sites/solr-shield/.git` exists and `git log` shows ≥1 commit on `main`.
- [ ] ISC-2: All PHP `Classes/`, `Configuration/`, `Resources/` files copied unchanged from source (byte-identical to origin except where intentionally tuned).
- [ ] ISC-3: `composer.json` has no Codeception/`phar-composer`/`create-libs`/base-distribution dev cruft.
- [ ] ISC-4: `composer.json` runtime `require` constrains `apache-solr-for-typo3/solr` to a real version range (not `*`).
- [ ] ISC-5: `composer.json` declares runtime dep on `typo3/cms-frontend` (used by `AfterLinkIsGeneratedEvent`).
- [ ] ISC-6: `composer.json` `require-dev` contains php-cs-fixer, phpstan, saschaegerer/phpstan-typo3, ssch/typo3-rector, editorconfig-cli, composer-normalize, typoscript-lint, translation-validator.
- [ ] ISC-7: `composer.json` `scripts` expose `ci:sca` aggregating all linters and a `fix` group.
- [ ] ISC-8: `composer validate --strict` passes.
- [ ] ISC-9: README documents the ACTUAL code: `HtmlOutputObfuscationMiddleware` field-rename + signed `_ss` token; no `_sst`, no `UserFunc/TokenRenderer`, no expiry claim.
- [ ] ISC-10: README marks the JavaScript bot-detection layer as work-in-progress / not yet functional.
- [ ] ISC-11: `LICENSE.md` (GPL-2.0) present.
- [ ] ISC-12: `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md` present and project-specific.
- [ ] ISC-13: `.editorconfig`, `.gitattributes`, `.gitignore` present and adapted to this extension's paths.
- [ ] ISC-14: `php-cs-fixer.php`, `phpstan.neon`, `rector.php` present and valid.
- [ ] ISC-15: `.github/workflows/sca.yml` present and references the composer ci scripts.
- [ ] ISC-16: `ext_emconf.php` constraints accept TYPO3 13.4–14 and PHP ≥8.2; metadata accurate.
- [ ] ISC-17: JavaScript file carries an explicit WIP banner comment.
- [ ] ISC-18: Anti: no agency/site-specific identifiers or secrets ship in any committed file.

## Test Strategy

| isc | type | check | threshold | tool |
|-----|------|-------|-----------|------|
| ISC-1 | cmd | `git -C ~/Sites/solr-shield log --oneline` | ≥1 line | Bash |
| ISC-2 | file | diff copied classes vs source | identical | Bash diff |
| ISC-3 | grep | no codeception/phar-composer in composer.json | 0 hits | Grep |
| ISC-4 | grep | solr constraint not `"*"` | matches range | Read |
| ISC-8 | cmd | `composer validate --strict` | exit 0 | Bash |
| ISC-9/10 | read | README content inspection | matches code | Read |
| ISC-14 | cmd | `php -l php-cs-fixer.php` / `php -l rector.php` | no errors | Bash |
| ISC-18 | grep | scan all files for identifiers/secrets | 0 hits | Grep |

## Features

| name | satisfies | depends_on | parallelizable |
|------|-----------|------------|----------------|
| repo-init | ISC-1 | — | no |
| code-copy | ISC-2 | repo-init | no |
| composer-clean | ISC-3,4,5,6,7,8 | code-copy | yes |
| readme-rewrite | ISC-9,10 | code-copy | yes |
| license-docs | ISC-11,12 | repo-init | yes |
| quality-config | ISC-13,14,15 | code-copy | yes |
| emconf-tune | ISC-16 | code-copy | yes |
| js-wip-banner | ISC-17 | code-copy | yes |
| public-scrub-verify | ISC-18 | all | no |

## Decisions

- 2026-06-11: Wrote ISA directly rather than via `Skill("ISA")` — preserves momentum on mechanical extraction work; ISA is still the system-of-record at `<project>/ISA.md`.
- 2026-06-11: Relaxed E3 thinking-floor and delegation-floor (show-your-math): this is single-author mechanical file extraction + config, not complex design. FirstPrinciples ("what must change to make this publishable") + ReReadCheck are the genuine thinking moves; spawning Forge/agents would add coordination noise for copy-and-tune work. Principal demonstrably penalizes over-process.
- 2026-06-11: Did NOT copy the original `composer.json` (cruft from another extension) or `README.md` (stale design docs) — rewrote both from scratch against the actual shipped code.
- 2026-06-11: Preserved JS verbatim per explicit user instruction ("JS-part is not working, still in progress"); added only a WIP banner, no logic changes.

## Verification

_(populated during VERIFY)_
