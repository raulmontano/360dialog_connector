<?php

namespace Inbenta\D360Connector;

use GuzzleHttp\Client as Guzzle;

class SubscribeWebhook
{

    /**
     * Subscribe the Whatsapp number to a webhook
     */
    public static function subscribe($config, $confirmation)
    {
        if ($confirmation == 1) {
            $payload = [
                "url" => $config["url_webhook"] //The url where the app is installed
            ];
            $headers = [
                "Content-Type" => "application/json",
                "D360-Api-Key" => $config['api_key']
            ];

            $client = new Guzzle();
            $clientParams = [
                'headers' => $headers,
                'body' => json_encode($payload)
            ];
            $serverOutput = $client->post($config['url_subscribe'], $clientParams);

            if (method_exists($serverOutput, 'getBody')) {
                $responseBody = $serverOutput->getBody();
                if (method_exists($responseBody, 'getContents')) {
                    $result = json_decode($responseBody->getContents());

                    echo 'Webhook "'.$result->url.'" is now associated to your Whatsapp Account';
                    die;
                }
            }
        }
        echo "There is no webhook to subscribe";
        die;
    }
}
