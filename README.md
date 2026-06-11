# Solr Shield

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE.md)
[![TYPO3 13](https://img.shields.io/badge/TYPO3-13.4-orange.svg)](https://get.typo3.org/version/13)

TYPO3 extension that protects the [Apache Solr](https://extensions.typo3.org/extension/solr) search endpoint from bots and crawlers that enumerate search parameters (`tx_solr[filter][…]`, `tx_solr[q]`, the suggest/autocomplete endpoint) to generate thousands of unique, uncacheable requests.

Two independent protection layers can be toggled per site:

1. **URI Obfuscation** — `tx_solr` parameters never appear in URLs or HTML form fields; every Solr request must carry a single server-signed `_ss` token.
2. **Bot Detection** — submissions from headless/scripted clients are rejected using behavioural signals.

Neither layer requires changes to your existing Solr templates.

> **Status:** The server-side URI-obfuscation layer is functional. The **client-side JavaScript bot-detection layer is a work in progress and not yet operational** — see [Client-side JavaScript](#client-side-javascript-work-in-progress) below.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| TYPO3 CMS | `^13.4 \|\| ^14.0` |
| apache-solr-for-typo3/solr | `^12.0 \|\| ^13.0` |

---

## Installation

```bash
composer require maikschneider/solr-shield
```

Then add the **Solr Shield** site set to your site configuration (TYPO3 backend → *Site Management → Sites → [your site] → Sets*), or declare it as a dependency in your sitepackage's set:

```yaml
# packages/your-sitepackage/Configuration/Sets/YourSet/config.yaml
dependencies:
  - maikschneider/solr-shield
```

Flush all caches after installation.

---

## Configuration

Settings are configured per site under *Site Management → Sites → [your site] → Solr Shield*. All settings ship with sensible defaults.

### URI Obfuscation

| Setting | Type | Default | Description |
|---|---|---|---|
| `solrShield.uriObfuscation.enabled` | bool | `true` | Enable/disable the obfuscation layer |
| `solrShield.uriObfuscation.rejectAction` | string | `redirect` | What to do with an unsigned direct `tx_solr` request: `redirect` (back to the referer) or `403` |

### Bot Detection

| Setting | Type | Default | Description |
|---|---|---|---|
| `solrShield.botDetection.enabled` | bool | `true` | Enable/disable bot detection |
| `solrShield.botDetection.minFormTimeout` | int | `3` | Minimum seconds between page load and form submission |
| `solrShield.botDetection.securityLevel` | int | `7` | Number of behavioural checks (out of 10) that must pass |
| `solrShield.botDetection.requireInteraction` | bool | `true` | Require at least one mouse-move or touch event |

You can also override these in `config/sites/<site>/settings.yaml`:

```yaml
solrShield:
  uriObfuscation:
    enabled: true
    rejectAction: redirect
  botDetection:
    enabled: true
    minFormTimeout: 3
    securityLevel: 7
    requireInteraction: true
```

---

## How It Works

### Protection 1 — URI Obfuscation

**Goal:** make `tx_solr[…]` parameters invisible in both URLs and HTML so bots cannot enumerate parameter combinations.

#### Token mechanism

`TokenService` derives a compact token: the first 12 bytes of `HMAC-SHA256(encryptionKey, "solr-shield")`, base64url-encoded — **16 characters**. Tokens are **stateless and do not expire**; they remain valid as long as the TYPO3 `encryptionKey` is unchanged. This is intentional — filter and pagination links are permanent and must not rot over time.

The signed payload carried on every request is a single `_ss` parameter:

```
_ss = base64( {"p": "<tx_solr query string>", "s": "<token>"} )
```

#### Filter / pagination / typolink URLs (server-side)

Two PSR-14 event listeners encode any URL that carries `tx_solr` parameters into the `_ss` payload:

- **`AfterUriIsProcessedEventListener`** — hooks Solr's `SearchUriBuilder` (`AfterUriIsProcessedEvent`) for facet, pagination and sorting links.
- **`AfterLinkIsGeneratedEventListener`** — hooks `typolink()` (`AfterLinkIsGeneratedEvent`) for any other generated Solr link. It deliberately skips Solr routing *template* links that still contain `###tx_solr:…###` placeholders, leaving those to the listener above.

```
Before: /search?tx_solr[q]=foo&tx_solr[filter][0]=type:pages&tx_solr[page]=2
After:  /search?_ss=eyJwIjoidHhfc29sciU1QnElNUQ9…
```

#### Search form (server-side, no JavaScript required)

`HtmlOutputObfuscationMiddleware` rewrites the rendered HTML response:

1. Replaces every `name="tx_solr[` attribute with `name="_s[`.
2. Injects a hidden `<input name="_ss">` carrying a signed empty-payload token into each form that now contains `_s[…]` fields.

On submission `SolrShieldMiddleware` remaps `_s[…]` back to `tx_solr[…]` and validates the `_ss` token — so the search form works **without any client-side JavaScript**.

#### Middleware validation (inbound)

`SolrShieldMiddleware` runs on every frontend request, positioned after site resolution (`typo3/cms-frontend/site`) and before the request-token middleware:

| Request | Action |
|---|---|
| `_s[…]` fields present | Remap to `tx_solr[…]` |
| Signed `_ss` present | Validate token → base64-decode → `parse_str` → inject `tx_solr` params → strip `_ss` |
| Direct `tx_solr` params without `_ss` | Reject (redirect / 403) |
| Suggest request (pageType `7384`) without `_ss` | Reject |
| No Solr params, or obfuscation disabled | Pass through unchanged |

### Protection 2 — Bot Detection

**Goal:** detect headless browsers and scripted clients from behavioural signals they cannot reliably reproduce.

`BotDetectionService` validates a base64-JSON `_solr_bot` payload server-side. Two checks are **always** enforced:

- `navigator.webdriver` must be absent/false
- Screen resolution must not be the `800×600` headless default

Eight further checks (elapsed time ≥ `minFormTimeout`, mouse/touch interaction, scroll, keyboard, viewport dimensions, sane timestamp) contribute to a score; the request passes when `score >= securityLevel` (default 7).

#### Client-side JavaScript (work in progress)

> ⚠️ **`Resources/Public/JavaScript/solr-shield.js` is a work in progress and is not yet operational.** It is shipped as-is so the moving parts are visible, but it does not currently produce a valid `_solr_bot` payload end-to-end. Until it is finished, run the extension with `solrShield.botDetection.enabled: false`, or rely on the server-side URI-obfuscation layer alone (which is fully functional and JavaScript-free).

When complete, the script is intended to collect behavioural signals (elapsed time, mouse/scroll/key/touch counts, `navigator.webdriver`, screen/viewport dimensions), write them into the hidden `_solr_bot` field immediately before submission, and patch the `data-suggest` autocomplete URL with the server-issued `_ss` token. Configuration is injected via TypoScript into `window.SolrShield`.

---

## File Structure

```
solr-shield/
├── Classes/
│   ├── EventListener/
│   │   ├── AfterLinkIsGeneratedEventListener.php   # Encodes typolink() Solr URLs
│   │   └── AfterUriIsProcessedEventListener.php    # Encodes Solr SearchUriBuilder URLs
│   ├── Middleware/
│   │   ├── HtmlOutputObfuscationMiddleware.php      # Renames tx_solr[ → _s[ in HTML, injects _ss
│   │   └── SolrShieldMiddleware.php                 # Validates & decodes incoming requests
│   └── Service/
│       ├── TokenService.php                         # HMAC token generation & validation
│       └── BotDetectionService.php                  # Bot-detection payload validation
├── Configuration/
│   ├── RequestMiddlewares.php                       # PSR-15 middleware registration
│   ├── Services.yaml                                # DI + event-listener registration
│   ├── Sets/SolrShield/                             # Site set: config, settings, setup
│   └── TypoScript/setup.typoscript                  # window.SolrShield config + JS include
├── Resources/
│   ├── Public/JavaScript/solr-shield.js             # Client-side bot detection (WIP)
│   └── Private/Language/locallang.xlf
├── composer.json
├── ext_emconf.php
└── ext_localconf.php
```

---

## Development

This repository uses Composer-based quality tooling. After `composer install`:

```bash
composer ci:sca   # run all static analysis (cs-fixer, phpstan, rector, lint, editorconfig, yaml/typoscript, translations)
composer fix      # auto-fix: cs-fixer, rector, editorconfig, composer normalize
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

---

## License

[GPL-2.0-or-later](LICENSE.md) — Maik Schneider.
