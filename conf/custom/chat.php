<?php

/**
 * DON'T MODIFY THIS FILE!!! READ "conf/README.md" BEFORE.
 */

// Inbenta Hyperchat configuration
return [
    'chat' => [
        'enabled' => true,
        'version' => '1',
        'appId' => '',
        'secret' => '',
        'roomId' => 1,             // Numeric value, no string (without quotes)
        'lang' => 'en',
        'source' => 3,             // Numeric value, no string (without quotes)
        'guestName' => '',
        'guestContact' => '',
        'regionServer' => 'us',
        'server' => '<server>',    // Your HyperChat server URL (ask your contact person at Inbenta)
        'server_port' => 443,
        'surveyId' => '1',
        'queue' => [
            'active' => false
        ]
    ],
    'triesBeforeEscalation' => 2,
    'negativeRatingsBeforeEscalation' => 0,
    'messenger' => [
        'auht_url' => '',
        'key' => '',
        'secret' => '',
        'webhook_secret' => ''
    ]
];
