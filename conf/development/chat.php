<?php

// Inbenta Hyperchat configuration
return [
    'chat' => [
        'enabled' => false,
        'version' => '1',
        'appId' => '',
        'secret' => '',
        'roomId' => 1,             // Numeric value, no string (without quotes)
        'lang' => 'en',
        'source' => 3,             // Numeric value, no string (without quotes)
        'guestName' => '',
        'guestContact' => '',
        'regionServer' => '',
        'server' => '<server>',    // Your HyperChat server URL (ask your contact person at Inbenta)
        'server_port' => 443,
        'survey' => [
            'id' => '1',
            'active' => false,
            'confirmToStart' => true, //If 'false' survey will start without any confirmation
        ],
        'workTimeTableActive' => true, // if set to FALSE then chat is 24/7, if TRUE then we get the working hours from API
        'timetable' => [
            'monday'     => ['09:00-18:00'], //It can be this way: ['09:00-18:00', '20:00-23:00']
            'tuesday'    => ['09:00-18:00'],
            'wednesday'  => ['09:00-18:00'],
            'thursday'   => ['09:00-18:00'],
            'friday'     => ['09:00-18:00'],
            'saturday'   => ['09:00-18:00'],
            'sunday'     => [],
            'exceptions' => [
                //'2021-06-19' => [], // not working that day
                //'2021-06-15' => ['9:00-12:00'] // no matter which day of week is, that day agents only works from 9 to 12
            ]
        ],
        'timezoneWorkingHours' => 'America/New_York'
    ],
    'triesBeforeEscalation' => 2,
    'negativeRatingsBeforeEscalation' => 0
];
