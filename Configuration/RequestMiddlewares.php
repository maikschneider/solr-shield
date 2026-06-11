<?php

declare(strict_types=1);

use MaikSchneider\SolrShield\Middleware\HtmlOutputObfuscationMiddleware;
use MaikSchneider\SolrShield\Middleware\SolrShieldMiddleware;

return [
    'frontend' => [
        'maikschneider/solr-shield' => [
            'target' => SolrShieldMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-core/request-token-middleware',
            ],
        ],
        'maikschneider/solr-shield-html' => [
            'target' => HtmlOutputObfuscationMiddleware::class,
            'after' => [
                'maikschneider/solr-shield',
            ],
        ],
    ],
];
