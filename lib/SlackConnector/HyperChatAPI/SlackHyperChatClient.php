<?php

namespace Inbenta\SlackConnector\HyperChatAPI;

use Inbenta\ChatbotConnector\HyperChatAPI\HyperChatClient;
use Inbenta\SlackConnector\ExternalAPI\SlackAPIClient;

class SlackHyperChatClient extends HyperChatClient
{

    public function __construct($config, $lang, $session, $appConf, $externalClient)
    {
        if ($config['enabled']) {
            parent::__construct($config, $lang, $session, $appConf, $externalClient);
        }
    }

    //Instances an external client
    protected function instanceExternalClient($externalId, $appConf)
    {
        $externalId = SlackAPIClient::getIdFromExternalId($externalId);
        if (is_null($externalId)) {
            return null;
        }
        $externalClient = new SlackAPIClient(null, $appConf->get('slack.access_token'));
        $externalClient->setSenderFromId($externalId);
        return $externalClient;
    }

    public static function buildExternalIdFromRequest($config)
    {
        $request = json_decode(file_get_contents('php://input'), true);

        $externalId = null;
        if (isset($request['trigger'])) {
            //Obtain user external id from the chat event
            $externalId = self::getExternalIdFromEvent($config, $request);
        }
        return $externalId;
    }
}
