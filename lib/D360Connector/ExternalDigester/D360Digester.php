<?php

namespace Inbenta\D360Connector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Inbenta\D360Connector\Helpers\Helper;

class D360Digester extends DigesterInterface
{
    protected $conf;
    protected $channel;
    protected $langManager;
    protected $session;
    protected $externalClient;
    protected $attachableFormats;
    protected $buttonsMaxLength = 20;
    protected $buttonsListMaxLength = 24;
    protected $buttonsMaxIdLength = 256;

    /**
     * Digester contructor
     */
    public function __construct($langManager, $conf, $session, $externalClient)
    {
        $this->langManager = $langManager;
        $this->channel = '360';
        $this->conf = $conf;
        $this->session = $session;
        $this->externalClient = $externalClient;
        $this->attachableFormats = Helper::$attachableFormats;
    }

    /**
     *	Returns the name of the channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     **	Checks if a request belongs to the digester channel
     **/
    public static function checkRequest($_request)
    {
        $request = json_decode($_request);

        if (isset($request->messages)) {
            return true;
        }

        return false;
    }

    /**
     * Formats a channel request into an Inbenta Chatbot API request
     * @param string $_request
     * @return array
     */
    public function digestToApi($_request)
    {
        $request = json_decode($_request);
        $output = [];

        if (isset($request->messages) && isset($request->messages[0])) {
            $message = $request->messages[0];

            if ($this->session->has('options')) {
                $output = $this->checkOptions($message);
            }
            if (count($output) == 0 && (isset($message->button->text))) {
                $output[0] = ['message' => $message->button->text]; //From Templates messages
            } else if (count($output) == 0 && (isset($message->interactive->button_reply) || isset($message->interactive->list_reply))) {
                $reply = isset($message->interactive->button_reply) ? $message->interactive->button_reply : $message->interactive->list_reply;
                if (isset($reply->id)) {
                    if (is_numeric($reply->id)) {
                        $output[0] = ['option' => $reply->id];
                    } else if (strpos($reply->id, '__dc') === 0 && strpos($reply->id, 'DC__') === 5) {
                        $output[0] = ['directCall' => substr($reply->id, 9)];
                    } else {
                        $output[0] = ['message' => $reply->id];
                    }
                }
            } else if (count($output) == 0 && isset($message->text)) {
                $output[0] = ['message' => $message->text->body];
            }
            if (
                isset($message->image) || isset($message->document) || isset($message->video)
                || isset($message->audio) || isset($message->voice)
            ) {
                $output = $this->mediaFileToHyperchat($message);
            }
        }
        return $output;
    }

    /**
     * Check if the response has options
     * @param object $userMessage
     * @return array $output
     */
    protected function checkOptions(object $message)
    {
        $output = [];
        $lastUserQuestion = $this->session->get('lastUserQuestion');
        $options = $this->session->get('options');

        $this->session->delete('options');
        $this->session->delete('lastUserQuestion');
        $this->session->delete('hasRelatedContent');

        if (isset($message->interactive->button_reply) || isset($message->interactive->list_reply)) {
            $reply = isset($message->interactive->button_reply) ? $message->interactive->button_reply : $message->interactive->list_reply;
            $options = is_array($options) ? $options : (array) $options;
            if (isset($reply->id)) {
                if (!isset($message->text)) {
                    $message->text = (object) [];
                }
                if (strpos($reply->id, 'list_values_') !== false || strpos($reply->id, 'escalation_') !== false) {
                    $message->text->body = str_replace('list_values_', '', $reply->id);
                    $message->text->body = str_replace('escalation_', '', $message->text->body);
                } else if (isset($reply->title) && isset($options[0]->is_polar)) {
                    $message->text->body = $reply->title;
                }
            }
        }

        if (isset($message->text->body)) {
            $userMessage = $message->text->body;
            $selectedOption = false;
            $selectedOptionText = "";
            $selectedEscalation = "";
            $isRelatedContent = false;
            $isListValues = false;
            $forceNumberedList = false;
            $isPolar = false;
            $isEscalation = false;
            $optionSelected = false;
            foreach ($options as $option) {
                if (isset($option->list_values)) {
                    $isListValues = true;
                } else if (isset($option->related_content)) {
                    $isRelatedContent = true;
                } else if (isset($option->is_polar)) {
                    $isPolar = true;
                } else if (isset($option->escalate)) {
                    $isEscalation = true;
                } else if (isset($option->forceNumberedList)) {
                    $forceNumberedList = true;
                }
                if (
                    ((!$this->conf['active_buttons'] || $isEscalation || $isListValues || $forceNumberedList) && $userMessage == $option->opt_key) ||
                    Helper::removeAccentsToLower($userMessage) === Helper::removeAccentsToLower($this->langManager->translate($option->label))
                ) {
                    if ($isListValues || $isRelatedContent || (isset($option->attributes) && isset($option->attributes->DYNAMIC_REDIRECT) && $option->attributes->DYNAMIC_REDIRECT == 'escalationStart')) {
                        $selectedOptionText = $option->label;
                    } else if ($isEscalation) {
                        $selectedEscalation = $option->escalate;
                    } else {
                        $selectedOption = $option;
                        $lastUserQuestion = isset($option->title) && !$isPolar ? $option->title : $lastUserQuestion;
                    }
                    $optionSelected = true;
                    break;
                }
            }

            if (!$optionSelected) {
                if ($isListValues) { //Set again options for variable
                    if ($this->session->get('optionListValues', 0) < 1) { //Make sure only enters here just once
                        $this->session->set('options', $options);
                        $this->session->set('lastUserQuestion', $lastUserQuestion);
                        $this->session->set('optionListValues', 1);
                    } else {
                        $this->session->delete('options');
                        $this->session->delete('lastUserQuestion');
                        $this->session->delete('optionListValues');
                    }
                } else if ($isPolar) { //For polar, on wrong answer, goes for NO
                    $message->text->body = $this->langManager->translate('no');
                }
            }

            if ($selectedOption) {
                $output[] = ['option' => $selectedOption->value];
            } else if ($selectedOptionText !== "") {
                $output[] = ['message' => $selectedOptionText];
            } else if ($isEscalation && $selectedEscalation !== "") {
                if ($selectedEscalation === false) {
                    $output[] = ['message' => $this->langManager->translate('no')];
                } else {
                    $output[] = ['escalateOption' => $selectedEscalation];
                }
            } else {
                $output[] = ['message' => $message->text->body];
            }
        }
        return $output;
    }

    /**
     * Formats an Inbenta Chatbot API response into a channel request
     * @param object $request
     * @param string $lastUserQuestion = ''
     */
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        $messages = [];
        //Parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif (!is_null($this->checkApiMessageType($request))) {
            $messages = array('answers' => $request);
        }

        $output = [];
        foreach ($messages as $msg) {
            $msgType = $this->checkApiMessageType($msg);
            $digestedMessage = [];
            switch ($msgType) {
                case 'answer':
                    $digestedMessage = $this->digestFromApiAnswer($msg, $lastUserQuestion);
                    break;
                case 'polarQuestion':
                    $digestedMessage = $this->digestFromApiPolarQuestion($msg, $lastUserQuestion);
                    break;
                case 'multipleChoiceQuestion':
                    $digestedMessage = $this->digestFromApiMultipleChoiceQuestion($msg, $lastUserQuestion);
                    break;
                case 'extendedContentsAnswer':
                    $digestedMessage = $this->digestFromApiExtendedContentsAnswer($msg, $lastUserQuestion);
                    break;
            }
            if (count($digestedMessage) > 0) {
                $output[] = $digestedMessage;
            }
        }
        return $output;
    }

    /**
     **	Classifies the API message into one of the defined $apiMessageTypes
     **/
    protected function checkApiMessageType($message)
    {
        $responseType = null;
        foreach ($this->apiMessageTypes as $type) {
            switch ($type) {
                case 'answer':
                    $responseType = $this->isApiAnswer($message) ? $type : null;
                    break;
                case 'polarQuestion':
                    $responseType = $this->isApiPolarQuestion($message) ? $type : null;
                    break;
                case 'multipleChoiceQuestion':
                    $responseType = $this->isApiMultipleChoiceQuestion($message) ? $type : null;
                    break;
                case 'extendedContentsAnswer':
                    $responseType = $this->isApiExtendedContentsAnswer($message) ? $type : null;
                    break;
            }
            if (!is_null($responseType)) {
                return $responseType;
            }
        }
        throw new Exception("Unknown ChatbotAPI response: " . json_encode($message, true));
    }

    /********************** API MESSAGE TYPE CHECKERS **********************/

    protected function isApiAnswer($message)
    {
        return isset($message->type) && $message->type == 'answer';
    }

    protected function isApiPolarQuestion($message)
    {
        return isset($message->type) && $message->type == "polarQuestion";
    }

    protected function isApiMultipleChoiceQuestion($message)
    {
        return isset($message->type) && $message->type == "multipleChoiceQuestion";
    }

    protected function isApiExtendedContentsAnswer($message)
    {
        return isset($message->type) && $message->type == "extendedContentsAnswer";
    }

    protected function hasTextMessage($message)
    {
        return isset($message->message) && is_string($message->message);
    }


    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    protected function digestFromApiAnswer($message, $lastUserQuestion)
    {
        $output = [];
        if (isset($message->actionField) && !empty($message->actionField) && $message->actionField->fieldType !== 'default') {
            $output = $this->handleMessageWithActionField($message, $lastUserQuestion);
        }
        if (count($output) === 0) {
            if (!isset($message->messageList) && trim($message->message) !== "") {
                $message->messageList = [$message->message];
            } else if ((is_array($message->messageList) && count($message->messageList) == 0) || !is_array($message->messageList)) {
                $message->messageList = [""];
            }
            if (isset($message->attributes->SIDEBUBBLE_TEXT) && trim($message->attributes->SIDEBUBBLE_TEXT) !== "") {
                $countMessages = count($message->messageList);
                $message->messageList[$countMessages] = $message->attributes->SIDEBUBBLE_TEXT;
            }
            $outputTmp = [];
            foreach ($message->messageList as $index => $messageTxt) {
                $messageTxt = Helper::processHtml($messageTxt);
                $outputTmp[$index] = $this->splitMessagesInElements($messageTxt);
                $outputTmp[$index] = $this->groupTextMessages($outputTmp[$index]);
            }
            if (count($outputTmp) > 0) {
                foreach ($outputTmp as $element) {
                    if (count($element) > 0) {
                        $output = array_merge($output, $element);
                    }
                }
            }
        }
        $relatedContent = $this->handleMessageWithRelatedContent($message, $lastUserQuestion);
        if (count($relatedContent) > 0) {
            $output[] = $relatedContent;
        }

        return $output;
    }

    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, $isPolar = false)
    {
        $title = Helper::formatFinalMessage($message->message);
        $messageTmp = $title;
        $options = $message->options;
        $buttons = [];
        $rows = [];
        $isButton = ($isPolar || count($options) <= 3) ? true : false;
        $hasUniqueTitles = $isPolar ? true : $this->hasUniqueTitles($options, $isButton, 'label');

        foreach ($options as $i => &$option) {
            $option->opt_key = $i + 1;
            if (isset($option->attributes->title) && !$isPolar) {
                $option->title = $option->attributes->title;
            } else if ($isPolar) {
                $option->is_polar = true;
                $option->value = $option->value;
            }

            if (($this->conf['active_buttons'] || $isPolar) && $hasUniqueTitles) {
                if ($i == 10) break;

                $idButton = (string) $option->value;
                if (
                    isset($option->attributes->DIRECT_CALL) && trim($option->attributes->DIRECT_CALL) !== ''
                    && (strlen($option->attributes->DIRECT_CALL) + 9) <= $this->buttonsMaxIdLength
                ) {
                    $idButton = '__dc' . $i . 'DC__' . $option->attributes->DIRECT_CALL;
                }

                $rowButton = [
                    "id" => $idButton,
                    "title" => $option->label
                ];
                if ($isButton) {
                    $buttons[] = [
                        "type" => "reply",
                        "reply" => $rowButton
                    ];
                } else {
                    $rows[] = $rowButton;
                }
            } else {
                $messageTmp .= "\n" . $option->opt_key . ') ' . $option->label;
                if (!$hasUniqueTitles && $this->conf['active_buttons']) {
                    $option->forceNumberedList = true;
                }
            }
        }
        if (count($buttons) > 0 || count($rows) > 0) {
            if (count($buttons) > 0) {
                $output = $this->makeButtons($title, $buttons);
            } else if (count($rows) > 0) {
                $clickMessage = $this->langManager->translate('click_to_choose');
                $chooseMessage = $this->langManager->translate('choose_an_option');
                $output = $this->makeButtonsList($title, $clickMessage, $chooseMessage, $rows);
            }
        } else {
            $output = ["text" => $messageTmp];
        }
        $this->session->set('options', $options);
        $this->session->set('lastUserQuestion', $lastUserQuestion);

        return $output;
    }

    /**
     * Validate if has unique titles
     * if titles are repeated then it will show a numbered list instead of buttons
     * @param array $options
     * @param bool $isButton
     * @param string $title
     * @return bool
     */
    protected function hasUniqueTitles(array $options, bool $isButton, string $title)
    {
        if ($this->conf['active_buttons']) {
            $titles = [];
            $maxLength = $isButton ? $this->buttonsMaxLength : $this->buttonsListMaxLength;
            foreach ($options as $option) {
                $titleTmp = substr($option->$title, 0, $maxLength);
                if (isset($titles[$titleTmp])) {
                    return false;
                }
                $titles[$titleTmp] = true;
            }
        }
        return true;
    }

    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        return $this->digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, true);
    }


    protected function digestFromApiExtendedContentsAnswer($message, $lastUserQuestion)
    {
        $output = [
            "text" => Helper::formatFinalMessage("_" . $message->message . "_"),
        ];

        $messageTitle = [];
        $messageExtended = [];
        $hasUrl = false;

        foreach ($message->subAnswers as $index => $subAnswer) {

            $messageTitle[$index] = $subAnswer->message;

            if (!isset($messageExtended[$index])) $messageExtended[$index] = [];

            if (isset($subAnswer->parameters) && isset($subAnswer->parameters->contents)) {
                if (isset($subAnswer->parameters->contents->url)) {
                    $messageExtended[$index][] = " (" . $subAnswer->parameters->contents->url->value . ")\n";
                    $hasUrl = true;
                }
            }
        }

        $messageTmp = "";
        if ($hasUrl) {
            foreach ($messageTitle as $index => $mt) {
                $messageTmp .= "\n\n" . $mt;
                foreach ($messageExtended[$index] as $key => $me) {
                    $messageTmp .= ($key == 0 ? "\n\n" : "") . $me;
                }
            }
        } else {
            if (count($messageTitle) == 1) {
                $tmp = $this->digestFromApiAnswer($message->subAnswers[0], $lastUserQuestion);
                $messageTmp = "\n\n" . $tmp["text"];
            } else if (count($messageTitle) > 1) {
                $messageTmp = "\n";
                foreach ($messageTitle as $index => $mt) {
                    $messageTmp .= "\n" . ($index + 1) . ") " . $mt;
                }
                $this->session->set('federatedSubanswers', $message->subAnswers);
            }
        }
        $output["text"] .= Helper::formatFinalMessage($messageTmp);
        return $output;
    }


    /********************** MISC **********************/

    /**
     * Create the content for ratings
     */
    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {
        $message = $this->langManager->translate('rate_content_intro');
        $buttons = [];
        foreach ($ratingOptions as $option) {
            $buttons[] = [
                "type" => "reply",
                "reply" => [
                    "id" => $this->langManager->translate($option['label']),
                    "title" => $this->langManager->translate($option['label'])
                ]
            ];
        }
        $output = $this->makeButtons($message, $buttons);
        return $output;
    }

    /**
     * Validate if the message has action fields
     */
    private function handleMessageWithActionField($message, $lastUserQuestion)
    {
        $output = [];
        if (isset($message->actionField) && !empty($message->actionField)) {
            if ($message->actionField->fieldType === 'list') {
                $messageListValues = $this->handleMessageWithListValues($message->message, $message->actionField->listValues, $lastUserQuestion);
                if (is_array($messageListValues)) {
                    $output[0] = $messageListValues;
                } else {
                    $output[0] = [
                        'text' => $message->message . " (" . $this->langManager->translate('type_a_number') . ")" . $messageListValues
                    ];
                }
            } else if ($message->actionField->fieldType === 'datePicker') {
                $output[0]['text'] = strip_tags($message->message . " (" . $this->langManager->translate('date_format') . ")");
            }
        }
        return $output;
    }

    /**
     * Validate if the message has related content and put like an option list
     */
    private function handleMessageWithRelatedContent($message, $lastUserQuestion)
    {
        $output = [];
        if (isset($message->parameters->contents->related->relatedContents) && !empty($message->parameters->contents->related->relatedContents)) {
            $options = [];
            $buttons = [];
            $optionList = "";
            $hasUniqueTitles = $this->hasUniqueTitles($message->parameters->contents->related->relatedContents, true, 'title');
            foreach ($message->parameters->contents->related->relatedContents as $key => $relatedContent) {
                $options[$key] = (object) [
                    'related_content' => true,
                    'label' => $relatedContent->title,
                    'opt_key' => $key + 1
                ];
                if ($this->conf['active_buttons'] && $hasUniqueTitles) {
                    if ($key == 3) break;
                    $buttons[] = [
                        "type" => "reply",
                        "reply" => [
                            "id" => (string) $relatedContent->id,
                            "title" => $relatedContent->title
                        ]
                    ];
                } else {
                    $optionList .= "\n\n" . ($key + 1) . ') ' . $relatedContent->title;
                    if (!$hasUniqueTitles && $this->conf['active_buttons']) {
                        $options[$key]->forceNumberedList = true;
                    }
                }
            }
            if (count($options) > 0) {
                $title = $message->parameters->contents->related->relatedTitle;
                if (count($buttons) > 0) {
                    $output = $this->makeButtons($title, $buttons);
                } else {
                    $output = ['text' => $title . $optionList];
                }
                $this->session->set('hasRelatedContent', true);
                $this->session->set('options', (object) $options);
                $this->session->set('lastUserQuestion', $lastUserQuestion);
            }
        }
        return $output;
    }

    /**
     *  Replaced with handleMessageWithImagesNew()
     *	Splits a message that contains an <img> tag into text/image/text and displays them in 360
     */
    protected function handleMessageWithImages($message)
    {
        return [];
    }

    /**
     * Split the messages into Whatsapp readible messages
     * @param string $messageTxt
     * @param array $output = []
     * @return array $output
     */
    public function splitMessagesInElements(string $messageTxt, array $output = [])
    {
        if ($messageTxt !== "") {
            $validTag = $this->findValidTag($messageTxt);
            if (count($validTag) > 0) {
                $output = $this->tagAnalyzer($validTag["position"], $validTag["closingTag"], $messageTxt, $output);
            } else {
                $output[] = [
                    "text" => $messageTxt
                ];
            }
        }
        return $output;
    }

    /**
     * Find the first position of a valid tag to show it in a correct order
     * @param string $messageTxt
     * @return array
     */
    protected function findValidTag(string $messageTxt)
    {
        $aPos = strpos($messageTxt, '<a ') !== false ? strpos($messageTxt, '<a ') : -1;
        $imgPos = strpos($messageTxt, '<img ') !== false ? strpos($messageTxt, '<img ') : -1;
        $iframePos = strpos($messageTxt, '<iframe ') !== false ? strpos($messageTxt, '<iframe ') : -1;

        $compare = [];
        if ($aPos >= 0) {
            $compare[] = $aPos;
        }
        if ($imgPos >= 0) {
            $compare[] = $imgPos;
        }
        if ($iframePos >= 0) {
            $compare[] = $iframePos;
        }
        if (count($compare) > 0) {
            $firstApparition = min($compare);

            if ($firstApparition == $aPos) {
                return [
                    "closingTag" => "</a>",
                    "position" => $aPos
                ];
            } else if ($firstApparition == $imgPos) {
                return [
                    "closingTag" => ">",
                    "position" => $imgPos
                ];
            } else if ($firstApparition == $iframePos) {
                return [
                    "closingTag" => "</iframe>",
                    "position" => $iframePos
                ];
            }
        }
        return [];
    }

    /**
     * Check the tag to validate if can extract any file
     * @param int $position
     * @param string $closingTag
     * @param string $messageTxt
     * @param array $output
     * @return array $output
     */
    protected function tagAnalyzer(int $position, string $closingTag, string $messageTxt, array $output)
    {
        $tagLength = strlen($closingTag);
        $messagePreTag = $position == 0 ? "" : substr($messageTxt, 0, $position);
        $messageWithTag = substr($messageTxt, $position, (strpos($messageTxt, $closingTag) + $tagLength) - strlen($messagePreTag));
        $messagePostTag = substr($messageTxt, strpos($messageTxt, $closingTag) + $tagLength);

        if ($closingTag === '</a>') $messageProcessed = $this->handleMessageWithLinks($messageWithTag);
        else if ($closingTag === '</iframe>') $messageProcessed = $this->handleMessageWithIframe($messageWithTag);
        else $messageProcessed = $this->handleMessageWithImagesNew($messageWithTag);

        if (!is_array($messageProcessed)) {
            if ($messagePreTag !== "" && $messagePreTag !== "\n\n" && $messagePreTag !== "\n\n ") {
                $messagePreTag .= " ";
            }
            $messageTmp = $messagePreTag === "" ? $messageProcessed : $messagePreTag . $messageProcessed;
            $output[] = [
                "text" => $messageTmp
            ];
        } else {
            if ($messagePreTag !== "" && $messagePreTag !== "\n\n" && $messagePreTag !== "\n\n ") {
                $output[] = [
                    "text" => $messagePreTag
                ];
            }
            $output[] = $messageProcessed;
        }
        if ($messagePostTag !== "" && $messagePostTag !== " " && $messagePostTag !== "\n\n" && $messagePostTag !== "\n\n ") {
            $output = $this->splitMessagesInElements($messagePostTag, $output);
        }
        return $output;
    }

    /**
     * Extract links from text in order to display as a Whatsapp valid format
     * if link is a valid file then attach to Whatsapp
     * @param string $messageWithLink
     */
    protected function handleMessageWithLinks(string $messageWithLink)
    {
        $response = $messageWithLink;
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($messageWithLink, 'HTML-ENTITIES', 'UTF-8'));
        @$xpath = new \DOMXpath($dom);
        $elements = $xpath->query('//a | //img');

        if (!is_null($elements)) {
            $url = '';
            $value = '';
            $image = '';
            foreach ($elements as $element) {
                if ($element->nodeName === 'a') {
                    $url = $element->getAttribute('href');
                    $value = trim($element->nodeValue);
                } else if ($element->nodeName === 'img') {
                    $image = '<img src="' . $element->getAttribute('src') . '">';
                }
            }
            if ($url !== '') {
                if ($image !== '') {
                    $response = $this->handleMessageWithImagesNew($image, $url);
                } else {
                    $response = $this->attachFileOrText($url, $value);
                }
            }
        }
        return $response;
    }

    /**
     * Handle message with image
     * @param string $messageWithImage
     * @param string $caption = null
     * @return array|string
     */
    protected function handleMessageWithImagesNew(string $messageWithImage, string $caption = null)
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($messageWithImage, 'HTML-ENTITIES', 'UTF-8'));
        $nodes = $dom->getElementsByTagName('img');
        if (isset($nodes[0])) {
            $url = $nodes[0]->getAttribute('src');
            if ($url) {
                return $this->attachFileOrText($url, $caption);
            }
        }
        return $messageWithImage;
    }

    /**
     * Handle the message from iFrame
     * @param string $messageWithIframe
     * @return array|string
     */
    protected function handleMessageWithIframe(string $messageWithIframe)
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($messageWithIframe, 'HTML-ENTITIES', 'UTF-8'));
        $nodes = $dom->getElementsByTagName('iframe');
        if (isset($nodes[0])) {
            $url = $nodes[0]->getAttribute('src');
            if ($url) {
                return $this->attachFileOrText($url);
            }
        }
        return $messageWithIframe;
    }

    /**
     * If there is an attachable file, includes it in the response
     * @param string $url
     * @param string $value = null
     * @param bool $ignoreTextResponse = false
     * @return array|string
     */
    protected function attachFileOrText(string $url, string $value = null)
    {
        $urlElements = explode(".", $url);
        if (count($urlElements) > 1) {
            $fileFormat = $urlElements[count($urlElements) - 1];
            foreach ($this->attachableFormats as $type => $formats) {
                if (in_array($fileFormat, $formats)) {
                    $tmp = ["link" => $url];
                    if (!is_null($value)) {
                        $tmp["caption"] = $value;
                    }
                    return [$type => [$tmp]];
                }
            }
        }
        return is_null($value) ? $url : $value . " (" . $url . ")";
    }

    /**
     * Group text messages from array if they are adjacent
     * @param array $output
     * @return array $output
     */
    protected function groupTextMessages(array $output)
    {
        $newOutput = [];
        $countElements = 0;
        $skipNextElement = false;
        foreach ($output as $index => $element) {
            if ($skipNextElement) {
                $skipNextElement = false;
                continue;
            }
            if (isset($element['text']) && isset($output[$index + 1]) && isset($output[$index + 1]['text'])) {
                //If next element is text, insert next in current
                $element['text'] = trim($element['text']) . ' ' . $output[$index + 1]['text'];
                $newOutput[$countElements] = $element;
                $skipNextElement = true;
                $countElements++;
            } else if (isset($element['text']) && !isset($output[$index + 1]) && isset($newOutput[$countElements - 1]['text'])) {
                //Previous element is text and current is the last, insert in the previous
                $newOutput[$countElements - 1]['text'] .= $element['text'];
            } else {
                $newOutput[$countElements] = $element;
                $countElements++;
            }
        }
        return $newOutput;
    }

    /**
     * Set the options for message with list values
     */
    protected function handleMessageWithListValues($messageTxt, $listValues, $lastUserQuestion)
    {
        $options = $listValues->values;
        $optionList = "";
        $buttons = [];
        $rows = [];
        $isButton = count($options) <= 3 ? true : false;
        $hasUniqueTitles = $this->hasUniqueTitles($options, $isButton, 'option');

        foreach ($options as $i => &$option) {
            $option->opt_key = $i + 1;
            $option->list_values = true;
            $option->label = $option->option;

            if ($this->conf['active_buttons'] && $hasUniqueTitles) {
                if ($i == 10) break;
                $rowButton = [
                    "id" => "list_values_" . $option->opt_key,
                    "title" => $option->label
                ];
                if ($isButton) {
                    $buttons[] = [
                        "type" => "reply",
                        "reply" => $rowButton
                    ];
                } else {
                    $rows[] = $rowButton;
                }
            } else {
                $optionList .= "\n" . $option->opt_key . ') ' . $option->label;
                if (!$hasUniqueTitles && $this->conf['active_buttons']) {
                    $option->forceNumberedList = true;
                }
            }
        }
        if (count($buttons) > 0 || count($rows) > 0) {
            $output = [];
            if (count($buttons) > 0) {
                $output = $this->makeButtons($messageTxt, $buttons);
            } else if (count($rows) > 0) {
                $clickMessage = $this->langManager->translate('click_to_choose');
                $chooseMessage = $this->langManager->translate('choose_an_option');
                $output = $this->makeButtonsList($messageTxt, $clickMessage, $chooseMessage, $rows);
            }
        } else {
            $output = $optionList;
        }

        if (count($buttons) > 0 || count($rows) > 0 || $optionList !== '') {
            $this->session->set('options', $options);
            $this->session->set('lastUserQuestion', $lastUserQuestion);
        }
        return $output;
    }

    /**
     *	Disabled for 360
     */
    protected function buildUrlButtonMessage($message, $urlButton)
    {
        $output = [];
        return $output;
    }

    /**
     * Build the message and options to escalate
     * @return array
     */
    public function buildEscalationMessage()
    {
        $message = $this->langManager->translate('ask_to_escalate');
        $escalateOptions = [];
        $buttons = [];
        $options = ['yes', 'no'];
        foreach ($options as $index => $option) {
            $buttons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => 'escalation_' . ($index + 1),
                    'title' => $this->langManager->translate($option)
                ]
            ];
            $escalateOptions[] = (object) [
                'label' => $this->langManager->translate($option),
                'escalate' => $option == 'yes',
                'opt_key' =>  $index + 1
            ];
        }
        $this->session->set('options', (object) $escalateOptions);
        $output = $this->makeButtons($message, $buttons);
        return $output;
    }

    /**
     * Check if Hyperchat is running and if the attached file is correct
     * @param object $request
     * @return array $output
     */
    protected function mediaFileToHyperchat(object $request)
    {
        $output = [];
        if ($this->session->get('chatOnGoing', false)) {
            $mediaId = "";
            $caption = "";
            if (isset($request->image)) {
                $mediaId = isset($request->image->id) ? $request->image->id : "";
                $caption = isset($request->image->caption) ? $request->image->caption : "";
            } else if (isset($request->document)) {
                $mediaId = isset($request->document->id) ? $request->document->id : "";
                $caption = isset($request->document->caption) ? $request->document->caption : "";
            } else if (isset($request->video)) {
                $mediaId = isset($request->video->id) ? $request->video->id : "";
                $caption = isset($request->video->caption) ? $request->video->caption : "";
            } else if (isset($request->audio)) {
                $mediaId = isset($request->audio->id) ? $request->audio->id : "";
                $caption = isset($request->audio->caption) ? $request->audio->caption : "";
            } else if (isset($request->voice)) {
                $mediaId = isset($request->voice->id) ? $request->voice->id : "";
                $caption = isset($request->voice->caption) ? $request->voice->caption : "";
            }
            if ($mediaId === "") {
                $output[] = ['message' => $this->langManager->translate('user_send_no_valid_file')];
                $this->externalClient->sendTextMessage('_' . $this->langManager->translate('invalid_file') . '_');
            } else {
                $mediaFile = $this->getMediaFile($mediaId);
                if ($mediaFile !== "") {
                    unset($output[0]);
                    if ($caption !== "") {
                        $output[] = ['message' => $caption];
                    }
                    $output[] = ['media' => $mediaFile];
                } else {
                    $output[] = ['message' => '(' . $this->langManager->translate('user_send_no_valid_file') . ')'];
                    $this->externalClient->sendTextMessage('_' . $this->langManager->translate('invalid_file') . '_');
                }
            }
        } else {
            $this->externalClient->sendTextMessage($this->langManager->translate('unable_to_process_file'));
        }
        return $output;
    }

    /**
     * Get the media file from the 360 response, 
     * save file into temporal directory to sent to Hyperchat
     * @param string $mediaId
     */
    protected function getMediaFile(string $mediaId)
    {
        $fileInfo = $this->externalClient->getMediaFrom360($mediaId);
        if ($fileInfo !== "") {
            foreach ($this->attachableFormats as $formats) {
                // If file format contains extra info other than the proper file format, strip it. (example: "ogg; codecs=opus" will be "ogg")
                $fileInfo["format"] = explode(";", $fileInfo["format"], 2)[0];
                if (in_array($fileInfo["format"], $formats)) {
                    $fileName = sys_get_temp_dir() . "/" . $mediaId . "." . $fileInfo["format"];
                    $tmpFile = fopen($fileName, "w") or die;
                    fwrite($tmpFile, $fileInfo["file"]);
                    $fileRaw = fopen($fileName, 'r');
                    @unlink($fileName);

                    return $fileRaw;
                }
            }
        }
        return "";
    }

    /**
     * Structure for buttons (from 1 to 3 elements)
     * @param string $message
     * @param array $buttons
     * @return array
     */
    protected function makeButtons(string $message, array $buttons)
    {
        foreach ($buttons as $index => $button) {
            $buttons[$index]['reply']['title'] = Helper::cleanButtonTitle($button['reply']['title']);
            if (strlen($buttons[$index]['reply']['title']) > $this->buttonsMaxLength) {
                $buttons[$index]['reply']['title'] = substr($buttons[$index]['reply']['title'], 0, $this->buttonsMaxLength - 3) . '...';
            }
        }
        return [
            "interactive" => [
                "type" => "button",
                "body" => [
                    "text" => trim($message)
                ],
                "action" => [
                    "buttons" => $buttons
                ]
            ]
        ];
    }

    /**
     * Structure for buttons list (from 4 to 10 elements)
     * @param string $message
     * @param array $buttons
     * @return array
     */
    protected function makeButtonsList(string $message, string $buttonText, string $subtitle, array $buttons)
    {
        foreach ($buttons as $index => $button) {
            $buttons[$index]['title'] = Helper::cleanButtonTitle($button['title']);
            if (strlen($buttons[$index]['title']) > ($this->buttonsListMaxLength)) {
                $buttons[$index]['title'] = substr($buttons[$index]['title'], 0, $this->buttonsListMaxLength - 3) . '...';
            }
        }
        return [
            "interactive" => [
                "type" => "list",
                "body" => [
                    "text" => trim($message)
                ],
                "action" => [
                    "button" => trim($buttonText),
                    "sections" => [
                        [
                            "title" => trim($subtitle),
                            "rows" => $buttons
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Show the message to ask to start the survey
     */
    public function askForSurvey()
    {
        $output = [];
        $surveyElements = $this->session->get('surveyElements');
        if (!is_null($surveyElements)) {
            $this->session->set('surveyAskForAcceptance', true);
            $this->session->set('surveyLaunch', true);
            $this->session->delete('surveyConfirm');
            $message = $this->langManager->translate('ask_for_survey');
            $buttons = [];
            $options = ['yes', 'no'];
            foreach ($options as $option) {
                $buttons[] = [
                    'type' => 'reply',
                    'reply' => [
                        'id' => $this->langManager->translate($option),
                        'title' => $this->langManager->translate($option)
                    ]
                ];
            }
            $output[] = $this->makeButtons($message, $buttons);
        }
        return $output;
    }

    /**
     * Process the survey data
     */
    public function nextSurveyQuestion($surveyElements, $message)
    {
        $response = $this->validateSurveyAnswer($surveyElements, $message);
        if (count($response) > 0) {
            return $response;
        }
        $surveyElements = $this->session->get('surveyElements');

        $previousPage = 0;
        $countResponse = -1;
        $isExpectingValue = false;
        foreach ($surveyElements['questions'] as $index => $question) {
            if (!$question['answered']) {
                if ($previousPage != $question['page'] && $previousPage > 0) {
                    break;
                }
                $countResponse++;
                if ($question['type'] === 'layout') {
                    $surveyElements['questions'][$index]['answered'] = true;
                } else {
                    $this->session->set('surveyExpectedValues', '');
                    $expectedValues = [];
                    $labels = [];
                    $min = null;
                    $max = null;
                    $countSettings = 0;
                    foreach ($question['settings'] as $setting) {
                        if ($countSettings == 0) {
                            $response[$countResponse] = $setting->value;
                        } else {
                            if (is_array($setting->value)) {
                                foreach ($setting->value as $value) {
                                    $expectedValues[] = $value->value;
                                    $labels[] = $value->label;
                                }
                            } else if (is_object($setting->value) && ($setting->subtype === 'min' || $setting->subtype === 'max')) {
                                if ($setting->subtype === 'min') {
                                    $min = $setting->value->value;
                                } else if ($setting->subtype === 'max') {
                                    $max = $setting->value->value;
                                }
                            }
                        }
                        $countSettings++;
                    }
                    if (is_int($min) && is_int($max) && $max > $min) {
                        for ($i = $min; $i <= $max; $i++) {
                            $expectedValues[] = $i;
                            $labels[] = $i;
                        }
                    }
                    if (count($expectedValues) > 0) {
                        $response[$countResponse] .= "\n \n";
                        foreach ($labels as $labelCount => $label) {
                            $response[$countResponse] .= "\n" . ($labelCount + 1) . " - " . $label;
                        }
                        $this->session->set('surveyExpectedLabels', $labels);
                        $this->session->set('surveyExpectedValues', $expectedValues);
                        $isExpectingValue = true;
                    } else if ($question['type'] === 'input' && ($question['subtype'] === 'text' || $question['subtype'] === 'textarea')) { //Expecting a simple text response
                        $isExpectingValue = true;
                    }
                    $this->session->set('surveyPendingElement', $index);
                    $countResponse++;
                    break;
                }
                $previousPage = $question['page'];
            }
        }
        $this->session->set('surveyElements', $surveyElements);

        $pendingAnswers = false;
        foreach ($surveyElements['questions'] as $index => $question) {
            if (!$question['answered']) {
                $pendingAnswers = true;
                break;
            }
        }
        if (!$isExpectingValue) $pendingAnswers = false;

        if (!$pendingAnswers) {
            $response[] = '__MAKE_SUBMIT__';
        }
        return $response;
    }

    /**
     * Validate if the answer for survey is correct
     */
    protected function validateSurveyAnswer($surveyElements, $message)
    {
        $response = [];
        $isSurveyAnswer = true;
        if ($this->session->get('surveyAskForAcceptance', false)) {
            $isSurveyAnswer = false;
            $this->session->delete('surveyAskForAcceptance');
            $this->session->set('surveyWrongAnswers', 0);
            if (Helper::removeAccentsToLower($this->langManager->translate('yes')) !== Helper::removeAccentsToLower($message)) {
                $this->session->delete('launchSurvey');
                $this->session->delete('surveyElements');
                $response[] = $this->langManager->translate('thanks');
                $response[] = '__SURVEY_NOT_ACCEPTED__';
            }
        } else if ($this->session->get('surveyAskForContinue', false)) {
            $isSurveyAnswer = false;
            $this->session->delete('surveyAskForContinue');
            $this->session->set('surveyWrongAnswers', 0);
            if (Helper::removeAccentsToLower($this->langManager->translate('yes')) !== Helper::removeAccentsToLower($message)) {
                $response[] = $this->langManager->translate('thanks');
                $response[] = '__END_SURVEY__';
            }
        }
        if ($isSurveyAnswer) {
            $pendingElement = $this->session->get('surveyPendingElement', -1);
            if ($pendingElement >= 0) {
                $surveyExpectedValues = $this->session->get('surveyExpectedValues', '');
                $surveyExpectedLabels = $this->session->get('surveyExpectedLabels', '');
                $correctAnswer = true;
                if (is_array($surveyExpectedValues) && is_array($surveyExpectedLabels)) {
                    $selected = false;
                    foreach ($surveyExpectedLabels as $index => $expected) {
                        if ($message == ($index + 1) || Helper::removeAccentsToLower($message) === Helper::removeAccentsToLower($expected)) {
                            $selected = true;
                            $message = $surveyExpectedValues[$index];
                            break;
                        }
                    }
                    if (!$selected) {
                        $this->session->set('surveyWrongAnswers', $this->session->get('surveyWrongAnswers', 0) + 1);
                        $correctAnswer = false;
                    }
                }
                if ($correctAnswer) {
                    $surveyElements['questions'][$pendingElement]['answered'] = true;
                    $surveyElements['questions'][$pendingElement]['response'] = $message;
                    $surveyElements = $this->reviewSurveyPageNavigation($surveyElements, $message);
                    $this->session->set('surveyElements', $surveyElements);
                    $this->session->set('surveyWrongAnswers', 0);
                }
            }
            if ($this->session->get('surveyWrongAnswers', 0) > 1) {
                $textConfirmation = $this->langManager->translate('ask_to_continue_survey');
                $buttons = [];
                $options = ['yes', 'no'];
                foreach ($options as $index => $option) {
                    $buttons[] = [
                        'type' => 'reply',
                        'reply' => [
                            'id' => $this->langManager->translate($option),
                            'title' => $this->langManager->translate($option)
                        ]
                    ];
                }
                $response[] = $this->makeButtons($textConfirmation, $buttons);
                $this->session->set('surveyAskForContinue', true);
            }
        }
        return $response;
    }

    /**
     * Check if there is a page navigation
     */
    protected function reviewSurveyPageNavigation($surveyElements, $message)
    {
        if (count($surveyElements['navigatePage']) > 0) {
            $pageSelected = '';
            foreach ($surveyElements['navigatePage'] as $page) {
                if ($page->value === $message) {
                    $pageSelected = $page->page;
                    break;
                }
            }
            if ($pageSelected !== '') {
                foreach ($surveyElements['questions'] as $index => $question) {
                    $markAsAnswered = true;
                    if ($question['page'] == $pageSelected) {
                        $markAsAnswered = false;
                    }
                    if ($markAsAnswered) {
                        $surveyElements['questions'][$index]['answered'] = true;
                    }
                }
            }
        }
        return $surveyElements;
    }
}
