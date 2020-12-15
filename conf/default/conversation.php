<?php

//Inbenta Chatbot configuration
return [
    "default" => [
        "answers" => [
            "sideBubbleAttributes"  => [],
            "answerAttributes"      => [
                "ANSWER_TEXT",
            ],
            "maxOptions"            => 3,
            "maxRelatedContents"    => 2,
            'skipLastCheckQuestion' => true
        ],
        "forms" => [
            "allowUserToAbandonForm"    => true,
            "errorRetries"              => 2
        ],
        "lang"  => "en"
    ],
    'source' => 'slack',
    "user_type" => 0,
    "content_ratings" => [ // Remember that these ratings need to be created in your instance
        "enabled" => true,
        "ratings" => [
            [
                'id' => 1,
                'label' => 'yes',
                'comment' => false,
                'isNegative' => false,
                'style' => 'primary' // 'primary' or 'danger' or delete to get default style
            ],
            [
                'id' => 2,
                'label' => 'no',
                'comment' => false, // Whether clicking this option should ask for a comment
                'isNegative' => true,
                'style' => 'danger' // 'primary' or 'danger' or delete to get default style
            ]
        ]
    ],
    "digester" => [
        "button_title" => "", // Provide the attribute that contains the custom content-title to be displayed in multiple options (30 characters max)
        "url_buttons" => [
            "attribute_name"     => "",    // Provide the setting that contains an url+title to be displayed as URL button
            "button_title_var"     => "",  // Provide the property name that contains the button title in the button object
            "button_url_var"    => "",     // Provide the property name that contains the button URL in the button object
        ],
    ],
];
