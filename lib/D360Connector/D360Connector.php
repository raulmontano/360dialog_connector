<?php

namespace Inbenta\D360Connector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;

use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\D360Connector\ChatbotAPIClientVariables; //TEMPORAL, JUST FOR ADD THE setVariable FUNCTION (USED FOR ESCALATION), MUST DELETE ON CONNECTOR V2

use Inbenta\D360Connector\ExternalAPI\D360APIClient;
use Inbenta\D360Connector\ExternalDigester\D360Digester;
use Inbenta\D360Connector\HyperChatAPI\D360HyperChatClient;
use Inbenta\D360Connector\Helpers\Helper;
use Inbenta\D360Connector\MessengerAPI\MessengerAPI;
use Inbenta\D360Connector\SubscribeWebhook;


class D360Connector extends ChatbotConnector
{

    //  M U S T   B E   ON   P A R E N T
    const ESCALATION_DIRECT          = '__escalation_type_callback__';
    const ESCALATION_OFFER           = '__escalation_type_offer__';

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

            $this->session = new SessionManager($this->getExternalIdFromRequest());

            if (isset($request->messages) && isset($request->messages[0]) && isset($request->messages[0]->id)) {
                //Prevent double request from 360 Dialog
                if ($this->session->get('lastMessageId', "") !== "" && $this->session->get('lastMessageId', "") === $request->messages[0]->id) {
                    die;
                }
                $this->session->set('lastMessageId', $request->messages[0]->id);
            }

            $this->botClient = new ChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);
            $this->botClientVariables = new ChatbotAPIClientVariables($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf, $this->botClient);

            // Try to get the translations from ExtraInfo and update the language manager
            $this->getTranslationsFromExtraInfo('360', 'translations');

            // Initialize Hyperchat events handler
            if ($this->conf->get('chat.chat.enabled') && ($this->session->get('chatOnGoing', false) || isset($_SERVER['HTTP_X_HOOK_SECRET']))) {
                $chatEventsHandler = new D360HyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $this->externalClient);
                $chatEventsHandler->handleChatEvent();
            } else if (isset($_SERVER['HTTP_X_HOOK_SIGNATURE']) && $_SERVER['HTTP_X_HOOK_SIGNATURE'] == $this->conf->get('chat.messenger.webhook_secret')) {
                $messengerAPI = new MessengerAPI($this->conf, $this->lang, $this->session);
                $messengerAPI->handleMessageFromClosedTicket($request);
            }

            // Instance application components
            $externalClient        = new D360APIClient($this->conf->get('360'), $request); // Instance 360 client
            $chatClient            = new D360HyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $externalClient);  // Instance HyperchatClient for 360
            $externalDigester      = new D360Digester($this->lang, $this->conf->get('conversation.digester'), $this->session); // Instance 360 digester

            $this->initComponents($externalClient, $chatClient, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
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
     *	Override useless facebook function from parent
     */
    protected function returnOkResponse()
    {
        return true;
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
        // If there is a active chat, send messages to the agent
        if ($this->chatOnGoing()) {
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
                define('APP_SECRET', $this->conf->get('chat.chat.secret'));
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
        if ($this->session->get('federatedSubanswers') && count($digestedRequest) && isset($digestedRequest[0]['message'])) {
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
                if ($index + 1 == (int) $userAnswer[0]['message'] || Helper::removeAccentsToLower($userAnswer[0]['message']) === Helper::removeAccentsToLower($this->lang->translate($option['label']))) {
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
     * @param [type] $externalRequest
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



    //THIS CHANGES INCLUDE ESCALATION V2 (ESCALATION_OFFER AND ESCALATION_DIRECT)
    //OVERRIDED FUNCTIONS AND ADDED FUNCTIONS THAT DON'T EXIST

    /**
     *	Retrieve Language translations from ExtraInfo
     */
    protected function getTranslationsFromExtraInfo($parentGroupName, $translationsObjectName)
    {
        $translations = [];
        $extraInfoData = $this->botClient->getExtraInfo($parentGroupName);
        if (isset($extraInfoData->results)) {
            foreach ($extraInfoData->results as $element) {
                if ($element->name == $translationsObjectName) {
                    $translations = json_decode(json_encode($element->value), true);
                    break;
                }
            }
            $language = $this->conf->get('conversation.default.lang');
            if (isset($translations[$language]) && count($translations[$language]) && is_array($translations[$language][0])) {
                $this->lang->addTranslations($translations[$language][0]);
            }
        }
    }


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
                    $this->session->set('escalationV2', true);
                } elseif ($apiEscalateDirect) {
                    $escalationType = static::ESCALATION_DIRECT;
                    $this->session->set('escalationV2', true);
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
        // Escalate if it has the form done
        $this->escalateIfFormHasBeenDone();

        // Ask the user if wants to escalate
        if (!$this->session->get('askingForEscalation', false)) {
            if ($this->session->get('escalationType') == static::ESCALATION_DIRECT) {
                $this->sendEscalationStart();
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
                    if ($this->session->get('escalationType') == static::ESCALATION_OFFER) {
                        $this->sendEscalationStart();
                    } else {
                        $this->escalateToAgent();
                    }
                } else {
                    if ($this->session->get('escalationType') == static::ESCALATION_OFFER) {
                        $message = ["message" => "no"];
                        $botResponse = $this->sendMessageToBot($message);
                        $this->sendMessagesToExternal($botResponse);
                    } else {
                        $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('escalation_rejected')));
                        $this->trackContactEvent("CONTACT_REJECTED");
                    }
                    $this->session->delete('escalationType');
                    $this->session->delete('escalationV2');
                }
                die();
            }
        }
    }


    /** NEW FUNCTION
     * Send the data for start scalation
     */
    private function sendEscalationStart()
    {
        if ($this->checkAgents()) {
            $this->setVariableValue("agents_available", "true");
        } else {
            $this->setVariableValue("agents_available", "false");
            $this->session->delete('escalationForm');
        }
        $message = ["directCall" => "escalationStart"];
        $botResponse = $this->sendMessageToBot($message);
        $this->sendMessagesToExternal($botResponse);
    }


    /** NEW FUNCTION
     * Check if in the $botResponse exists the "escalateToAgent" callback
     * @param object $botResponse
     * @return bool
     */
    public function checkEscalationForm($botResponse)
    {
        if ($this->session->get('escalationV2', false)) {
            // Parse bot messages
            if (isset($botResponse->answers) && is_array($botResponse->answers)) {
                $messages = $botResponse->answers;
            } else {
                $messages = array($botResponse);
            }
            // Check if BotApi returned 'escalate' flag on message or triesBeforeEscalation has been reached
            foreach ($messages as $msg) {
                $this->updateNoResultsCount($msg);
                $resetSession  = isset($msg->actions) && isset($msg->actions);
                if ($resetSession && ($msg->actions[0]->parameters->callback == "escalateToAgent")) {
                    $data = $msg->actions[0]->parameters->data;
                    $this->session->set('escalationForm', $data);
                    return true;
                }
            }
        }
        return false;
    }


    /** NEW FUNCTION
     * Escalate to an agent if the escalation form has been done
     * @return void
     */
    public function escalateIfFormHasBeenDone()
    {
        if ($this->session->get('escalationV2', false)) {
            $escalationFormData = $this->session->get('escalationForm', false);
            if ($escalationFormData) {
                if ($escalationFormData) {
                    $this->externalClient->setFullName($escalationFormData->FIRST_NAME . ' ' . $escalationFormData->LAST_NAME);
                    $this->externalClient->setEmail($escalationFormData->EMAIL_ADDRESS);
                    $this->externalClient->setExtraInfo((array) $escalationFormData);
                }
                $this->escalateToAgent();
                die;
            }
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
            $chatData = array(
                'roomId' => $this->conf->get('chat.chat.roomId'),
                'user' => array(
                    'name'             => trim($this->externalClient->getFullName()),
                    'contact'         => trim($this->externalClient->getEmail()),
                    'externalId'     => $this->externalClient->getExternalId(),
                    'extraInfo'     => []
                )
            );
            $response =  $this->chatClient->openChat($chatData);
            if (!isset($response->error) && isset($response->chat)) {
                $this->session->set('chatOnGoing', $response->chat->id);
                if ($this->session->get('escalationV2', false)) {
                    $this->trackContactEvent("CHAT_ATTENDED", $response->chat->id);
                } else {
                    $this->trackContactEvent("CONTACT_ATTENDED");
                }
            } else {
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('error_creating_chat')));
            }
        } else {
            // Send no-agents-available message if the escalation trigger is an API flag (user asked for having a chat explicitly)
            if ($this->session->get('escalationType') == static::ESCALATION_API_FLAG || $this->session->get('escalationV2', false)) {

                if ($this->session->get('escalationV2', false)) {
                    $this->setVariableValue("agents_available", "false");
                    $message = ["directCall" => "escalationStart"];
                    $botResponse = $this->sendMessageToBot($message);
                    $this->sendMessagesToExternal($botResponse);
                } else {
                    $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
                }
            } else if ($this->session->get('escalationV2', false)) {
                $this->trackContactEvent("CHAT_UNATTENDED");
            } else {
                $this->trackContactEvent("CONTACT_UNATTENDED");
            }
        }
        $this->session->delete('escalationType');
        $this->session->delete('escalationV2');
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
