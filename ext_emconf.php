<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Solr Shield',
    'description' => 'Protects the Apache Solr search endpoint from bots and crawlers via URI obfuscation and bot detection.',
    'category' => 'frontend',
    'author' => 'Maik Schneider',
    'author_email' => 'schneider.maik@me.com',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
            'solr' => '12.0.0-13.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
