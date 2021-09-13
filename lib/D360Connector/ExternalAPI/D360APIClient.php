<?php

namespace Inbenta\D360Connector\ExternalAPI;

use Inbenta\D360Connector\ExternalDigester\D360Digester;
use GuzzleHttp\Client as Guzzle;
use Exception;

class D360APIClient
{
    public $apiKey;
    public $url;

    public $to;
    public $email;
    public $fullName;
    public $extraInfo;


    /**
     * 360 constructor
     *
     * @param string $apiKey
     * @param object $request
     */
    public function __construct(array $config, object $request = null)
    {
        $this->apiKey = $config['api_key'];
        $this->url = $config['url_messages'];
        $this->to = isset($request->messages[0]->from) ? $request->messages[0]->from : null; //From > To
    }


    /**
     * Send the message to 360 dialog service
     * @param array $payload
     */
    private function sendTo360(array $payload)
    {
        $headers = [
            'Content-Type' => 'application/json',
            'D360-Api-Key' => $this->apiKey
        ];

        $client = new Guzzle();
        $response = $client->post($this->url, [
            'body' => json_encode($payload),
            'headers' => $headers
        ]);
        return $response;
    }


    /**
     * Build an external session Id using the following pattern:
     * 
     * @return string|null
     */
    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'));

        if (isset($request->messages[0]) && isset($request->messages[0]->id)) {
            return 'd360-' . $request->messages[0]->from;
        }
        return null;
    }


    /**
     * Establishes the 360 sender (user) directly with the provided phone numbers
     * @param string $companyPhoneNumber
     * @param string $userPhoneNumber
     * @return void
     */
    public function setSenderFromId($userPhoneNumber) //$companyPhoneNumber, 
    {
        //$this->from = $companyPhoneNumber;
        $this->to = $userPhoneNumber;
    }

    /**
     *   Generates the external id used by HyperChat to identify one user as external.
     *   This external id will be used by HyperChat adapter to instance this client class from the external id
     *   @return String external Id
     */
    public function getExternalId()
    {
        return 'd360-' . $this->to;
    }

    /**
     *   Retrieves the user id from the external ID generated by the getExternalId method
     */
    public static function getIdFromExternalId($externalId)
    {
        $userInfo = explode('-', $externalId);
        if (array_shift($userInfo) == 'd360') {
            return end($userInfo);
        }
        return null;
    }

    /**
     *  Retrieves the Account SID from the external ID generated by the getExternalId method
     *  @param String $externalId
     *  @return String|null user phone number or null
     */
    public static function getUserNumberFromExternalId($externalId)
    {
        $externalIdExploded = explode('-', $externalId);
        if (array_shift($externalIdExploded) == 'd360') {
            return $externalIdExploded[0];
        }
        return null;
    }

    /**
     * Send an outgoing message.
     *
     * @param array $message
     * @param string $type
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function send(array $message, string $type)
    {
        $destinationInfo = [
            "recipient_type" => "individual",
            "to" => $this->to,
            "type" => $type,
            $type => $message
        ];

        $response = $this->sendTo360($destinationInfo);

        usleep(400000); //Make a pause before the next message, sleeps 400 miliseconds
        return $response;
    }

    /**
     * Sends a message to 360. Needs a message formatted with the Whatsapp API notation
     *
     * @param array $message 
     * @return Psr\Http\Message\ResponseInterface $messageSend
     */
    public function sendMessage($elements)
    {
        $messageSend = false;
        $elementsArray = $elements;
        if (!isset($elements[0])) {
            $elementsArray = [
                $elements
            ];
        }
        foreach ($elementsArray as $subElements) {
            foreach ($subElements as $type => $element) {
                if ($type === 'text') {
                    if (trim($element) !== '') {
                        $param = ["body" => trim($element)];
                        $messageSend = $this->send($param, $type);
                    }
                } else if ($type === 'interactive') {
                    $messageSend = $this->send($element, $type);
                } else {
                    foreach ($element as $media) {
                        if (count($media) > 0) {
                            $messageSend = $this->send($media, $type);
                        }
                    }
                }
            }
        }
        return $messageSend;
    }


    /**
     *   Method needed
     */
    public function showBotTyping($show = true)
    {
        return true;
    }


    /**
     * Get the fullname attribute
     * @return string
     */
    public function getFullName()
    {
        if (is_null($this->fullName) || $this->fullName === '') {
            $request = file_get_contents('php://input');
            $requestDecoded = json_decode($request);
            if (isset($requestDecoded->contacts[0]->profile->name)) {
                $this->fullName = $requestDecoded->contacts[0]->profile->name;
            }
        }
        return $this->fullName;
    }

    /**
     *   Returns the user email or a default email made with the external ID
     *   @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     *   Returns the extra info data
     *   @return Array
     */
    public function getExtraInfo()
    {
        return $this->extraInfo;
    }


    /**
     * Get the phone number of the user
     */
    public function getUserPhone()
    {
        return $this->to;
    }


    /**
     * Set full name attribute
     *
     * @param String $fullName
     * @return void
     */
    public function setFullName($fullName)
    {
        $this->fullName = $fullName;
    }

    /**
     * Set extra info attributes
     *
     * @param Array $extraInfo
     * @return void
     */
    public function setExtraInfo($extraInfo)
    {
        $this->extraInfo = $extraInfo;
    }

    /**
     * Set email attribute
     *
     * @param String $email
     * @return void
     */
    public function setEmail($email)
    {
        $this->email = $email;
    }


    /**
     * Sends a message to 360. Needs a message formatted with the Whatsapp notation 
     */
    public function sendTextMessage($text)
    {
        $data = [
            'text' => $text
        ];
        if (strpos($text, "<") !== false) { //If text has a type of html tags
            $digester = new D360Digester('', '', '', '');
            $data = $digester->handleMessageWithImgOrIframe($text);
            $digester->handleMessageWithLinks($text);
            $digester->handleMessageWithTextFormat($text);
            $data["text"] = $digester->formatFinalMessage($text);
        }
        $this->sendMessage($data);
    }

    /**
     * Generates a 360 attachment message from HyperChat message
     *
     * @param array $message
     * @return void
     */
    public function sendAttachmentMessageFromHyperChat($message)
    {
        $supportedTypes = ["audio", "document", "image", "video", "voice"];
        $type = explode("/", $message['type']);

        if (count($type) > 0) {
            if ($type[1] === 'pdf') $type[0] = 'document';
            if (in_array($type[0], $supportedTypes)) {
                $media[$type[0]] = [
                    ['link' => $message['fullUrl']]
                ];
                $this->sendMessage($media);
            }
        }
    }

    /**
     * Get the media from 360
     * @param string $mediaId
     */
    public function getMediaFrom360(string $mediaId)
    {
        $urlMedia = str_replace("/messages", "/media/" . $mediaId, $this->url);
        $headers = [
            'D360-Api-Key' => $this->apiKey
        ];

        try {
            $client = new Guzzle();
            $response = $client->get($urlMedia, [
                'headers' => $headers
            ]);

            if (method_exists($response, "getBody") && method_exists($response->getBody(), "getContents")) {
                $fileFormat = explode("/", $response->getHeader('Content-Type')[0]);
                if (isset($fileFormat[1])) {
                    return [
                        "file" => $response->getBody()->getContents(),
                        "format" => $fileFormat[1]
                    ];
                }
            }
        } catch (Exception $e) {
            return "";
        }
        return "";
    }
}
