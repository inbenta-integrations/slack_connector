<?php

namespace Inbenta\SlackConnector;

use Exception;
use Inbenta\ChatbotConnector\{
    ChatbotConnector,
    Utils\SessionManager,
    ChatbotAPI\ChatbotAPIClient
};
use Inbenta\SlackConnector\{
    ExternalAPI\SlackAPIClient,
    ExternalDigester\SlackDigester,
    HyperChatAPI\SlackHyperChatClient,
    MessengerAPI\MessengerAPI
};

class SlackConnector extends ChatbotConnector
{
    /** @var SlackDigester */
    protected $digester;
    /** @var SessionManager */
    public $session;

    /**
     * @inheritDoc
     */
    public function __construct($appPath)
    {
        // Initialize and configure specific components for Slack
        try {
            parent::__construct($appPath);

            // Initialize base components
            $request = file_get_contents('php://input');

            $conversationConf = array(
                'configuration' => $this->conf->get('conversation.default'),
                'userType' => $this->conf->get('conversation.user_type'),
                'environment' => $this->environment,
                'source' => $this->conf->get('conversation.source')
            );

            $this->session = new SessionManager($this->getExternalIdFromRequest($request));

            // Instance Slack digester
            $externalDigester = new SlackDigester(
                $this->lang,
                $this->conf->get('conversation.digester'),
                $this->session
            );

            $this->botClient = new ChatbotAPIClient(
                $this->conf->get('api.key'),
                $this->conf->get('api.secret'),
                $this->session,
                $conversationConf
            );

            // Initialize Hyperchat events handler
            if ($this->conf->get('chat.chat.enabled') && ($this->session->get('chatOnGoing', false) || isset($_SERVER['HTTP_X_HOOK_SECRET']))) {
                $chatEventsHandler = new SlackHyperChatClient(
                    $this->conf->get('chat.chat'),
                    $this->lang,
                    $this->session,
                    $this->conf,
                    $this->externalClient
                );
                $chatEventsHandler->handleChatEvent();
            } else if (isset($_SERVER['HTTP_X_HOOK_SIGNATURE']) && $_SERVER['HTTP_X_HOOK_SIGNATURE'] == $this->conf->get('chat.messenger.webhook_secret')) {
                $messengerAPI = new MessengerAPI($this->conf, $this->lang, $externalDigester, $this->session);
                $request = json_decode(file_get_contents('php://input'));
                $messengerAPI->handleMessageFromClosedTicket($request);
            }

            // Handle Slack verification challenge, if needed
            SlackAPIClient::hookChallenge($request);

            // Instance application components
            // Instance Slack client
            $externalClient = new SlackAPIClient($externalDigester->getRequest(false), $this->conf->get('slack.access_token'),);
            // Instance HyperchatClient for Slack
            $chatClient = new SlackHyperChatClient(
                $this->conf->get('chat.chat'),
                $this->lang,
                $this->session,
                $this->conf,
                $externalClient
            );

            $this->initComponents($externalClient, $chatClient, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     * @inheritDoc
     */
    public function handleRequest()
    {
        // Store request
        $request = $this->digester->getRequest();

        if ($this->shouldHandleRequest($request)) {
            // handle request normally
            parent::handleRequest();
        } else {
            $this->returnOkResponse();
        }
    }

    /**
     * Check if the request can be handled normally
     *
     * @param object $request Request object
     *
     * @return bool
     */
    protected function shouldHandleRequest(object $request): bool
    {
        // sometimes the bot sends a request using it's identification a this count a
        // user request, so we shouldn't handle this kind of request
        if (isset($request->event->bot_profile)) {
            return false;
        }

        // we check message id to avoid duplicate handling when it takes some time to respond
        $messageId = isset($request->event->client_msg_id) ? $request->event->client_msg_id : false;
        if ($messageId) {
            // is a slack message
            $messageIds = $this->session->get('messageIds', []);
            if (array_search($messageId, $messageIds) === false) {
                // new message incoming
                array_push($messageIds, $messageId);
                if (count($messageIds) >= 10) {
                    array_shift($messageIds);
                }
                $this->session->set('messageIds', $messageIds);
                // handle request normally
                return true;
            }
            if (isset($request->trigger_id)) {
                // other messages
                return true;
            } else {
                // same message sent
                return false;
            }
        }

        return true;
    }

    /**
     * Return external id from request (Hyperchat of Slack)
     *
     * @param string|array $request
     *
     * @return string | null
     *
     * @throws Exception On the last try of obtaining the External ID
     */
    protected function getExternalIdFromRequest($request): ?string
    {
        if (is_string($request)) {
            $request = json_decode($request, true);
        }

        // Try to get user_id from a Slack message request
        $externalId = SlackAPIClient::buildExternalIdFromRequest();
        if (is_null($externalId)) {
            // Try to get user_id from a Hyperchat event request
            $externalId = SlackHyperChatClient::buildExternalIdFromRequest(
                $this->conf->get('chat.chat')
            );
        }

        if (empty($externalId)) {
            $apiKey = $this->conf->get('api.key');
            if (isset($request['challenge'])) {
                // Create a temporary session_id from a Slack webhook linking request
                $externalId = "slack-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $apiKey);
            } elseif (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
                // Create a temporary session_id from a HyperChat webhook linking request
                $externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $apiKey);
            } elseif (isset($_SERVER['HTTP_X_HOOK_SIGNATURE'])) {
                $externalId = "response-from-agent";
            } else {
                throw new Exception("Invalid request");
            }
        }

        return $externalId;
    }
}
