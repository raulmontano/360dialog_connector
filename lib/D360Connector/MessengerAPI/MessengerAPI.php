<?php

namespace Inbenta\D360Connector\MessengerAPI;

use Inbenta\D360Connector\ExternalAPI\D360APIClient;
use GuzzleHttp\Client as Guzzle;

date_default_timezone_set('UTC');

class MessengerAPI
{
    private $authUrl;
    private $messengerUrl;
    private $apiKey;
    private $accessToken;
    private $config;
    private $lang;
    private $session;
    private $currentTime;

    function __construct(object $config, $lang = null, $session = null)
    {
        $this->config = $config->get();
        $this->lang = $lang;
        $this->session = $session;
        $this->authUrl = $this->config['chat']['messenger']['auht_url'];
        $this->apiKey = $this->config['chat']['messenger']['key'];
        $this->currentTime = time();
        $this->makeAuth($this->config['chat']['messenger']['secret']);
    }


    /**
     * Execute the remote request
     * @param string $url
     * @param string $method
     * @param array $params
     * @param array $headers
     * @param string $dataResponse
     * @return array|object|null $response
     */
    private function remoteRequest(string $url, string $method, array $params, array $headers, string $dataResponse)
    {
        $response = null;

        $client = new Guzzle();
        $clientParams = ['headers' => $headers];
        if ($method !== 'get') {
            $clientParams['body'] = json_encode($params);
        }
        $serverOutput = $client->$method($url, $clientParams);

        if (method_exists($serverOutput, 'getBody')) {
            $responseBody = $serverOutput->getBody();
            if (method_exists($responseBody, 'getContents')) {
                $result = json_decode($responseBody->getContents());

                if ($dataResponse == "") {
                    $response = $result;
                } else if (isset($result->$dataResponse)) {
                    $response = $result->$dataResponse;
                }
            }
        }
        return $response;
    }


    /**
     * Make the authorization on instance
     * @param string $secret
     * @return void
     */
    private function makeAuth(string $secret)
    {
        if ($this->apiKey !== "" && $secret !== "" && !$this->validateSession()) {
            $params = ["secret" => $secret];
            $headers = ['x-inbenta-key' => $this->apiKey];
            $response = $this->remoteRequest($this->authUrl, "post", $params, $headers, "");

            $this->accessToken = isset($response->accessToken) ? $response->accessToken : null;
            $this->messengerUrl = isset($response->apis) && isset($response->apis->ticketing) ? $response->apis->ticketing : null;
            $tokenExpiration = isset($response->expiration) ? $response->expiration : null;

            $this->session->set('accessTokenMessenger', $this->accessToken);
            $this->session->set('messengerUrl', $this->messengerUrl);
            $this->session->set('accessTokenMessengerExpiration', $tokenExpiration);
        }
    }

    /**
     * Validate if session exists and if the token is on time
     */
    private function validateSession()
    {
        if (
            $this->session->get('accessTokenMessenger', '') !== '' && $this->session->get('messengerUrl', '') !== ''
            && !is_null($this->session->get('accessTokenMessenger', '')) && !is_null($this->session->get('messengerUrl', ''))
            && $this->session->get('accessTokenMessengerExpiration', 0) > $this->currentTime + 10
        ) {

            $this->accessToken = $this->session->get('accessTokenMessenger');
            $this->messengerUrl = $this->session->get('messengerUrl');
            return true;
        }
        return false;
    }


    /**
     * Handle the incoming message from the ticket
     * @param object $request
     * @return void
     */
    public function handleMessageFromClosedTicket($request)
    {
        if (!is_null($this->accessToken) && isset($request->events) && isset($request->events[0]) && isset($request->events[0]->resource_data)) {
            $userEmail = $request->events[0]->resource_data->creator->identifier;
            $ticketNumber = $request->events[0]->resource;
            $message = $request->events[0]->action_data->text;

            if ($userEmail !== "") {
                $headers = [
                    'x-inbenta-key' => $this->apiKey,
                    'Authorization' => 'Bearer ' . $this->accessToken
                ];
                $response = $this->remoteRequest($this->messengerUrl . "/v1/users?address=" . $userEmail, "get", [], $headers, "data");
                if (isset($response[0]) && isset($response[0]->extra) && isset($response[0]->extra[0]) && isset($response[0]->extra[0]->id)) {
                    if ($response[0]->extra[0]->id == 2 && $response[0]->extra[0]->content !== "") {

                        $number = $response[0]->extra[0]->content;
                        if (strpos($number, "-") > 0) {
                            $number = str_replace("d360-", "", $number);

                            $requestToSend = (object) [
                                'messages' => [
                                    (object) ['from' => $number]
                                ]
                            ];

                            $intro = "Hi, I'm the agent that you spoke to a while ago. My response to your question";
                            $ticketInfo = "Here is the ticket number for your reference";
                            $end = "You can now continue chatting with the chatbot. If you want to talk to someone, type 'agent'. Thank you!";
                            if (!is_null($this->lang)) {
                                $intro = $this->lang->translate('ticket_response_intro');
                                $ticketInfo = $this->lang->translate('ticket_response_info');
                                $end = $this->lang->translate('ticket_response_end');
                            }

                            $newMessage = "_" . $intro . ":_\n";
                            $newMessage .= $message . "\n\n";
                            $newMessage .= "_" . $ticketInfo . ": *" . $ticketNumber . "*_\n";
                            $newMessage .= "_" . $end . "_";

                            $externalClient = new D360APIClient($this->config['360'], $requestToSend); // Instance 360 client
                            $externalClient->sendTextMessage($newMessage);
                        }
                    }
                }
            }
        }
        die;
    }


    /**
     * Save the user phone number when the agent conversation is closed
     * @param object $userData
     * @param string $number
     * @return void
     */
    public function saveUserPhoneNumber($userData, $number)
    {
        if (!is_null($this->accessToken) && isset($userData->EMAIL_ADDRESS) && $userData->EMAIL_ADDRESS !== "" && $number != "") {
            $email = $userData->EMAIL_ADDRESS;

            $headers = [
                'x-inbenta-key' => $this->apiKey,
                'Authorization' => 'Bearer ' . $this->accessToken
            ];
            $response = $this->remoteRequest($this->messengerUrl . "/v1/users?address=" . $email, "get", [], $headers, "data");

            if (isset($response[0]) && isset($response[0]->id)) {
                $idUser = $response[0]->id;
                $dataSave = [
                    "extra" => [
                        [
                            "id" => 2,
                            "content" => $number
                        ]
                    ]
                ];
                $this->remoteRequest($this->messengerUrl . "/v1/users/" . $idUser, "put", $dataSave, $headers, "");
            }
        }
    }
}
