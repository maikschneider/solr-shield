<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Solr Shield',
    'description' => 'Protects the Apache Solr search endpoint from bots and crawlers that enumerate search parameters.',
    'category' => 'fe',
    'author' => 'Maik Schneider',
    'author_email' => 'schneider.maik@me.com',
    'state' => 'beta',
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'frontend' => '13.4.0-14.99.99',
            'solr' => '13.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
