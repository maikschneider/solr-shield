<?php

declare(strict_types=1);

return [
    'frontend' => [
        'maikschneider/solr-shield' => [
            'target' => \MaikSchneider\SolrShield\Middleware\SolrShieldMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-core/request-token-middleware',
            ],
        ],
        'maikschneider/solr-shield-html' => [
            'target' => \MaikSchneider\SolrShield\Middleware\HtmlOutputObfuscationMiddleware::class,
            'after' => [
                'maikschneider/solr-shield',
            ],
        ],
    ],
];
