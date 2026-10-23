<div align="center">

![Extension icon](Resources/Public/Icons/Extension.svg)

# TYPO3 extension `solr_shield`

[![Supported PHP versions](https://img.shields.io/packagist/dependency-v/maikschneider/solr-shield/php?logo=php)](https://packagist.org/packages/maikschneider/solr-shield)
[![Tests](https://img.shields.io/github/actions/workflow/status/maikschneider/solr-shield/tests.yml?label=tests&logo=github)](https://github.com/maikschneider/solr-shield/actions/workflows/tests.yml)
[![CGL](https://img.shields.io/github/actions/workflow/status/maikschneider/solr-shield/sca.yml?label=cgl&logo=github)](https://github.com/maikschneider/solr-shield/actions/workflows/sca.yml)
[![Coverage](https://codecov.io/gh/maikschneider/solr-shield/graph/badge.svg)](https://codecov.io/gh/maikschneider/solr-shield)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.md)

</div>

Protects the [EXT:solr](https://extensions.typo3.org/extension/solr) search endpoint from bots and crawlers that enumerate `tx_solr[…]` parameters to generate thousands of unique, uncacheable requests.

```
Before: /search?tx_solr[q]=foo&tx_solr[filter][0]=type:pages&tx_solr[page]=2
After:  /search?_ss=eyJwIjoidHhfc29sciU1QnElNUQ9…
```

## ✨ Features

* Facet, pagination, sorting and other `typolink()` links carry one signed `_ss` parameter instead of `tx_solr[…]`
* Search form fields are renamed in the rendered HTML and get a signed hidden field, so search works without JavaScript
* Unsigned `tx_solr` requests and suggest requests are redirected back or answered with `403`
* No template changes, configured per site

> [!NOTE]
> A behavioural bot-detection layer is in development ([#8](https://github.com/maikschneider/solr-shield/issues/8)). Its settings ship disabled; keep `solrShield.botDetection.enabled` off until it lands.

## 🔥 Installation

```bash
composer require maikschneider/solr-shield
```

Add the site set **Solr Shield** (`maikschneider/solr-shield`) to your site, then flush all caches.

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| TYPO3 | `^13.4 \|\| ^14.3` |
| EXT:solr | `^13.0 \|\| ^14.0` |

## ⚙️ Configuration

```yaml
# config/sites/<site>/settings.yaml
solrShield:
  uriObfuscation:
    enabled: true
    rejectAction: redirect  # or 403
```

## 📙 Documentation

How the signed parameter, the form rewrite and the middleware work, plus known limitations: [docs.typo3.org](https://docs.typo3.org/p/maikschneider/solr-shield/main/en-us/).

## 🧑‍💻 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
