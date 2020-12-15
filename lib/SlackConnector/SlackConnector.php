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
    MessengerAPI\MessengerAPI,
    ChatbotAPIClientVariables //Temporal, until the SetVariable function is available
};

class SlackConnector extends ChatbotConnector
{

    //  M U S T   B E   ON   P A R E N T
    const ESCALATION_DIRECT          = '__escalation_type_callback__';
    const ESCALATION_OFFER           = '__escalation_type_offer__';

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
            $this->botClientVariables  = new ChatbotAPIClientVariables($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf, $this->botClient);

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

    /**
     * Handle an incoming request for the ChatBot
     *
     * @param array $externalRequest
     *
     * @throws Exception On failed to send the messages to the Bot
     */
    public function handleBotActions($externalRequest)
    {
        $needEscalation = false;
        $needContentRating = false;
        foreach ($externalRequest as $message) {
            // Check if is needed to execute any preset 'command'
            $this->handleCommands($message);
            // Store the last user text message to session
            $this->saveLastTextMessage($message);
            // Check if is needed to ask for a rating comment
            $message = $this->checkContentRatingsComment($message);
            // Send the messages received from the external service to the ChatbotAPI
            $botResponse = $this->sendMessageToBot($message);
            // Check if escalation to agent is needed
            $needEscalation = $this->checkEscalation($botResponse) ? true : $needEscalation;
            if ($needEscalation) {
                $this->deleteLastMessage($botResponse);
            }

            // Check if is needed to display content ratings
            $hasRating = $this->checkContentRatings($botResponse);
            $needContentRating = $hasRating ? $hasRating : $needContentRating;
            // Send the messages received from ChatbotApi back to the external service
            $this->sendMessagesToExternal($botResponse);
        }
        if ($needEscalation) {
            $this->handleEscalation();
        }
        // Display content rating if needed and not in chat nor asking to escalate
        if ($needContentRating && !$this->chatOnGoing() && !$this->session->get(
            'askingForEscalation',
            false
        )) {
            $this->displayContentRatings($needContentRating);
        }
    }

    /**
     * If there is escalation offer, delete the last message (that contains the polar question)
     */
    private function deleteLastMessage(&$botResponse)
    {
        if (isset($botResponse->answers) && $this->session->get('escalationType') == static::ESCALATION_OFFER) {
            if (count($botResponse->answers) > 0) {
                $elements = count($botResponse->answers) - 1;
                unset($botResponse->answers[$elements]);
            }
        }
    }

    //OVERWRITTEN OR NEW FOR ESCALATION
    /**
     * 	Checks if a bot response requires escalation to chat
     */
    protected function checkEscalation($botResponse)
    {
        if (!$this->chatEnabled()) {
            return false;
        }

        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }
        // Check if BotApi returned 'escalate' flag, an escalation callback on message or triesBeforeEscalation has been reached
        foreach ($messages as $msg) {
            $this->updateNoResultsCount($msg);

            $noResultsToEscalateReached = $this->shouldEscalateFromNoResults();
            $negativeRatingsToEscalateReached = $this->shouldEscalateFromNegativeRating();
            $apiEscalateFlag = isset($msg->flags) && in_array('escalate', $msg->flags);
            $apiEscalateDirect = isset($msg->actions) ? $msg->actions[0]->parameters->callback == "escalationStart" : false;
            $apiEscalateOffer = isset($msg->attributes) ? (isset($msg->attributes->DIRECT_CALL) ? $msg->attributes->DIRECT_CALL == "escalationOffer" : false) : false;

            if ($apiEscalateFlag || $noResultsToEscalateReached || $negativeRatingsToEscalateReached || $apiEscalateDirect || $apiEscalateOffer) {

                // Store into session the escalation type
                if ($apiEscalateFlag) {
                    $escalationType = static::ESCALATION_API_FLAG;
                } elseif ($noResultsToEscalateReached) {
                    $escalationType = static::ESCALATION_NO_RESULTS;
                } elseif ($negativeRatingsToEscalateReached) {
                    $escalationType = static::ESCALATION_NEGATIVE_RATING;
                } elseif ($apiEscalateOffer) {
                    $escalationType = static::ESCALATION_OFFER;
                } elseif ($apiEscalateDirect) {
                    $escalationType = static::ESCALATION_DIRECT;
                }
                $this->session->set('escalationType', $escalationType);
                return true;
            }
        }
        return false;
    }


    /**
     * Ask the user if wants to talk with a human and handle the answer
     * @param array $userAnswer = null
     * @return void
     */
    protected function handleEscalation($userAnswer = null)
    {
        // Ask the user if wants to escalate
        if (!$this->session->get('askingForEscalation', false)) {
            if ($this->session->get('escalationType') == static::ESCALATION_DIRECT) {
                $this->escalateToAgent();
            } else {
                // Ask the user if wants to escalate
                $this->session->set('askingForEscalation', true);
                $escalationMessage = $this->digester->buildEscalationMessage();
                $this->externalClient->sendMessage($escalationMessage);
            }
            die;
        } else {
            // Handle user response to an escalation question
            $this->session->set('askingForEscalation', false);
            // Reset escalation counters
            $this->session->set('noResultsCount', 0);
            $this->session->set('negativeRatingCount', 0);

            if (is_array($userAnswer) && isset($userAnswer[0]['escalateOption'])) {
                if ($userAnswer[0]['escalateOption'] === true || $userAnswer[0]['escalateOption'] === 1) {
                    $this->escalateToAgent();
                } else {
                    $message = ["message" => "no"];
                    $botResponse = $this->sendMessageToBot($message);
                    $this->sendMessagesToExternal($botResponse);
                }
            }
            $this->session->delete('escalationType');
            die;
        }
    }

    /**
     * 	Tries to start a chat with an agent
     */
    protected function escalateToAgent()
    {
        if ($this->checkAgents()) {
            // Start chat
            $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));
            // Build user data for HyperChat API
            $chatData = [
                'roomId' => $this->conf->get('chat.chat.roomId'),
                'user' => [
                    'name'             => trim($this->externalClient->getFullName()),
                    'contact'         => trim($this->externalClient->getEmail()),
                    'externalId'     => $this->externalClient->getExternalId(),
                    'extraInfo'     => []
                ]
            ];
            $response =  $this->chatClient->openChat($chatData);
            if (!isset($response->error) && isset($response->chat)) {
                $this->session->set('chatOnGoing', $response->chat->id);
                $this->trackContactEvent("CHAT_ATTENDED", $response->chat->id);

                //Save slack user info
                $messengerAPI = new MessengerAPI($this->conf, null, null, $this->session);
                $messengerAPI->saveSlackUserInfo($chatData['user']);
            } else {
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('error_creating_chat')));
            }
        } else {
            // Send no-agents-available message if the escalation trigger is an API flag (user asked for having a chat explicitly) or a callback
            if (
                $this->session->get('escalationType') == static::ESCALATION_API_FLAG || $this->session->get('escalationType') == static::ESCALATION_OFFER
                || $this->session->get('escalationType') == static::ESCALATION_DIRECT
            ) {
                $this->setVariableValue("agents_available", "false");
                $message = ["directCall" => "escalationStart"];
                $botResponse = $this->sendMessageToBot($message);
                $this->sendMessagesToExternal($botResponse);
            } else {
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
                $this->trackContactEvent("CHAT_UNATTENDED");
            }
        }
    }


    /**
     * 	Check if a bot response should display content-ratings
     */
    protected function checkContentRatings($botResponse)
    {
        $ratingConf = $this->conf->get('conversation.content_ratings');
        if (!$ratingConf['enabled']) {
            return false;
        }

        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }

        // Check messages are answer and have a rate-code
        $rateCode = false;
        foreach ($messages as $msg) {
            $isAnswer            = isset($msg->type) && $msg->type == 'answer';
            $hasEscalationCallBack = isset($msg->actions) ? $msg->actions[0]->parameters->callback == "escalationStart" : false;
            $hasEscalationCallBack2 = isset($msg->attributes) ? (isset($msg->attributes->DIRECT_CALL) ? $msg->attributes->DIRECT_CALL == "escalationOffer" : false) : false;
            $hasEscalationFlag   = isset($msg->flags) && in_array('escalate', $msg->flags);
            $hasNoRatingsFlag    = isset($msg->flags) && in_array('no-rating', $msg->flags);
            $hasRatingCode       = isset($msg->parameters) &&
                isset($msg->parameters->contents) &&
                isset($msg->parameters->contents->trackingCode) &&
                isset($msg->parameters->contents->trackingCode->rateCode);

            if ($isAnswer && $hasRatingCode && !$hasEscalationFlag && !$hasNoRatingsFlag && !$hasEscalationCallBack && !$hasEscalationCallBack2) {
                $rateCode = $msg->parameters->contents->trackingCode->rateCode;
            }
        }
        return $rateCode;
    }


    /**
     * Function to track CONTACT events
     * @param string $type Contact type: "CHAT_ATTENDED", "CHAT_UNATTENDED"
     * @param string $chatId
     */
    public function trackContactEvent($type, $chatId = null)
    {
        $data = [
            "type" => $type,
            "data" => [
                "value" => "true"
            ]
        ];
        if (!is_null($chatId)) {
            $chatConfig = $this->conf->get('chat.chat');
            $region = isset($chatConfig['regionServer']) ? $chatConfig['regionServer'] : 'us';
            $data["data"]["value"] = [
                "chatId" => $chatId,
                "appId" => $chatConfig['appId'],
                "region" => $region
            ];
        }

        $this->botClient->trackEvent($data);
    }

    /**
     * Set a value of a variable
     * @param string $varName
     * @param string $varValue
     */
    public function setVariableValue($varName, $varValue)
    {
        $variable = [
            "name" => $varName,
            "value" => $varValue
        ];
        $botVariableResponse = $this->botClientVariables->setVariable($variable);

        if (isset($botVariableResponse->success)) {
            return $botVariableResponse->success;
        }
        return false;
    }
}
