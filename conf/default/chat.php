<?php

// Inbenta Hyperchat configuration
return [
    'chat' => [
        'enabled' => false,
        'version' => '1',
        'appId' => '',
        'secret' => '',
        'roomId' => 1, // Numeric value, no string (without quotes)
        'lang' => 'en',
        'source' => 3, // Numeric value, no string (without quotes)
        'guestName' => '',
        'guestContact' => '',
        'regionServer' => 'us',
        'server' => 'hyperchat-{region}.inbenta.chat', // Your HyperChat server URL (ask your contact person at Inbenta)
        'server_port' => 443
    ],
    'triesBeforeEscalation' => 0,
    'negativeRatingsBeforeEscalation' => 0,
    'messenger' => [
        'auht_url' => '',
        'key' => '',
        'secret' => '',
        'webhook_secret' => ''
    ]
];
