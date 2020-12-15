<?php

namespace Inbenta\SlackConnector\ExternalAPI;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Inbenta\SlackConnector\ExternalDigester\SlackDigester;
use Psr\Http\Message\ResponseInterface;

class SlackAPIClient
{

    /**
     * The Slack API URL.
     *
     * @var string
     */
    protected $baseUri = 'https://slack.com/api/';

    /**
     * The Slack app's Access Token.
     *
     * @var string|null
     */
    protected $appAccessToken;

    /**
     * Slack User who sends the message.
     *
     * @var array|null
     */
    protected $sender;

    /**
     * Slack channel to send back response to.
     *
     * Correspond to the User ID in a slack request
     *
     * @var string|null
     */
    protected $channel;

    /**
     * Incoming request
     *
     * @var mixed
     */
    protected $request;

    /**
     * Create a new instance.
     *
     * @param string|null $request
     */
    public function __construct($request = null, $accessToken)
    {
        $this->request = is_null($request) ? $this->getRequest(false) : $request;
        $this->appAccessToken = $accessToken;
        $this->setSenderFromRequest($this->request);
        $this->setChannelFromRequest($this->request);
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
     * Send an outgoing message.
     *
     * @param array $body
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function send(array $body): ResponseInterface
    {
        return $this->request('POST', 'chat.postMessage', $body);
    }

    /**
     * Update a message with a new one
     *
     * @param array $body Message body
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function update(array $body): ResponseInterface
    {
        return $this->request('POST', 'chat.update', $body);
    }

    /**
     * Send a request to the Slack API.
     *
     * @param string $method HTTP Method
     * @param string $uri Request URI
     * @param array $body Body parameters
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    protected function request($method, $uri, array $body = []): ResponseInterface
    {
        $guzzle = new Guzzle(
            [
                'base_uri' => $this->baseUri,
            ]
        );

        // define options
        $options = [
            RequestOptions::HEADERS => [
                "Authorization" => "Bearer " . $this->appAccessToken,
            ]
        ];

        // set body & header if not empty
        if ($method === "POST" && !empty($body)) {
            $options[RequestOptions::JSON] = $body;
            $options[RequestOptions::HEADERS]["Content-Type"] = "application/json; charset=utf-8";
        }

        return $guzzle->request(
            $method,
            $uri,
            $options
        );
    }

    /**
     * Establishes the Slack channel from an incoming Slack request
     *
     * @param string $request
     */
    protected function setChannelFromRequest(string $request)
    {
        $event = $this->getFirstMessageFromRequest($request);

        if (empty($event)) {
            $request = json_decode($request, true);
            $channel = isset($request['channel'])
                ? $request['channel']['id']
                : self::setChannelFromSessionID();
        } else {
            $channel = isset($event['channel'])
                ? $event['channel']
                : self::setChannelFromSessionID();
        }

        $this->channel = $channel;
    }

    /**
     * Return the channel from the session ID
     *
     * @return string|null
     */
    public static function setChannelFromSessionID(): ?string
    {
        $sessionID = explode('-', session_id());
        if (array_shift($sessionID) === 'slack' && isset($sessionID[1])) {
            return $sessionID[1];
        }
        return null;
    }

    /**
     * Establishes the Slack sender (user) from an incoming Slack request
     *
     * @param string $request
     */
    protected function setSenderFromRequest(string $request)
    {
        $message = $this->getFirstMessageFromRequest($request);
        if (count($message) > 0) {
            $message = (object) $message;
        }

        // prevents bot from talking to himself (infinite loop).
        if (isset($message->subtype) && $message->subtype === 'bot_message') {
            die();
        }

        if (empty($message)) {
            // sometimes Slack sends requests as application/x-www-form-urlencoded...
            parse_str($request, $payload);
            if (isset($payload['payload'])) {
                $body = json_decode($payload['payload'], true);
                $senderId = isset($body['user']['id']) ? $body['user']['id'] : '';
                $this->sender = ['id' => $senderId];
            } else {
                $body = json_decode($request, true);
                if (isset($body["user"]) && isset($body["user"]["id"])) {
                    $this->sender = ['id' => $body["user"]["id"]];
                }
            }
            return;
        }

        $senderId = isset($message->user) ? $message->user : '';
        $this->sender = ['id' => $senderId];
    }

    /**
     * Retrieves the user id from the external ID generated by the getExternalId method
     *
     * @param string $externalId
     *
     * @return mixed|null
     */
    public static function getIdFromExternalId(string $externalId)
    {
        $slackInfo = explode('-', $externalId);
        if (array_shift($slackInfo) == 'slack') {
            return end($slackInfo);
        }
        return null;
    }

    /**
     * This returns the external id
     *
     * @return string|null
     */
    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'), true);
        $user = isset($request['event']['user']) ? $request['event']['user'] : false;
        $channel = isset($request['event']['channel']) ? $request['event']['channel'] : false;
        if ($user && $channel) {
            return "slack-$channel-$user";
        }
        // Sometimes Slack sends requests as application/x-www-form-urlencoded...
        parse_str(file_get_contents('php://input'), $request);
        if (isset($request['payload'])) {
            $body = json_decode($request['payload'], true);
            $user = isset($body['user']['id']) ? $body['user']['id'] : false;
            $channel = isset($body['channel']['id']) ? $body['channel']['id'] : false;
            if ($user && $channel) {
                return "slack-$channel-$user";
            }
        }
        return null;
    }

    /**
     * Establishes the Slack sender (user) directly with the provided ID
     *
     * @param string $senderID
     *
     * @throws GuzzleException
     */
    public function setSenderFromId(string $senderID)
    {
        $this->sender = $this->user($senderID);
        $this->sender['id'] = $senderID;
    }

    /**
     * Request the sender infos from the API
     *
     * @param string $senderID
     *
     * @return array
     *
     * @throws GuzzleException
     */
    public function user(string $senderID): array
    {
        $response = $this->getUserFromId($senderID);
        $body = json_decode($response->getBody()->getContents(), true);
        if (isset($body['ok']) && $body['ok'] && isset($body['profile'])) {
            return $body['profile'];
        }

        return [];
    }

    /**
     * Return a user profile from it's id
     *
     * @see https://api.slack.com/methods/users.profile.get
     *
     * @param string $id
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function getUserFromId(string $id): ResponseInterface
    {
        $uri = "users.profile.get?user=$id&token=" . $this->appAccessToken;
        return $this->get($uri);
    }

    /**
     * This sends a file to Slack and return it's information on success
     *
     * @param array $file File information from Inbenta Backstage
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function fileUpload(array $file): ResponseInterface
    {
        $uri = "files.upload?token=" . $this->appAccessToken;

        $guzzle = new Guzzle(
            [
                'base_uri' => $this->baseUri,
            ]
        );

        return $guzzle->request(
            "POST",
            $uri,
            [
                RequestOptions::HEADERS => [
                    "Authorization" => "Bearer " . $this->appAccessToken
                ],
                RequestOptions::MULTIPART => [
                    [
                        "name" => "filename",
                        "contents" => $file['name']
                    ],
                    [
                        "name" => "file",
                        "contents" => fopen($file['fullUrl'], 'r')
                    ],
                    [
                        "name" => "channels",
                        "contents" => $this->channel
                    ],
                    [
                        "name" => "title",
                        "contents" => $file['name']
                    ]
                ]
            ]
        );
    }

    /**
     * Send a GET request
     *
     * @param string $uri
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    protected function get(string $uri): ResponseInterface
    {
        $guzzle = new Guzzle(
            [
                'base_uri' => $this->baseUri,
            ]
        );

        return $guzzle->request('GET', $uri);
    }

    /**
     * Returns properties of the sender object when the $key parameter is provided (and exists).
     * If no key is provided will return the whole object
     *
     * @param null|string $key
     *
     * @return null|mixed
     */
    public function getSender($key = null)
    {
        $sender = $this->sender;

        if ($key) {
            if (isset($sender[$key])) {
                return $sender[$key];
            }
            return null;
        } else {
            return $sender;
        }
    }

    /**
     * Returns the full name of the user (first + last name)
     *
     * @return string | null
     *
     * @throws GuzzleException
     */
    public function getFullName(): ?string
    {
        if (!$this->getSender('real_name') && $this->getSender('id')) {
            $this->setSenderFromId($this->getSender('id'));
        }
        return $this->getSender('real_name');
    }

    /**
     * Return the current user email address if available
     *
     * @return string
     *
     * @throws GuzzleException
     */
    public function getEmail(): ?string
    {
        if (!$this->getSender('email') && $this->getSender('id')) {
            $this->setSenderFromId($this->getSender('id'));
        }
        return $this->getSender('email');
    }

    /**
     * Generates the external id used by HyperChat to identify one user as external.
     * This external id will be used by HyperChat adapter to instance this client class from the external id
     *
     * @return string
     */
    public function getExternalId(): string
    {
        return 'slack-' . $this->channel . '-' . $this->getSender('id');
    }

    /**
     * Handles the hook challenge sent by Slack to ensure that we're the owners of the Slack app.
     * Requires the request body sent by the Slack app
     *
     * @param string $requestBody
     */
    public static function hookChallenge(string $requestBody)
    {
        $jsonBody = json_decode($requestBody, true);
        if (isset($jsonBody['challenge'])) {
            echo $jsonBody['challenge'];
            die();
        }
    }

    /**
     * Returns the first message in a incoming Slack request
     *
     * @param string $request
     *
     * @return array|mixed
     */
    protected function getFirstMessageFromRequest(string $request)
    {
        $request = json_decode($request, true);
        return isset($request['event']) ? $request['event'] : [];
    }

    /**
     * Sends a flag to Slack to display a notification alert as the bot is 'writing'
     * This method can be used to disable the notification if a 'false' parameter is received
     *
     * @param bool $show
     *
     * @return null
     */
    public function showBotTyping($show = true)
    {
        return null;
    }

    /**
     * Sends a message to Slack. Needs a message formatted with the Slack notation
     *
     * @param array $message
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function sendMessage(array $message): ResponseInterface
    {
        $this->showBotTyping();

        $body = [
            "channel" => $this->channel,
            "token" => $this->appAccessToken
        ];

        // handle blocks
        if (isset($message['blocks'])) {
            $body['blocks'] = $message['blocks'];
        }

        // handle attachments
        if (isset($message['attachments'])) {
            $body['attachments'] = [
                [
                    "blocks" => $message['attachments'],
                ]
            ];
        }

        // handle text notification (shown when no focus on slack on receiving new message)
        $textNotification = (isset($message['text']) && trim($message['text']) !== "") ? $message['text'] : false;
        if ($textNotification) {
            $body['text'] = $textNotification;
            if (!isset($message['blocks'])) { //This prevents send message from an empty message
                unset($body['text']);
            }
        }

        // get the current action in the request
        $request = $this->getRequest();
        $action =  isset($request->actions[0]) ? $request->actions[0] : false;

        // check if it's a rating click action
        if ($action && strpos($action->action_id, 'RATINGS') !== false) {
            $body['ts'] = $request->message->ts;
            $body['as_user'] = true;
            return $this->update($body);
        }

        return $this->send($body);
    }

    /**
     * Generates a text message from a string and sends it to Slack
     *
     * @param string $message Simple text message
     *
     * @throws GuzzleException
     */
    public function sendTextMessage($message)
    {
        $notification = is_string($message) ? SlackDigester::createNotificationMessageFromExternal($message) : "";
        $this->sendMessage(
            SlackDigester::buildBlockKitResponse(
                [
                    SlackDigester::buildBlockKitText($message)
                ],
                $notification
            )
        );
    }

    /**
     * Generate a message from a file and sends it to Slack
     *
     * @param array $attachment Attachment information
     *
     * @throws GuzzleException
     */
    public function sendAttachmentMessageFromHyperChat(array $attachment)
    {
        $this->fileUpload($attachment);
    }

    /**
     * Converts an HTML-formatted text into Slack markdown.
     *
     * @param string $text
     *
     * @return string
     */
    public function toMarkdown(string $text): string
    {
        $content = str_replace(">\n", '>', $text);
        $content = str_replace("\n<", '<', $content);
        $content = str_replace("\t", '', $content);
        $content = strip_tags($text, '<br><strong><em><del><li><code><pre><a></a><p></p><ul></ul>');
        $content = str_replace("\n", '', $content);
        $content = str_replace(array('<br />', '<br>'), "\n", $content);
        $content = str_replace(array('<strong>', '</strong>'), array('*', '*'), $content);
        $content = str_replace(array('<p>', '</p>'), array('', "\n"), $content);
        $content = str_replace(array('<em>', '</em>'), array('_', '_'), $content);
        $content = str_replace(array('<del>', '</del>'), array('~', '~'), $content);
        $content = str_replace(array('<li>', '</li>'), array(' -', "\n"), $content);
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
        return $content;
    }
}
