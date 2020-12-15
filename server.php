<?php

require "vendor/autoload.php";

use Inbenta\SlackConnector\SlackConnector;

//Instance new SlackConnector
$appPath = __DIR__ . '/';
$app = new SlackConnector($appPath);

//Handle the incoming request
$app->handleRequest();
