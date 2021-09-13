<?php

require "vendor/autoload.php";

use Inbenta\D360Connector\D360Connector;

$request = json_decode(file_get_contents('php://input'));
if (isset($request->statuses)) {
    //Prevent empty message, when the validation for status is sent from 360 Dialog
    header('Connection: close');
    die;
}

//Instance new D360Connector
$appPath = __DIR__ . '/';
$app = new D360Connector($appPath);

//Handle the incoming request
$app->handleRequest();
