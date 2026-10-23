<?php

declare(strict_types=1);

return [
    'frontend' => [
        'maikschneider/solr-shield-test/echo' => [
            'target' => \MaikSchneider\SolrShieldTest\EchoMiddleware::class,
            'after' => [
                'maikschneider/solr-shield',
                'maikschneider/solr-shield-html',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
