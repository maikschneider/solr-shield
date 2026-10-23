# Solr Shield

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE.md)
[![TYPO3 13 | 14](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014.3-orange.svg)](https://get.typo3.org/)

TYPO3 extension that protects the [Apache Solr](https://extensions.typo3.org/extension/solr) search endpoint from bots and crawlers that enumerate search parameters (`tx_solr[filter][…]`, `tx_solr[q]`, the suggest/autocomplete endpoint) to generate thousands of unique, uncacheable requests.

**URI Obfuscation** — `tx_solr` parameters never appear in URLs or HTML form fields; every Solr request must carry a single server-signed `_ss` token. This layer is server-side, requires no JavaScript, and needs no changes to your existing Solr templates.

> **Bot detection (JavaScript) is not part of `main`.** A second, behavioural bot-detection layer is under development ([#8](https://github.com/maikschneider/solr-shield/issues/8)). The server-side validator (`BotDetectionService`) ships on `main` but is **disabled by default** because it depends on that client-side layer. Until it lands, leave `solrShield.botDetection.enabled` off.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| TYPO3 CMS | `^13.4 \|\| ^14.3` |
| apache-solr-for-typo3/solr | `^13.0 \|\| ^14.0` |

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

Bot-detection settings exist but default to **disabled** on `main` (the client-side layer they depend on is not built yet, see [#8](https://github.com/maikschneider/solr-shield/issues/8)). `solrShield.botDetection.enabled` defaults to `false`; the remaining keys (`minFormTimeout`, `securityLevel`, `requireInteraction`) only take effect once it is enabled together with the JavaScript layer.

You can also override the obfuscation settings in `config/sites/<site>/settings.yaml`:

```yaml
solrShield:
  uriObfuscation:
    enabled: true
    rejectAction: redirect
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

### Protection 2 — Bot Detection (in development)

A second, optional layer scores behavioural signals (elapsed time, mouse/scroll/key/touch interaction, `navigator.webdriver`, screen/viewport dimensions) to reject headless and scripted clients. The server-side validator (`BotDetectionService`) ships on `main`, but the client-side JavaScript that collects the signals — and the TypoScript that wires it up — is not built yet ([#8](https://github.com/maikschneider/solr-shield/issues/8)), so the layer is **not yet operational**.

Bot detection therefore defaults to **off** on `main`. Enabling it without the client-side layer would reject every form submission. Track or contribute to the work in [#8](https://github.com/maikschneider/solr-shield/issues/8).

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
│   └── TypoScript/setup.typoscript                  # (JS wiring follows with #8)
├── Resources/
│   └── Private/Language/locallang.xlf
├── composer.json
├── ext_emconf.php
└── ext_localconf.php
```

---

## Development

```bash
ddev start
ddev composer install
ddev init-typo3
ddev composer sca      # fix what can be fixed, then lint and analyse
ddev composer ci:sca   # read-only, as in CI
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

---

## License

[GPL-2.0-or-later](LICENSE.md) — Maik Schneider.
