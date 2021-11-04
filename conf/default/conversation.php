<?php

/**
 * DON'T MODIFY THIS FILE!!! READ 'conf/README.md' BEFORE.
 */

// Inbenta Chatbot configuration
return [
    'default' => [
        'answers' => [
            'sideBubbleAttributes'  => [],
            'answerAttributes'      => [
                'ANSWER_TEXT',
            ],
            'maxOptions'            => 3,
            'maxRelatedContents'    => 2,
            'skipLastCheckQuestion' => true
        ],
        'forms' => [
            'allowUserToAbandonForm'    => true,
            'errorRetries'              => 2
        ],
        'lang'  => 'en'
    ],
    'user_type' => 0,
    'source' => '360',
    'content_ratings' => [     // Remember that these ratings need to be created in your instance
        'enabled' => true,
        'ratings' => [
            [
                'id' => 1,
                'label' => 'yes',
                'comment' => false,
                'isNegative' => false
            ],
            [
                'id' => 2,
                'label' => 'no',
                'comment' => true,   // Whether clicking this option should ask for a comment
                'isNegative' => true
            ]
        ]
    ],
    'digester' => [
        // Consider that Whatsapp buttons only accepts 20 characters (24 for lists), if this is active, some of your content titles may not be displayed completely
        'active_buttons' => false, // if "false", instead of buttons there will be a numbered option
    ],
];
