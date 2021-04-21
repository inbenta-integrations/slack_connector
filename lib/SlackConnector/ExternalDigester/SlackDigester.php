<?php

namespace Inbenta\SlackConnector\ExternalDigester;

use DOMDocument;
use DOMNode;
use DOMText;
use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Inbenta\ChatbotConnector\Utils\LanguageManager;

class SlackDigester extends DigesterInterface
{

    protected $conf;
    protected $session;
    protected $channel;
    /** @var LanguageManager */
    protected $langManager;
    protected $externalClient;
    protected $externalMessageTypes = array(
        'text',
        'button',
        'quickReply',
        'attachment',
        'sticker',
    );

    protected $apiMessageTypes = [
        'actionField',
        'answer',
        'polarQuestion',
        'multipleChoiceQuestion',
        'extendedContentsAnswer',
    ];

    protected $attachableFormats = [
        'jpg', 'jpeg', 'png', 'gif',
        'pdf', 'xls', 'xlsx', 'doc', 'docx',
        'mp4', 'avi',
        'mp3'
    ];

    const BLOCK_KIT_BUTTON_STYLE_EMPTY = '';
    const BLOCK_KIT_BUTTON_STYLE_PRIMARY = 'primary';
    const BLOCK_KIT_BUTTON_STYLE_DANGER = 'danger';

    public function __construct($langManager, $conf, $session)
    {
        $this->langManager = $langManager;
        $this->channel = 'Slack';
        $this->conf = $conf;
        $this->session = $session;
    }

    /**
     * Sets the external client
     */
    public function setExternalClient($externalClient)
    {
        $this->externalClient = $externalClient;
    }

    /**
     * Returns the name of the channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * Checks if a request belongs to the digester channel
     *
     * @param string $request
     *
     * @return bool
     */
    public static function checkRequest($request)
    {
        $request = json_decode($request);

        $isPage = isset($request->object) && $request->object == "page";
        $isMessaging = isset($request->entry) && isset($request->entry[0]) && isset($request->entry[0]->messaging);
        if ($isPage && $isMessaging && count((array)$request->entry[0]->messaging)) {
            return true;
        }
        return false;
    }

    /**
     * Formats a channel request into an Inbenta Chatbot API request
     *
     * @param string $request
     *
     * @return array
     */
    public function digestToApi($request)
    {
        $request = json_decode($request);

        // Sometimes Slack sends requests as application/x-www-form-urlencoded...
        if (is_null($request)) {
            parse_str(file_get_contents('php://input'), $request);
            if (isset($request['payload'])) {
                $request = json_decode($request['payload']);
            }
        }

        if (
            is_null($request) ||
            !isset($request->event) && !isset($request->actions) ||
            isset($request->event) && !isset($request->event->text) ||
            isset($request->actions) && !isset($request->actions[0])
        ) {
            return [];
        }

        if (isset($request->type)) {
            switch ($request->type) {
                case 'interactive_message':
                    $message = $request->actions[0];
                    break;
                case 'block_actions':
                    $message = $request->actions[0];
                    switch ($message->type) {
                        case "button":
                            unset($message->text);
                            // get the button value
                            $message->message = (object)[
                                "quick_reply" => (object)[
                                    "payload" => $message->value,
                                ]
                            ];
                            $message->type = 'quick_reply';
                            break;
                        case 'static_select':
                            unset($message->text);
                            // get selected value
                            $message->message = (object) [
                                "quick_reply" => (object) [
                                    "payload" => $message->selected_option->value,
                                ]
                            ];
                            $message->type = 'quick_reply';
                            break;
                        default:
                            break;
                    }
                    break;
                default:
                    $message = $request->event;
                    break;
            }
        } else {
            $message = $request->event;
        }

        $output = [];

        $msgType = $this->checkExternalMessageType($message);
        $digester = 'digestFromSlack' . ucfirst($msgType);

        $digestedMessage = $this->$digester($message);
        $output[] = $digestedMessage;

        if (isset($output[0]['message']) && isset($output[0]['media'])) {
            $output = [
                0 => ["message" => $output[0]['message']],
                1 => ["media" => $output[0]['media']]
            ];
        }

        return $output;
    }

    /**
     * Formats an Inbenta Chatbot API response into a channel request
     *
     * @param object $request
     * @param string $lastUserQuestion
     *
     * @return array
     *
     * @throws Exception
     */
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        $messages = [];
        // parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif ($this->checkApiMessageType($request) !== null) {
            $messages = ['answers' => $request];
        } elseif (count($messages) && isset($messages[0]) && $this->hasTextMessage($messages[0])) {
            // if the first message contains text although it's an unknown message type, send the text to the user
            $output = [];
            $output[] = $this->digestFromApiAnswer($messages[0]);
            return $output;
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output = [];
        foreach ($messages as $msg) {
            $msgType = $this->checkApiMessageType($msg);
            $digester = 'digestFromApi' . ucfirst($msgType);
            $digestedMessage = $this->$digester($msg, $lastUserQuestion);

            $output[] = $digestedMessage;
        }

        return $output;
    }

    /**
     * Classifies the external message into one of the defined $externalMessageTypes
     *
     * @param string $message
     *
     * @return mixed
     */
    protected function checkExternalMessageType($message)
    {
        foreach ($this->externalMessageTypes as $type) {
            $checker = 'isSlack' . ucfirst($type);
            if ($this->$checker($message)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Classifies the API message into one of the defined $apiMessageTypes
     *
     * @param string $message
     *
     * @return mixed|null
     */
    protected function checkApiMessageType($message)
    {
        foreach ($this->apiMessageTypes as $type) {
            $checker = 'isApi' . ucfirst($type);
            if ($this->$checker($message)) {
                return $type;
            }
        }
        return null;
    }

    /********************** EXTERNAL MESSAGE TYPE CHECKERS **********************/

    /**
     * This check if the message is a simple text
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isSlackText($message)
    {
        return isset($message->text);
    }

    /**
     * This check if the message is a button type
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isSlackButton($message)
    {
        return isset($message->type) && ($message->type === 'button');
    }

    /**
     * This check if the message is a Quick Reply
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isSlackQuickReply($message)
    {
        return isset($message->message) && isset($message->message->quick_reply);
    }

    /**
     * This check if the message is a Sticker
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isSlackSticker($message)
    {
        return isset($message->message) && isset($message->message->attachments) && isset($message->message->sticker_id);
    }

    /**
     * This check if the message is an attachment
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isSlackAttachment($message)
    {
        return isset($message->message) && isset($message->message->attachments) && !isset($message->message->sticker_id);
    }

    /********************** API MESSAGE TYPE CHECKERS **********************/

    /**
     * This check if the message is a simple answer
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isApiAnswer($message)
    {
        return $message->type == 'answer';
    }

    /**
     * This check if the message is a Polar Question
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isApiPolarQuestion($message)
    {
        return $message->type == "polarQuestion";
    }

    /**
     * This check if the message is a Multiple Choice Question
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isApiMultipleChoiceQuestion($message)
    {
        return $message->type == "multipleChoiceQuestion";
    }

    /**
     * This check if the message is an Extended Contents Answer
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isApiExtendedContentsAnswer($message)
    {
        return $message->type == "extendedContentsAnswer";
    }

    /**
     * Check if the message in an action field
     *
     * @param $message
     * @return bool
     */
    protected function isApiActionField($message)
    {
        return $message->type == 'answer' && isset($message->actionField) && !empty($message->actionField);
    }

    /**
     * This check if the message has a text message
     *
     * @param object $message
     *
     * @return bool
     */
    protected function hasTextMessage($message)
    {
        return isset($message->message) && is_string($message->message);
    }


    /********************** SLACK MESSAGE DIGESTERS **********************/

    /**
     * This transform a Slack text into Inbenta API input
     *
     * @param object $message
     *
     * @return array
     */
    protected function digestFromSlackText($message)
    {
        $output = ['message' => ''];

        if ($message->text !== '') {
            $output['message'] = $message->text;
        }
        if (isset($message->files)) {
            $tmp = $this->mediaFileToHyperchat($message->files[0]);
            if (isset($tmp['media'])) {
                $output['media'] = $tmp['media'];
            }
        }
        if (!$this->session->get('chatOnGoing', false) && $output['message'] === '') {
            die;
        }

        return $output;
    }

    /**
     * This transform a Slack Button into Inbenta API input
     *
     * @param object $message
     *
     * @return array
     */
    protected function digestFromSlackButton($message)
    {
        return array(
            'message' => '',
            'option' => $message->value
        );
    }

    /**
     * This transform a Slack Quick Reply into Inbenta API input
     *
     * @param object $message
     *
     * @return array
     */
    protected function digestFromSlackQuickReply($message)
    {
        $answer = [];
        $quickReply = $message->message->quick_reply;
        $payload = json_decode($quickReply->payload, true);
        $option = isset($payload['option']) ? $payload['option'] : $payload;
        if (isset($payload['ratingData'])) {
            $answer = $option;
        } elseif (isset($message->action_id) && $message->action_id === "ACTION_FIELD") {
            $answer = $payload;
        } elseif (isset($option["escalateOption"])) {
            $answer = $payload;
        } else {
            if ($this->session->get('escalationStartFromMultiple', "") == $option) {
                $answer["message"] = "agent";
            } else {
                $answer =  [
                    "option" => $option,
                ];
            }
            $this->session->delete('escalationStartFromMultiple');
        }
        return $answer;
    }

    /**
     * This transform a Slack Attachment into Inbenta API input
     *
     * @param object $message
     *
     * @return array
     */
    protected function digestFromSlackAttachment($message)
    {
        $attachments = [];
        foreach ($message->message->attachments as $attachment) {
            $attachments[] = array('message' => $attachment->payload->url);
        }
        return ["multiple_output" => $attachments];
    }

    /**
     * This transform a Slack Sticker into Inbenta API input
     *
     * @param object $message
     *
     * @return array
     */
    protected function digestFromSlackSticker($message)
    {
        $sticker = $message->message->attachments[0];
        return array(
            'message' => $sticker->payload->url
        );
    }


    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    /**
     * This transform an Inbenta API Answer into Slack Block Kit response
     *
     * @param object $message
     *
     * @return array
     */
    protected function digestFromApiAnswer($message)
    {
        $output = [];
        $attachments = [];

        if (isset($message->attributes->SIDEBUBBLE_TEXT) && !empty($message->attributes->SIDEBUBBLE_TEXT)) {
            $message->message .= "\n" . $message->attributes->SIDEBUBBLE_TEXT;
        }

        // parse message HTML
        $nodesBlocks = $this->createNodesBlocks($message->message);

        // iterate each node
        foreach ($nodesBlocks as $nodeBlock) {
            // node are returned as array when images have been found
            if (is_array($nodeBlock)) {
                if (isset($nodeBlock['src'])) {
                    // image
                    $output[] = [
                        'type' => 'image',
                        'title' => [
                            'type' => 'plain_text',
                            'text' => !empty($nodeBlock['alt']) ? $nodeBlock['alt'] : 'image',
                            'emoji' => true
                        ],
                        'alt_text' => !empty($nodeBlock['alt']) ? $nodeBlock['alt'] : 'image',
                        'image_url' => $nodeBlock['src']
                    ];
                } else {
                    $output[] = $nodeBlock;
                }
            } else {
                // transform HTML Text node to Markdown
                $text = $this->toMarkdown($nodeBlock);

                if (!empty($text)) {
                    $output[] = $this->buildBlockKitText($text);
                }
            }
        }

        // add related
        if ($this->hasRelated($message)) {
            $related = $message->parameters->contents->related;
            $attachments = $this->buildRelatedMessage($related);
        }

        $message_notification = $this->createNotificationMessage($message->message);

        return $this->buildBlockKitResponse(
            $output,
            $message_notification,
            $attachments,
        );
    }

    /**
     * This transform an Inbenta API Multiple Choice Question into Slack Block Kit response
     *
     * @param object $message
     * @param string $lastUserQuestion
     *
     * @return array
     */
    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion): array
    {
        $buttonTitleAttribute = $this->getButtonTitleAttribute();
        $blocks = [
            $this->buildBlockKitText($message->message)
        ];

        foreach ($message->options as $option) {
            $blocks[] = $this->buildBlockKitText(
                isset($option->attributes->$buttonTitleAttribute)
                    ? '*' . $this->toMarkdown($option->attributes->$buttonTitleAttribute) . '*'
                    : '*' . $this->toMarkdown($option->label) . '*',
                'mrkdwn',
                $this->buildBlockKitButtonElement(
                    $this->langManager->translate('multiple-answer-button-title'),
                    (string)$option->value
                )
            );
            if (isset($option->attributes) && isset($option->attributes->DYNAMIC_REDIRECT) && $option->attributes->DYNAMIC_REDIRECT == 'escalationStart') {
                $this->session->set('escalationStartFromMultiple', $option->value);
            }
        }
        $blocks[] = ['type' => 'divider'];

        $message_notification = $this->createNotificationMessage($message->message);

        return $this->buildBlockKitResponse($blocks, $message_notification);
    }

    /**
     * This transform an Inbenta API Polar Question into Slack Block Kit response
     *
     * @param object $message
     * @param string $lastUserQuestion
     *
     * @return array
     */
    protected function digestFromApiPolarQuestion($message, $lastUserQuestion): array
    {
        $blocks = [
            $this->buildBlockKitText($this->toMarkdown($message->message))
        ];

        $elements = [];
        foreach ($message->options as $option) {
            $elements[] = $this->buildBlockKitButtonElement(
                $this->langManager->translate($option->label),
                json_encode(
                    [
                        "message" => $lastUserQuestion,
                        "option" => $option->value
                    ]
                )
            );
        }

        $blocks[] = $this->buildBlockKitButtons($elements);

        $message_notification = $this->createNotificationMessage($message->message);

        return $this->buildBlockKitResponse($blocks, $message_notification);
    }

    /**
     * This transform an Inbenta API Extended Contents Answer into Slack Block Kit response
     *
     * @param object $message
     *
     * @return array
     */
    protected function digestFromApiExtendedContentsAnswer($message): array
    {
        $buttonTitleAttribute = $this->getButtonTitleAttribute();
        $blocks = [
            $this->buildBlockKitText($this->toMarkdown($message->message))
        ];

        $elements = [];
        foreach ($message->subAnswers as $index => $option) {
            if (isset($option->parameters) && isset($option->parameters->contents) && isset($option->parameters->contents->title) && isset($option->parameters->contents->url)) {
                $text = "<" . $option->parameters->contents->url->value . "|";
                $text .= isset($option->attributes->$buttonTitleAttribute) ? isset($option->attributes->$buttonTitleAttribute) : $this->toMarkdown($option->message);
                $text .= ">";
                $blocks[] = $this->buildBlockKitText($text);
            } else {
                $elements[] = $this->buildBlockKitButtonElement(
                    isset($option->attributes->$buttonTitleAttribute)
                        ? $this->toMarkdown($option->attributes->$buttonTitleAttribute)
                        : $this->toMarkdown($option->message),
                    json_encode(
                        [
                            "extendedContentAnswer" => $option
                        ]
                    )
                );
            }
        }
        if (count($elements) > 0) {
            $blocks[] = $this->buildBlockKitButtons($elements);
        }

        $message_notification = $this->createNotificationMessage($message->message);

        return $this->buildBlockKitResponse($blocks, $message_notification);
    }

    /**
     * This transform an Inbenta API Action Field answer into Slack Block Kit response
     *
     * @param object $message
     * @return array
     */
    protected function digestFromApiActionField($message)
    {
        $blocks = [];

        $actionField = $message->actionField ?: false;

        if ($actionField) {
            if (isset($actionField->listValues) && isset($actionField->listValues->displayType)) {
                switch ($actionField->listValues->displayType) {
                    case 'dropdown':
                        $options = array_map(
                            function ($option) {
                                $option_message = strlen($option->option) > 61 ? $option->option : $option->option; //Max 75 characters minus 14 after the json_encode
                                return self::buildBlockKitSelectOption(
                                    $option->label[0],
                                    json_encode(
                                        [
                                            "message" => $option_message
                                        ]
                                    )
                                );
                            },
                            $actionField->listValues->values
                        );
                        $blocks[] = self::buildBlockKitStaticSelect(
                            $message->message,
                            $this->langManager->translate('action-field-select-placeholder'),
                            "ACTION_FIELD",
                            $options
                        );
                        break;
                    case 'buttons':
                        $blocks[] = self::buildBlockKitText(self::toMarkdown($message->message));
                        foreach ($actionField->listValues->values as $option) {
                            $blocks[] = self::buildBlockKitText(
                                $option->label[0],
                                "mrkdwn",
                                $this->buildBlockKitButtonElement(
                                    $this->langManager->translate('action-field-button-title'),
                                    json_encode(
                                        [
                                            "message" => $option->option,
                                            "option" => $option->option,
                                            "userMessage" => $option->option,
                                        ]
                                    ),
                                    "",
                                    true,
                                    "",
                                    "ACTION_FIELD",
                                ),
                            );
                        }
                        break;
                    default:
                        $blocks[] = self::buildBlockKitText($this->toMarkdown($message->message));
                        break;
                }
            } else {
                $blocks[] = self::buildBlockKitText($this->toMarkdown($message->message));
            }
        }
        $message_notification = $this->createNotificationMessage($message->message);

        return self::buildBlockKitResponse($blocks, $message_notification);
    }

    /********************** MISC **********************/

    /**
     * Check if it's the asking comment message answer
     *
     * @param object $message
     *
     * @return bool
     */
    protected function isCommentAnswer($message): bool
    {
        return $this->langManager->translate('ask_rating_comment') === $message->message;
    }

    /**
     * Return a content rating message question as a Slack Block Kit response
     *
     * @param array $ratingOptions
     * @param string $rateCode
     *
     * @return array
     */
    public function buildContentRatingsMessage($ratingOptions, $rateCode): array
    {
        $blocks = [
            $this->buildBlockKitText(
                $this->toMarkdown($this->langManager->translate('rate-content-intro'))
            )
        ];

        $elements = [];
        foreach ($ratingOptions as $option) {
            $elements[] = $this->buildBlockKitButtonElement(
                $this->langManager->translate($option['label']),
                json_encode(
                    [
                        'askRatingComment' => isset($option['comment']) && $option['comment'],
                        'isNegativeRating' => isset($option['isNegative']) && $option['isNegative'],
                        'ratingData' => [
                            'type' => 'rate',
                            'data' => [
                                'code' => $rateCode,
                                'value' => $option['id'],
                                'comment' => null
                            ]
                        ]
                    ],
                    true
                ),
                isset($option['style']) ? $option['style'] : '',
                true,
                '',
                'RATINGS_' . $option['id'],
            );
        }

        $blocks[] = $this->buildBlockKitButtons($elements);

        $message_notification = $this->createNotificationMessage($this->langManager->translate('rate-content-intro'));

        return $this->buildBlockKitResponse($blocks, $message_notification);
    }

    /**
     * Splits a message that contains an <img> tag into text/image/text and displays them in Slack
     *
     * @param object $message
     *
     * @return array
     */
    protected function handleMessageWithImages($message)
    {
        // remove \t \n \r and HTML tags (keeping <img> tags)
        $text = str_replace(
            ["\r\n", "\r", "\n", "\t"],
            '',
            strip_tags($message->message, "<img><a>")
        );

        $patternLink = '~<a(.*?)href="([^"]+)"(.*?)>(.*?)<\/a>~';

        $doc = new \DOMDocument();
        $doc->loadHTML($text);
        $tagsImg = $doc->getElementsByTagName('img');
        $tagsLinks = $doc->getElementsByTagName('a');

        $output = ["blocks" => []];

        if ($tagsImg->length > 0) {
            $textParts = [];
            foreach ($tagsImg as $tag) {
                $pos = mb_strpos($text, '<img');
                $part = mb_str_split($text, $pos);
                $textParts[] = $part[0];
                $pattern = '/<img.*?>/';
                $text = preg_replace($pattern, '', str_replace($part[0], '', $text), 1);

                $part[0] = preg_replace($patternLink, '<$2|$4>', $part[0]);

                $output["blocks"][] = [
                    "type" => "section",
                    "text" => [
                        "type" => "mrkdwn",
                        "text" => $part[0]
                    ]
                ];

                $output["blocks"][] = [
                    "type" => "image",
                    "title" => [
                        "type" => "plain_text",
                        "text" => "test"
                    ],
                    "image_url" => $tag->getAttribute('src'),
                    "alt_text" => "test",
                ];
            }
        }

        if ($text !== "") {
            $output["blocks"][] = [
                "type" => "section",
                "text" => [
                    "type" => "mrkdwn",
                    "text" => preg_replace($patternLink, '<$2|$4>', $text)
                ]
            ];
        }

        return $output;
    }

    /**
     * @inheritDoc
     */
    protected function buildUrlButtonMessage($message, $urlButton): array
    {
        $blocks = [
            $this->buildBlockKitText($this->toMarkdown($message->message))
        ];
        $buttonTitleProp = $this->conf['url_buttons']['button_title_var'];
        $buttonURLProp = $this->conf['url_buttons']['button_url_var'];

        if (!is_array($urlButton)) {
            $urlButton = [$urlButton];
        }

        $buttons = [];
        foreach ($urlButton as $button) {
            $buttons[] = $this->buildBlockKitButtonElement(
                $button->$buttonTitleProp,
                '',
                '',
                true,
                $button->$buttonURLProp
            );
        }

        $blocks[] = $this->buildBlockKitButtons($buttons);

        $message_notification = $this->createNotificationMessage($message->message);

        return $this->buildBlockKitResponse($blocks, $message_notification);
    }

    /**
     * This build the escalation message into a Slack Block Kit response
     *
     * @return array
     */
    public function buildEscalationMessage(): array
    {
        $blocks = [
            $this->buildBlockKitText($this->langManager->translate('ask-to-escalate'))
        ];

        $escalateOptions = [
            [
                "label" => 'yes',
                "escalate" => true,
            ],
            [
                "label" => 'no',
                "escalate" => false
            ],
        ];

        $elements = [];
        foreach ($escalateOptions as $option) {
            $elements[] = $this->buildBlockKitButtonElement(
                $this->langManager->translate($option['label']),
                json_encode(
                    [
                        'escalateOption' => $option['escalate'],
                    ],
                    true
                ),
            );
        }

        $blocks[] = $this->buildBlockKitButtons($elements);

        $message_notification = $this->createNotificationMessage($this->langManager->translate('ask-to-escalate'));

        return $this->buildBlockKitResponse($blocks, $message_notification);
    }

    /**
     * This build a list of blocks containing related information
     *
     * @param object $related
     *
     * @return array Blocks response
     */
    public function buildRelatedMessage($related)
    {
        $blocks = [
            // related introduction
            $this->buildBlockKitText($this->langManager->translate('related-introduction')),
        ];

        foreach ($related->relatedContents as $content) {
            $blocks[] = $this->buildBlockKitText(
                '*' . $this->toMarkdown($content->title) . '*',
                'mrkdwn',
                $this->buildBlockKitButtonElement(
                    $this->langManager->translate('multiple-answer-button-title'),
                    (string) $content->id,
                    '',
                    true,
                    '',
                    'RELATED_' . $content->id
                )
            );
        }

        return $blocks;
    }

    /**
     * Return the request input
     *
     * @param bool $decode
     *
     * @return mixed
     */
    public function getRequest($decode = true)
    {
        $request = file_get_contents('php://input');

        // Sometimes Slack sends requests as application/x-www-form-urlencoded...
        if (is_null(json_decode($request))) {
            parse_str(file_get_contents('php://input'), $request);
            if (isset($request['payload'])) {
                $request = $request['payload'];
            }
        }
        return $decode ? json_decode($request) : $request;
    }

    /**
     * Converts an HTML-formatted text into Markdown format.
     *
     * @param string $text - Text to transform into Markdown
     * @return string
     */
    public static function toMarkdown(string $text): string
    {
        $content = str_replace(">\n", '>', $text);
        $content = str_replace("\n<", '<', $content);
        $content = str_replace("\t", '', $content);
        $content = strip_tags(
            $content,
            '<br><strong><em><del><li><code><pre><a><p><ul><i><s><h1><h2><h3><h4><b>'
        );
        $content = str_replace("\n", '', $content);
        $content = str_replace(array('<br />', '<br>'), "\n", $content);
        $content = str_replace(
            array(
                '<strong>',
                '</strong>',
                '<b>',
                '</b>',
                '<h1>',
                '</h1>',
                '<h2>',
                '</h2>',
                '<h3>',
                '</h3>',
                '<h4>',
                '</h4>'
            ),
            array('*', '*'),
            $content
        );

        $content = str_replace(array('<p>', '</p>'), array('', "\n"), $content);
        $content = str_replace(
            array('<em>', '</em>', '<i>', '</i>'),
            array('_', '_', '_', '_'),
            $content
        );
        $content = str_replace(
            array('<del>', '</del>', '<s>', '</s>'),
            array('~', '~', '~', '~'),
            $content
        );
        $content = str_replace(array('<li>', '</li>'), array('● ', "\n"), $content);
        $content = str_replace(array('<ul>', '</ul>'), array("\n", "\n"), $content);
        $content = str_replace(array('<code>', '</code>'), array('`', '`'), $content);
        $content = str_replace(array('<pre>', '</pre>'), array('```', '```'), $content);
        preg_match_all('/<a href=\"(.*?)\">(.*?)<\/a>/i', $content, $res);
        for ($i = 0; $i < count($res[0]); $i++) {
            $content = str_replace(
                $res[0][$i],
                '<' . $res[1][$i] . '|' . $res[2][$i] . '>',
                $content
            );
        }

        return html_entity_decode($content);
    }

    /**
     * Get the button title attribute from the configuration
     *
     * @return string - Title Attribute
     */
    protected function getButtonTitleAttribute(): string
    {
        return (isset($this->conf['button_title']) && $this->conf['button_title'] !== '')
            ? $this->conf['button_title']
            : '';
    }

    /********************** BLOCK KIT HELPERS **********************/

    /**
     * Build the BlockKit response to send to the Slack API
     *
     * @param array $blocks Array of block messages
     * @param string $notification Additional notification message
     * @param array $attachments An block element to show as an attachment form
     *
     * @return array - Block API Response array
     */
    public static function buildBlockKitResponse(
        array $blocks = [],
        $notification = "",
        array $attachments = []
    ): array {
        $response = [];

        if (!empty($notification)) {
            $response['text'] = $notification;
        }

        if (!empty($blocks)) {
            $response['blocks'] = $blocks;
        }

        if (!empty($attachments)) {
            $response['attachments'] = $attachments;
        }

        return $response;
    }

    /**
     * Build a Block Kit button element
     *
     * @param string $text - Button text
     * @param string $value - Button value return when clicking
     * @param string $style - Button style (primary or danger)
     * @param bool $emoji - If emoji's are allowed in the text
     * @param string $url - URL To open in user browser
     * @param string $action_id - Action identifier
     *
     * @return array
     */
    public static function buildBlockKitButtonElement(
        string $text,
        string $value = '',
        string $style = '',
        bool $emoji = true,
        string $url = '',
        string $action_id = ''
    ): array {
        $element = [
            "type" => "button",
            "text" => [
                "type" => "plain_text",
                "emoji" => $emoji,
                "text" => $text
            ],
        ];
        if (!empty($value)) {
            $element['value'] = $value;
        }
        if (!empty($url)) {
            $element['url'] = $url;
        }
        if (!empty($style) && ($style === 'primary' || $style === 'danger')) {
            $element['style'] = $style;
        }
        if (!empty($action_id)) {
            $element['action_id'] = $action_id;
        }
        return $element;
    }

    /**
     * Build a Block Kit Buttons list from elements given
     *
     * @param array $elements - Button elements
     * @return array
     */
    public static function buildBlockKitButtons(array $elements): array
    {
        return [
            "type" => "actions",
            "elements" => $elements
        ];
    }

    /**
     * Build a simple Block Kit text section
     *
     * @param string $text - Given text to show
     * @param string $type - Type of handler (simple text or markdown)
     * @param array|bool $accessory - Block element
     * @return array
     */
    public static function buildBlockKitText(
        string $text,
        string $type = "mrkdwn",
        $accessory = false
    ): array {
        $blockText = [
            "type" => "section",
            "text" => [
                "type" => $type,
                "text" => $text
            ]
        ];

        if ($accessory) {
            $blockText['accessory'] = $accessory;
        }

        return $blockText;
    }

    /**
     * Build a simple Block Kit Image
     *
     * @param string $url Image URL
     * @param string $text Text shown
     * @param string $altText Alternative text (optional)
     * @param bool $emoji Emoji allowed in text (optional, default: true)
     * @return array
     */
    public static function buildBlockKitImage(
        string $url,
        string $text = "",
        string $altText = "",
        bool $emoji = true
    ): array {
        return [
            "type" => "image",
            "title" => [
                "type" => "plain_text",
                "text" => $text,
                "emoji" => $emoji
            ],
            "alt_text" => $altText,
            "image_url" => $url
        ];
    }

    /**
     * Build a simple Block Kit File
     *
     * @param string $external_id
     * @param string $block_id
     * @return array
     */
    public static function buildBlockKitFile(
        string $external_id,
        string $block_id = ""
    ): array {
        $block = [
            "type" => "file",
            "external_id" => $external_id,
            "source" => "remote",
        ];

        if (!empty($block_id)) {
            $block['block_id'] = $block_id;
        }

        return $block;
    }

    /**
     * Build a static select
     *
     * @param string $text
     * @param string $placeholder
     * @param string $action_id
     * @param array $options
     * @param array $option_groups
     * @param array $initial_options
     * @param array $confirm
     * @param int $max_selected_items
     * @return array
     */
    public static function buildBlockKitStaticSelect(
        string $text,
        string $placeholder,
        string $action_id,
        array $options,
        array $option_groups = [],
        array $initial_options = [],
        array $confirm = []
    ): array {
        $accessory = [
            "type" => "static_select",
            "placeholder" => [
                "type" => "plain_text",
                "text" => $placeholder,
            ],
            "action_id" => $action_id,
            "options" => $options,
        ];

        if (!empty($option_groups)) {
            $accessory['option_groups'] = $option_groups;
        }

        if (!empty($initial_options)) {
            $accessory['initial_options'] = $initial_options;
        }

        if (!empty($confirm)) {
            $accessory['confirm'] = $confirm;
        }

        return [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => self::toMarkdown($text),
            ],
            "accessory" => $accessory,
        ];
    }

    /**
     * Build a select option element
     *
     * @param string $text
     * @param string $value
     * @param string $description
     * @param string $url
     * @return array
     */
    public static function buildBlockKitSelectOption(
        string $text,
        string $value,
        string $description = '',
        string $url = ''
    ): array {
        $option = [
            "text" => [
                "type" => "plain_text",
                "text" => $text,
            ],
            "value" => $value,
        ];

        if (!empty($description)) {
            $option['description'] = $description;
        }

        if (!empty($url)) {
            $option['url'] = $url;
        }

        return $option;
    }

    /**
     * This create an array of `string` & `array` using {@link DOMDocument::loadHTML()} from the HTML String
     * passed in the parameter.
     *
     * @param string $html HTML String
     * @param array $defaultsNodesBlocks Default nodes
     *
     * @return string|array[]
     */
    public function createNodesBlocks($html, $defaultsNodesBlocks = [])
    {
        $nodesBlocks = $defaultsNodesBlocks;

        try {
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

            /** @var DOMNode $body */
            $body = $dom->getElementsByTagName('body')[0];

            if (isset($body->childNodes)) {
                foreach ($body->childNodes as $childNode) {

                    /** @type DOMNode $childNode */
                    if ($this->domElementHasImage($childNode)) {
                        $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMImages($childNode));
                    }
                    if (strpos($this->getElementHTML($childNode), '<iframe') !== false) {
                        $nodesBlocks = array_merge($nodesBlocks, $this->handleDOMIframe($childNode));
                    } else {
                        if (strpos($this->getElementHTML($childNode), '<hr') !== false) {
                            $nodesBlocks[] = ['type' => 'divider'];
                        } else {
                            $nodesBlocks[] = $this->getElementHTML($childNode);
                        }
                    }
                }
            }

            return $nodesBlocks;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * This check {@link DOMNode::$childNodes} and search for images, then return an array
     * containing the `alt` and `src` attributes or the {@link DOMNode} HTML if not an image.
     *
     * @param DOMNode $element Given {@link DOMNode} element
     * @return array
     */
    public function handleDOMImages(DOMNode $element): array
    {
        $elements = [];

        foreach ($element->childNodes as $childNode) {
            /** @type DOMNode $childNode */

            if ($childNode->nodeName === 'img') {
                $elements[] = [
                    'alt' => $childNode->getAttribute('alt'),
                    'src' => $childNode->getAttribute('src')
                ];
            } else {
                $elements[] = $this->getElementHTML($childNode);
            }
        }

        return $elements;
    }

    /**
     * This check {@link DOMNode::$childNodes} and search for iframe, then return an array
     * containing the link of the src of the iframe
     */
    public function handleDOMIframe(DOMNode $element): array
    {
        $elements = [];
        foreach ($element->childNodes as $childNode) {
            /** @type DOMNode $childNode */
            if ($childNode->nodeName === 'iframe') {
                $source = $childNode->getAttribute('src');
                if ($source) {
                    $elements[] = $source;
                } else {
                    $elements[] = $this->getElementHTML($childNode);
                }
            } else {
                $elements[] = $this->getElementHTML($childNode);
            }
        }
        return $elements;
    }


    /**
     * Check if the current {@link DOMNode} children has an image node
     *
     * @param DOMNode $element
     * @return bool
     */
    public function domElementHasImage($element): bool
    {
        if (!$element instanceof DOMText) {
            $images = $element->getElementsByTagName('img');
            return $images->length > 0 ? true : false;
        }
        return false;
    }

    /**
     * Return an HTML {@link string} form a {@link DOMNode} element
     *
     * @param DOMNode $element
     * @return string
     */
    public function getElementHTML($element)
    {
        $tmp = new \DOMDocument();
        $tmp->appendChild($tmp->importNode($element, true));
        return $tmp->saveHTML();
    }

    /**
     * Check if the message contains related contents
     *
     * @param $message
     * @return bool
     */
    protected function hasRelated($message)
    {
        return isset($message->parameters->contents->related);
    }


    /**
     * Create the notification message
     * @param string $message
     * @return string $message_notification
     */
    protected function createNotificationMessage(string $message)
    {
        $message_notification = strlen($message) == 0 || empty($message) ? $this->langManager->translate('new-message') : $this->toMarkdown($message);
        return $this->validateNotificationLength($message_notification);
    }

    /**
     * Create the notification from external class
     * @param string $message
     * @return string $message_notification
     */
    public static function createNotificationMessageFromExternal(string $message)
    {
        $message_notification = self::toMarkdown($message);
        return self::validateNotificationLength($message_notification);
    }

    /**
     * Validate the length of the notification
     * @param string $message_notification
     * @return string $message_notification
     */
    protected static function validateNotificationLength(string $message_notification)
    {
        if (strpos($message_notification, "\n") > 0) {
            $message_notification = substr($message_notification, 0, strpos($message_notification, "\n"));
        }
        $message_notification = strlen($message_notification) > 50 ? substr($message_notification, 0, 47) . "..." : $message_notification;
        return $message_notification;
    }

    /**
     * Check if Hyperchat is running and if the attached file is correct
     * @param object $request
     * @return array $output
     */
    protected function mediaFileToHyperchat(object $fileData)
    {
        $output = [];
        if ($this->session->get('chatOnGoing', false)) {
            $mediaFile = $this->getMediaFile($fileData);
            if ($mediaFile !== "") {
                $output = ['media' => $mediaFile];
            }
        }
        return $output;
    }

    /**
     * Get the media file from Slack response, 
     * save file into temporal directory to sent to Hyperchat
     * @param object $fileData
     */
    protected function getMediaFile(object $fileData)
    {
        if (
            isset($fileData->url_private) && isset($fileData->filetype) && in_array($fileData->filetype, $this->attachableFormats)
        ) {
            $fileRaw = $this->externalClient->getFileFromSlack($fileData->url_private);
            if ($fileRaw !== "") {
                $uniqueName = str_replace(" ", "", microtime(false));
                $uniqueName = str_replace("0.", "", $uniqueName);
                $fileName = sys_get_temp_dir() . "/file" . $uniqueName . "." . $fileData->filetype;
                $tmpFile = fopen($fileName, "w") or die;
                fwrite($tmpFile, $fileRaw);
                $fileRaw = fopen($fileName, 'r');
                @unlink($fileName);

                return $fileRaw;
            }
        }
        return "";
    }
}
