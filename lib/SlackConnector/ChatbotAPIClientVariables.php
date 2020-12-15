<?php

namespace Inbenta\SlackConnector;

use Inbenta\ChatbotConnector\ChatbotAPI\APIClient;
use \Exception;

class ChatbotAPIClientVariables extends APIClient
{
    protected $sessionToken = null;
    protected $sessionTokenExpiration = null;
    protected $botClient = null;

    function __construct($key, $secret, $session, $conversationConfiguration, $botClient)
    {
        parent::__construct($key, $secret);

        // Check if Chatbot API endpoint is known
        if (!isset($this->methods) || !isset($this->methods->chatbot)) {
            throw new Exception("Missing Inbenta API endpoints");
        }
        $this->url = $this->methods->chatbot;
        $this->session = $session;
        $this->appDataCacheFile = $this->cachePath . "cached-appdata-" . preg_replace("/[^A-Za-z0-9 ]/", '', $this->key);
        $this->conversationConf = $conversationConfiguration;
        $this->botClient = $botClient;
    }

    /**
     * Set a value of a variable
     */
    public function setVariable($variable)
    {
        // Update access token if needed
        $this->updateAccessToken();

        //Update sessionToken if needed
        $this->sessionToken = $this->session->get('sessionToken.token');
        $this->sessionTokenExpiration = $this->session->get('sessionToken.expiration');
        if (is_null($this->sessionToken) || is_null($this->sessionTokenExpiration) || $this->sessionTokenExpiration < time()) {
            $source = isset($this->conversationConf['source']) ? $this->conversationConf['source'] : null;
            $this->botClient->startConversation($this->conversationConf['configuration'], $this->conversationConf['userType'], $this->conversationConf['environment'], $source);
        }

        // Prepare the message
        $string = json_encode($variable);
        $params = array("payload" => $string);

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken,
            "Content-Type: application/json,charset=UTF-8",
            "Content-Length: " . strlen($string)
        );

        $response = $this->call("/v1/conversation/variables", "POST", $headers, $params);

        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }
}
