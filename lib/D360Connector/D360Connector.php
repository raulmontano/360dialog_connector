<?php

namespace Inbenta\D360Connector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\D360Connector\ExternalAPI\D360APIClient;
use Inbenta\D360Connector\ExternalDigester\D360Digester;
use Inbenta\D360Connector\HyperChatAPI\D360HyperChatClient;
use Inbenta\D360Connector\Helpers\Helper;
use Inbenta\ChatbotConnector\MessengerAPI\MessengerAPIClient;
use Inbenta\D360Connector\SubscribeWebhook;


class D360Connector extends ChatbotConnector
{
    public function __construct($appPath)
    {
        // Initialize and configure specific components for 360 dialog
        try {
            // Initialize base components
            parent::__construct($appPath);

            $request = json_decode(file_get_contents('php://input'));

            if (isset($_GET["subscribe"])) {
                SubscribeWebhook::subscribe($this->conf->get('360'), $_GET["subscribe"]);
            }

            $conversationConf = [
                'configuration' => $this->conf->get('conversation.default'),
                'userType' => $this->conf->get('conversation.user_type'),
                'environment' => $this->environment,
                'source' => $this->conf->get('conversation.source')
            ];
            $externalId = $this->getExternalIdFromRequest();
            $this->session = new SessionManager($externalId);
            $this->validatePreviousMessages($request);

            $this->storeExternalId($externalId);

            $this->botClient = new ChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);

            if ($this->conf->get('api.messenger.key') !== '' && $this->conf->get('api.messenger.secret') !== '') {
                $this->messengerClient = new MessengerAPIClient($this->conf->get('api.messenger.key'), $this->conf->get('api.messenger.secret'), $this->session);
            }

            // Try to get the translations from ExtraInfo and update the language manager
            $this->getTranslationsFromExtraInfo('360', 'translations');

            // Initialize Hyperchat events handler
            if ($this->conf->get('chat.chat.enabled') && ($this->session->get('chatOnGoing', false) || isset($_SERVER['HTTP_X_HOOK_SECRET']))) {
                $chatEventsHandler = new D360HyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $this->externalClient, $this->messengerClient);
                $chatEventsHandler->handleChatEvent();
            } else if (isset($_SERVER['HTTP_X_HOOK_SIGNATURE']) && $_SERVER['HTTP_X_HOOK_SIGNATURE'] == $this->conf->get('api.messenger.webhook_secret')) {
                $this->handleMessageFromClosedTicket($request, $this->conf->get('360'));
            }

            // Instance application components
            $externalClient        = new D360APIClient($this->conf->get('360'), $request); // Instance 360 client
            $chatClient            = new D360HyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $externalClient, $this->messengerClient);  // Instance HyperchatClient for 360
            $externalDigester      = new D360Digester($this->lang, $this->conf->get('conversation.digester'), $this->session, $externalClient); // Instance 360 digester

            $this->initComponents($externalClient, $chatClient, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     * Save in session the external Id
     */
    protected function storeExternalId($externalId)
    {
        if (is_null($this->session->get('externalId'))) {
            $externalId = D360APIClient::getIdFromExternalId($externalId);
            if ($externalId !== '') {
                $this->session->set('externalId', $externalId);
            }
        }
    }


    /**
     * Return external id from request (Hyperchat of 360)
     */
    protected function getExternalIdFromRequest()
    {
        // Try to get user_id from a 360 message request
        $externalId = D360APIClient::buildExternalIdFromRequest();
        if (is_null($externalId)) {
            // Try to get user_id from a Hyperchat event request
            $externalId = D360HyperChatClient::buildExternalIdFromRequest($this->conf->get('chat.chat'));
        }
        if (empty($externalId)) {
            $api_key = $this->conf->get('api.key');
            if (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
                // Create a temporary session_id from a HyperChat webhook linking request
                $externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
            } elseif (isset($_SERVER['HTTP_X_HOOK_SIGNATURE'])) {
                $externalId = "response-from-agent";
            } else {
                throw new Exception("Invalid request");
                die();
            }
        }
        return $externalId;
    }

    /**
     * Return if only chat mode is active
     *
     * @return boolean
     */
    protected function isOnlyChat()
    {
        $onlyChatMode = false;
        $validateCustom = true;
        $extraInfoData = $this->botClient->getExtraInfo('360');

        if (isset($extraInfoData->results)) {
            // Get the settings data from extra info
            foreach ($extraInfoData->results as $element) {
                if ($element->name == 'settings') {
                    $onlyChatMode = isset($element->value->only_chat_mode) && $element->value->only_chat_mode === 'true' ? true : false;
                    $validateCustom = false;
                    break;
                }
            }
        }
        // Get data from configuration file if it has not been set on ExtraInfo
        if (!$onlyChatMode && $validateCustom) {
            $onlyChatMode = $this->conf->get('custom.onlyHyperChatMode', false);
        }
        return $onlyChatMode;
    }

    /**
     * 	Display content rating message and its options
     */
    protected function displayContentRatings($rateCode)
    {
        $ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
        $ratingMessage = $this->digester->buildContentRatingsMessage($ratingOptions, $rateCode);
        $this->session->set('askingRating', true);
        $this->session->set('rateCode', $rateCode);
        usleep(1000000); //Delay response, sleeps 1 second
        $this->externalClient->sendMessage($ratingMessage);
    }


    /**
     *	Check if it's needed to perform any action other than a standard user-bot interaction
     */
    protected function handleNonBotActions($digestedRequest)
    {
        //Check if a survey is running
        $this->handleSurvey($digestedRequest);

        // If there is a active chat, send messages to the agent
        if ($this->chatOnGoing()) {
            $this->validateIsInHyperchatQueue($digestedRequest);

            if ($this->isCloseChatCommand($digestedRequest)) {
                $chatData = [
                    'roomId' => $this->conf->get('chat.chat.roomId'),
                    'user' => [
                        'name' => $this->externalClient->getFullName(),
                        'contact' => $this->externalClient->getEmail(),
                        'externalId' => $this->externalClient->getExternalId(),
                        'extraInfo' => []
                    ]
                ];
                $this->chatClient->closeChat($chatData);
                $this->externalClient->sendTextMessage($this->lang->translate('chat_closed'));
                $this->session->set('chatOnGoing', false);
            } else {
                $this->sendMessagesToChat($digestedRequest);
            }
            die();
        }
        // If user answered to an ask-to-escalate question, handle it
        if ($this->session->get('askingForEscalation', false)) {
            $this->handleEscalation($digestedRequest);
        }

        // CUSTOM If user answered to a rating question, handle it
        if ($this->session->get('askingRating', false)) {
            $this->handleRating($digestedRequest);
        }

        // If the bot offered Federated Bot options, handle its request
        if ($this->session->get('federatedSubanswers') && is_array($digestedRequest) && isset($digestedRequest[0]['message'])) {
            $selectedAnswer = $digestedRequest[0]['message'];
            $federatedSubanswers = $this->session->get('federatedSubanswers');
            $this->session->delete('federatedSubanswers');
            foreach ($federatedSubanswers as $key => $answer) {
                if ($selectedAnswer === $answer->attributes->title || ((int) $selectedAnswer - 1) == $key) {
                    $this->displayFederatedBotAnswer($answer);
                    die();
                }
            }
        }
    }

    /**
     * 	Ask the user if wants to talk with a human and handle the answer
     * @param array $userAnswer = null
     */
    protected function handleRating($userAnswer = null)
    {
        // Ask the user if wants to escalate
        // Handle user response to an rating question
        $this->session->set('askingRating', false);
        $ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
        $ratingCode = $this->session->get('rateCode', false);
        $event = null;

        if (count($userAnswer) && isset($userAnswer[0]['message']) && $ratingCode) {
            foreach ($ratingOptions as $index => $option) {
                if (Helper::removeAccentsToLower($userAnswer[0]['message']) === Helper::removeAccentsToLower($this->lang->translate($option['label']))) {
                    $event = $this->formatRatingEvent($ratingCode, $option['id']);
                    if (isset($option["comment"]) && $option["comment"]) {
                        $this->session->set('askingRatingComment', $event);
                    }
                    break;
                }
            }
            if ($event) {
                // Rate if the answer was correct
                $this->sendMessagesToExternal($this->sendEventToBot($event));
                die;
            } else { //If no rating given, show a message and continue
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_rating_given')));
            }
        }
    }

    /**
     * Return formated rate event
     *
     * @param string $ratingCode
     * @param integer $ratingValue
     * @return array
     */
    private function formatRatingEvent($ratingCode, $ratingValue, $comment = '')
    {
        return [
            'type' => 'rate',
            'data' => [
                'code' => $ratingCode,
                'value' => $ratingValue,
                'comment' => $comment
            ]
        ];
    }

    /**
     * Validate if the message has the close command (/close)
     */
    private function isCloseChatCommand($userMessage)
    {
        if (isset($userMessage[0]) && isset($userMessage[0]['message'])) {
            return $userMessage[0]['message'] === $this->lang->translate('close_chat_key_word');
        }
        return false;
    }

    /**
     * Direct call to sys-welcome message to force escalation
     *
     * @param $externalRequest
     * @return void
     */
    public function handleBotActions($externalRequest)
    {
        $needEscalation = false;
        $needContentRating = false;
        $hasFormData = false;
        foreach ($externalRequest as $message) {
            // if the session just started throw sys-welcome message
            if ($this->isOnlyChat()) {
                if ($this->checkAgents()) {
                    $this->escalateToAgent();
                } else {
                    // throw no agents message
                    $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
                    $this->session->clear();
                    return false;
                }
            }
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
            // Check if it has attached an escalation form
            $hasFormData = $this->checkEscalationForm($botResponse);

            // Check if is needed to display content ratings
            $hasRating = $this->checkContentRatings($botResponse);
            $needContentRating = $hasRating ? $hasRating : $needContentRating;

            // Send the messages received from ChatbotApi back to the external service
            $this->sendMessagesToExternal($botResponse);
        }
        if ($needEscalation || $hasFormData) {
            $this->handleEscalation();
        }
        // Display content rating if needed and not in chat nor asking to: escalate, related content, options, etc
        if ($needContentRating && !$this->chatOnGoing() && !$this->session->get('askingForEscalation', false) && !$this->session->get('hasRelatedContent', false) && !$this->session->get('options', false)) {
            $this->displayContentRatings($needContentRating);
        }
    }

    /**
     * Validate if the id of the recent message is not previously sent
     * this prevents double request from 360 Dialog
     */
    private function validatePreviousMessages($request)
    {
        if (isset($request->messages[0]->id) || isset($request->events[0]->id)) {
            $idCurrentMessage = isset($request->messages[0]->id) ? $request->messages[0]->id : $request->events[0]->id;
            $lastMessagesId = $this->session->get('lastMessagesId', false);
            if (!is_array($lastMessagesId)) {
                $lastMessagesId = [];
            }
            if (in_array($idCurrentMessage, $lastMessagesId)) {
                die;
            }
            $lastMessagesId[time()] = $idCurrentMessage;

            foreach ($lastMessagesId as $key => $messageSent) {
                if ((time() - 240) > $key) {
                    //Deletes the stored incomming messages with more than 240 seconds
                    unset($lastMessagesId[$key]);
                }
            }
            $this->session->set('lastMessagesId', $lastMessagesId);
        }
    }

    /**
     * Before calling to the parent handleRequest() validate if there is a survey confirmation
     */
    public function handleRequest()
    {
        $this->surveyConfirm();
        parent::handleRequest();
    }

    /**
     * Check if the confirmation for the survey exists
     */
    public function surveyConfirm()
    {
        if ($this->session->get('surveyConfirm')) {
            if ($this->conf->get('chat.chat.survey.confirmToStart')) {
                $this->validateIfAskForSurvey();
            } else { //Direct survey, without confirm to start
                $this->externalClient->setSenderFromId($this->session->get('externalId'));
                $this->processSurveyData($this->session->get('surveyElements'));
                $this->session->delete('surveyConfirm');
                $this->session->set('surveyLaunch', true);
            }
        }
    }

    /**
     * Handle the incoming message from the ticket
     * @param object $request
     * @return void
     */
    public function handleMessageFromClosedTicket($request, $config360)
    {
        if (
            !is_null($this->messengerClient) && isset($request->events[0]->resource_data->creator->identifier)
            && isset($request->events[0]->resource) && isset($request->events[0]->action_data->text)
        ) {
            $userEmail = $request->events[0]->resource_data->creator->identifier;
            $ticketNumber = $request->events[0]->resource;
            $message = $request->events[0]->action_data->text;

            if ($userEmail !== "") {
                $response = $this->messengerClient->getUserByParam('address', $userEmail);
                if (
                    isset($response->data[0]->extra[0]->id) && isset($response->data[0]->extra[0]->content) &&
                    $response->data[0]->extra[0]->id == 2 && $response->data[0]->extra[0]->content !== ""
                ) {
                    $number = $response->data[0]->extra[0]->content;
                    if (strpos($number, "-") > 0) {
                        $number = str_replace("d360-", "", $number);

                        $intro = $this->lang->translate('ticket_response_intro');
                        $ticketInfo = $this->lang->translate('ticket_response_info');
                        $end = $this->lang->translate('ticket_response_end');

                        $newMessage = "_" . $intro . ":_\n";
                        $newMessage .= $message . "\n\n";
                        $newMessage .= "_" . $ticketInfo . ": *" . $ticketNumber . "*_\n";
                        $newMessage .= "_" . $end . "_";

                        $requestToSend = (object) [
                            'messages' => [
                                (object) ['from' => $number]
                            ]
                        ];
                        $externalClient = new D360APIClient($config360, $requestToSend); // Instance 360 client
                        $externalClient->sendTextMessage($newMessage);
                    }
                }
            }
        }
        die;
    }
}
