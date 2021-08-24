<?php

namespace Inbenta\D360Connector\HyperChatAPI;

use Inbenta\ChatbotConnector\HyperChatAPI\HyperChatClient;
use Inbenta\D360Connector\ExternalAPI\D360APIClient;

class D360HyperChatClient extends HyperChatClient
{
    private $eventHandlers = array();
    private $appConf;
    private $externalId;
    protected $session;
    protected $messengerClient;

    function __construct($config, $lang, $session, $appConf, $externalClient, $messengerClient = null)
    {
        // CUSTOM added session attribute to clear it
        $this->session = $session;
        $this->appConf = $appConf;
        $this->messengerClient = $messengerClient;
        parent::__construct($config, $lang, $session, $appConf, $externalClient);
    }

    //Instances an external client
    protected function instanceExternalClient($externalId, $appConf)
    {
        $userNumber = D360APIClient::getUserNumberFromExternalId($externalId);
        if (is_null($userNumber)) {
            return null;
        }
        $externalClient = new D360APIClient($appConf->get('360'), null);

        $externalClient->setSenderFromId($userNumber);
        $this->externalId = $externalClient->getExternalId();

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

    /**
     * Overwirtten method to add system:info case
     * Handle an incoming event and perform the required logic
     */
    public function handleEvent()
    {
        // listen for a webhook handshake call
        if ($this->webhookHandshake() === true) {
            return;
        }

        // get event data
        $event = json_decode(file_get_contents('php://input'), true);
        if (
            !empty($event) &&
            isset($event['trigger']) &&
            !empty($event['data'])
        ) {
            $eventData = $event['data'];

            // if the event trigger has a custom handler defined, execute this one
            if (in_array($event['trigger'], array_keys($this->eventHandlers))) {
                $handler = $this->eventHandlers[$event['trigger']];
                return $handler($event);
            }
            // or respond with the default logic depending on the event type
            switch ($event['trigger']) {
                case 'messages:new':
                    if (empty($eventData['message'])) {
                        return;
                    }
                    $messageData = $eventData['message'];

                    $chat = $this->getChatInfo($messageData['chat']);
                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }
                    $sender = $this->getUserInfo($messageData['sender']);
                    if (!empty($sender->providerId)) {
                        $targetUser = $this->getUserInfo($chat->creator);

                        if ($messageData['type'] === 'media') {
                            $fullUrl = $this->getContentUrl($messageData['message']['url']);
                            $messageData['message']['fullUrl'] = $fullUrl;
                            $messageData['message']['contentBase64'] =
                                'data:' . $messageData['message']['type'] . ';base64,' .
                                base64_encode(file_get_contents($fullUrl));
                        }

                        // send message
                        $this->extService->sendMessageFromAgent(
                            $chat,
                            $targetUser,
                            $sender,
                            $messageData,
                            $event['created_at']
                        );
                    }

                    break;

                case 'chats:close':
                    $chat = $this->getChatInfo($eventData['chatId']);

                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }

                    $userId = $eventData['userId'];
                    $isSystem = ($userId === 'system') ? true : false;
                    $user = !$isSystem ? $this->getUserById($eventData['userId']) : null;

                    if (($user && !empty($user->providerId)) || $isSystem) {
                        $targetUser = $this->getUserInfo($chat->creator);

                        //On close, save customer phone number, with the given email
                        $this->updatesUserPhone($targetUser);

                        // notify chat close
                        $attended = true;
                        $this->extService->notifyChatClose(
                            $chat,
                            $targetUser,
                            $isSystem,
                            $attended,
                            !$isSystem ? $user : null
                        );

                        if (isset($chat->id)) {
                            $this->hasSurvey($chat->id);
                        }
                    }

                    break;
                case 'invitations:new':

                    break;
                case 'invitations:accept':

                    $chat = $this->getChatInfo($eventData['chatId']);

                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }

                    $agent = $this->getUserById($eventData['userId']);
                    $targetUser = $this->getUserInfo($chat->creator);
                    $this->extService->notifyChatStart($chat, $targetUser, $agent);
                    $this->session->set('chatInvitationAccepted', true);
                    break;

                case 'users:activity':
                    $chat = $this->getChatInfo($eventData['chatId']);

                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }

                    $targetUser = $this->getUserInfo($chat->creator);

                    switch ($eventData['type']) {
                        case 'not-writing':
                            $this->extService->sendTypingPaused($chat, $targetUser);
                            break;
                        case 'writing':
                            $this->extService->sendTypingActive($chat, $targetUser);
                            break;
                        default:
                            $this->extService->sendTypingPaused($chat, $targetUser);
                            break;
                    }

                    break;

                case 'forever:alone':
                    $chat = $this->getChatInfo($event['data']['chatId']);

                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }

                    $targetUser = $this->getUserInfo($chat->creator);

                    // close chat on server
                    $this->api->chats->close($chat->id, array('secret' => $this->config->get('secret')));

                    $system = true;
                    $attended = false;
                    $this->extService->notifyChatClose($chat, $targetUser, $system, $attended);

                    if (isset($chat->id)) {
                        $this->hasSurvey($chat->id);
                    }

                    break;
                case 'system:info': // CUSTOM case
                    $this->attachSurveyToTicket($event);
                    break;

                case 'queues:update':
                    $chat = $this->getChatInfo($eventData['chatId']);
                    if (!$chat || $chat->source !== $this->config->get('source')) {
                        return;
                    }
                    $user = null;
                    if (isset($eventData['userId'])) {
                        $user = $this->getUserInfo($eventData['userId']);
                    }
                    $data = $eventData['data'];
                    $this->extService->notifyQueueUpdate($chat, $user, $data);
            }
        }
    }

    /**
     * Overwritten method to allow use it
     * Perform webhook handshake (only executed on the webhook setup request)
     * @return void
     */
    private function webhookHandshake()
    {
        if (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
            // get the webhook secret
            $xHookSecret = $_SERVER['HTTP_X_HOOK_SECRET'];
            // set response header
            header('X-Hook-Secret: ' . $xHookSecret);
            // set response status code
            http_response_code(200);
            return true;
        }
        return false;
    }

    /**
     * Attach a survey to the ticket
     *
     * @param array $event HyperChat system:info event
     *
     * @return void
     */
    protected function attachSurveyToTicket($event)
    {
        $ticketId = $event['data']['data']['ticketId'];
        $surveyConfig = $this->config->get('survey');
        $surveyId = $surveyConfig['id'];

        // Only send the survey if it's properly configured
        if ($surveyId !== '' && $surveyId !== null) {
            $response = $this->api->get(
                'surveys/' . $surveyId,
                [
                    'secret' => $this->config->get('secret'),
                ],
                [
                    'sourceType' => 'ticket',
                    'sourceId' => $ticketId
                ]
            );
            // Send the survey URL to the user
            $this->extService->sendMessageFromSystem(null, null, $response->survey->url, null);
        }
        // Clear chatbot session when chat is closed
        $this->session->clear();
    }

    /**
     * Updates Messenger user phone number
     */
    protected function updatesUserPhone($targetUser)
    {
        if (
            !is_null($this->messengerClient) && $this->externalId !== '' &&
            isset($targetUser->contact) && trim($targetUser->contact) !== '' 
        ) {
            $email = $targetUser->contact;
            $userData = $this->messengerClient->getUserByParam('address', $email);
            if (isset($userData->data) && isset($userData->data[0]->id)) {
                $idUser = $userData->data[0]->id;
                $dataSave = [
                    "extra" => [
                        [
                            "id" => 2,
                            "content" => $this->externalId
                        ]
                    ]
                ];
                $this->messengerClient->updatesUserInfo($idUser, $dataSave);
            }
        }
    }
}
