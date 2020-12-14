## Introduction
This connector is developed for Inbenta’s customers to be able to have their users chat with the chatbot through WhatsApp as a channel. 360 Dialog is a verified WhatsApp Business Solution (BSP) and provides direct access to WhatsApp Business API.

## Features
Following are the features supported by this connector:

* FAQ Intents with images, videos and Hyperlinks as external links.
* Related Contents.
* Sidebubble Text (Will be appended to the Answer Text when displayed)
* Multiple options with a limit of 3 elements (Buttons are not supported. But, user can choose by entering a number as a position in the list or natural language matching).
* Polar Questions (Buttons are not supported. But, user can choose by entering a number as a position in the list or natural language matching).
* Dialogs(Buttons are not supported. But, use can choose by entering a number as a position in the list or natural language matching).
* Forms, Actions & Variables (with text transformations where applicable).
* Hyperchat escalation after X no-results answers (configuration in chat.php).
* Hyperchat escalation from any content.
* Translation labels (can be set up in ‘Extra Info’ section of backstage).

>**NOTE:** WhatsApp doesn't allow displaying buttons, so **Multiple options**, **Polar questions**, **Chained answers**, **ratings** and **escalation question** will be asked without buttons, the options will be numbered and user will enter the number of their choice.

## Configuration
### Inbenta Instance
**Create translations object in ExtraInfo (optional)**

You can manage the translation labels from Extra Info. Here are the steps to create the translations object:

1. In your Backstage instance, go to Knowledge → Extra Info and click on 'Manage groups and types' → 360dialog→ Add type. Name it '**translations**' and add a new property with type 'Multiple' named with your chatbot's language label (en, es, it...).
2. Inside the language object, add all the labels that you want to override. Each label should be a 'text' type entry (you can find the labels list below).
3. Save your translations object.

Now you can create the ExtraInfo object by clicking the **New entry** button, selecting the 'translations' type and naming it as 'translations'. Then, fill each label with your desired translation and remember to publish ExtraInfo by clicking the **Post** button.

Here you have the current labels with their English value:
* **agent_joined** => 'Agent $agentName has joined the conversation.',
* **api_timeout** => 'Please, reformulate your question.',
* **ask_rating_comment** => 'Please tell us why',
* **ask_to_escalate** => 'Do you want to start a chat with a human agent?',
* **chat_closed** => 'Chat closed',
* **creating_chat** => 'I will try to connect you with an agent. Please wait.',
* **error_creating_chat** => 'There was an error joining the chat',
* **escalation_rejected** => 'What else can I do for you?',
* **no** => 'No',
* **no_agents** => 'No agents available',
* **queue_estimation_first** => 'There is one person ahead of you.',
* **queue_estimation** => 'There are $queuePosition people ahead of you.',
* **rate_content_intro** => 'Was this answer helpful?',
* **thanks** => 'Thanks!',
* **yes** => 'Yes',
* **close_chat_key_word** => '/close',
* **out_of_time** => '_There are no agents connected_',
* **queue_warning** => 'Wait until an agent is connected before making a question.',
* **no_rating_given** => 'We understand you do not want to rate.'

>**Note**
Remember to publish your ExtraInfo changes by clicking the ‘Post’ button.
Even if you have created the ExtraInfo translation, it is mandatory that the lang file exists in the “lang” folder.

**HyperChat integration (optional)**

If you want to use Hyperchat you must subscribe your UI to the Hyperchat events. Open your Messenger instance. Go to Messenger → Settings → Chat → Webhooks. Here, in the ‘Events’ column type “queues:update,invitations:new,invitations:accept,forever:alone,chats:close, messages:new,users:activity”. In the ‘Target’ column paste your UI’s URL, then click on the ‘+’ button on the right.


# 360 Dialog 
### WhatsApp Business Account
Before a business can access the WhatsApp Business API, each client has to go through an approval procedure.
The following information is required for opening a WhatsApp Business Account (WABA):

* Facebook Manager ID
* Phone number with the ability to receive either voice or SMS
* Business Name & Display Name
* Company logo (square JPG, min 300x300 px)
* Company description (max. 130 characters)
* Postal address of the company where utility bills go to
* Contact email where customers can reach the business
* URL (end-point) to which incoming WhatsApp messages will be forwarded (typically provided by a 360dialog partner)

You can find more information on their page - https://docs.360dialog.com/api/onboarding-guide-summary

Additional Documentation - [360 Dialog Documentation](https://docs.360dialog.com)

### Sandbox Environment

The Sandbox environment can be instanced without a Whatsapp Business Account. You can start testing by sending a Whatsapp message to ```491606232334``` (Note: current number at the time of this documentation. Could be different now. Please check the 360 dialog documentation.) with the word ```START```, the response for this request is the sandbox ```API KEY```. The next step is to set the Webhook.

For sandbox, there is no limit of messages, but only text-based messages can be sent/received (media files are not supported). More information: https://docs.360dialog.com/api/whatsapp-api/sandbox

### Add the Api Key and URLs to Chatbot 360 Dialog Template

In **conf/custom/360.php** file, you have to add 4 values:

* **api_key:** Provided by 360 Dialog or by the Sandbox response (check previous step).
* **url_messages:** The URL where the messages to Whatsapp are delivered. Possible values, for production: https://waba.360dialog.io/v1/messages, for sandbox: https://waba-sandbox.messagepipe.io/v1/messages
* **url_subscribe:** URL used to set your webhook. Possible values, for production: https://waba.messagepipe.io/v1/configs/webhook, for sandbox: https://waba-sandbox.messagepipe.io/v1/configs/webhook
* **url_webhook:** This is the URL where your app is hosted. Is used to link your Whatsapp phone number to your app server.
```php
return [
    'api_key' => '',
    'url_messages' => '',
    'url_subscribe' => '',
    'url_webhook' => ''
];
```

### Setting the Webhook

Whatsapp needs a defined webhook to interact with the Inbenta Chatbot. This webhook is the URL of the Inbenta Chatbot 360 dialog connector application (```url_of_your_deployed_app```). You need to execute (from Postman or from a web browser), using GET method.

[```url_of_your_deployed_app```]?subscribe=1

Before execute the previous instruction, you need to validate that **conf/custom/360.php** file has the ```api_key```, ```url_subscribe``` and ```url_webhook``` properly filled.

More information at: https://docs.360dialog.com/api/whatsapp-api/webhook

### Setup Inbenta Chatbot 360 Dialog Connector Code

**Required Configuration**

It's pretty simple to get this UI working. The mandatory configuration files are included by default in ```/conf/custom``` to be filled in, so you have to provide the information required in these files:

* **File 'api.php'** Provide the API Key and API Secret of your Chatbot Instance.
* **File 'environments.php'** Here you can define regexes to detect ```development``` and ```preproduction``` environments. If the regexes do not match the current conditions or there isn't any regex configured, ```production``` environment will be assumed.
* **File ‘360.php’** Instructions mentioned in the previous section.

**Optional Configuration**

Some of the optional features that can be enabled from the configuration files too. Every optional configuration file should be copied from **/conf/default** to **/conf/custom**. The bot will detect the customization and it will load the right version. Here is a list of those optional configuration files:

**HYPERCHAT (chat.php)**

* **chat**
    * **enabled:** Enable or disable HyperChat (“**true**” or “**false**”).
    * **version:** HyperChat version. The default and latest one is 1.
    * **appId:** The ID of the HyperChat app. This defines the instance in which the chat opens. You can find it in your instance → Messenger → Settings → Chat.
    * **secret:** Your HyperChat instance application secret. You can find it in your instance → Messenger → Settings → Chat.
    * **roomId:** The room where the chat opens. This is mapped directly to a Backstage queue ID. Numeric value, not a string. You can find your rooms list it in your instance → Messenger → Settings → Queues.
    * **lang:** Language code (in ISO 639-1 format) for the current chat. This is used when the engine checks if there are agents available for this language to assign the chat to one of them.
    * **source:** Source id from the sources in your instance. Numeric value, not a string. The default value is **3**. You can find your sources list it in your instance → Messenger → Settings → Sources.
    * **regionServer:** The geographical region where the HyperChat app lives.
    * **server:** The Hyperchat server URL assigned to your instance. Ask your Inbenta contact for this configuration parameter.
    * **server_port:** The port where to communicate with the Hyperchat server. It’s defined in your instance → Messenger → Settings → Chat -->Port
    * **queue:**
        * **active:** Enable or disable the queue system (“**true**” or “**false**”). It **MUST** be enabled in your instance too (Messenger → Settings → Chat → Queue mode).
* **triesBeforeEscalation:** Number of no-result answers in a row after the bot should escalate to an agent (if available). Numeric value, not a string. Zero means it’s disabled.
* **negativeRatingsBeforeEscalation:** Number of negative content ratings in a row after the bot should escalate to an agent (if available). Numeric value, not a string. Zero means it’s disabled.
* **messenger:** Setting that allow replying to tickets after the agent conversation is closed.
    * **auht_url:** Url for authorization, used by API Messenger.
    * **key:** API Key of the Messenger Instance. You can find it in Administration → API → [Production | Development].
    * **secret:** Secret Key token of the Messenger Instance. You can find it in Administration → API → [Production | Development].
    * **webhook_secret:** Secret token, defined when the configuration of [External Ticket Source](https://help.inbenta.com/en/configuring-an-external-tickets-source/) is made.

**CONVERSATION (conversation.php)**

* **default:** Contains the API conversation configuration. The values are described below:
    * **answers:**
        * **sideBubbleAttributes:** Dynamic settings to show side-bubble content. Because there is no side-bubble in 360 Dialog the content is shown after the main answer.
        * **answerAttributes:** Dynamic settings to show as the bot answer. The default is [ "ANSWER_TEXT" ]. Setting multiple dynamic settings generates a bot answer with concatenated values with a newline character (\n).
        * **maxOptions:** Maximum number of options returned in a multiple-choice answer.
    * **forms:**
        * **allowUserToAbandonForm:** Whether or not a user is allowed to abandon the form after a number of consecutive failed answers. The default value is **true**.
        * **errorRetries:** The number of times a user can fail a form field before being asked if he wants to leave the form. The default value is 3.
    * **lang:** Language of the bot, represented by its ISO 639-1 code. Accepted values: ca, de, en, es, fr, it, ja, ko, nl, pt, zh, ru, ar, hu, eu, ro, gl, da, sv, no, tr, cs, fi, pl, el, th, id, uk
* **user_type:** Profile identifier from the Backstage knowledge base. Minimum:0. Default:0. You can find your profile list in your Chatbot Instance → Settings → User Types.
* **source:** Source identifier (e.g. “360dialog”) used to filter the logs in the dashboards.
* **content_ratings:**
    * **enabled:** Enable or disable the rating feature (“***true***” or “***false***”).
    * **ratings:** Array of options to display in order to rate the content. Every option has the following parameters:
        * **id:** Id of your content rating. You can find your content ratings in your Chatbot instance → Settings → Ratings. Remember that your rating type should be "**content**".
        * **label:** Key of the label translation to display within the rating option button. The available labels can be configured from **/lang/**. Also can be modified from Backstage as described in section **Create translations object in ExtraInfo (optional)**.
        * **comment:** If **true**, asks for a comment for the rating. It's useful when a user rates a content negatively in order to ask why the negative rating.
        * **isNegative:** If **true**, the bot will increment the negative-comments counter in order to escalate with an agent (if HyperChat **negativeRatingsBeforeEscalation** is configured).

**ENVIRONMENTS (environments.php)**

This file allows configuring a rule to detect the current environment for the connector. It can check the current **http_host** or the **script_name** in order to detect the environment.

* **development:**
    * **type:** Detection type: check the **http_host** (e.g. _www.example.com_) or the **script_name** (e.g. _/path/to/the/connector/server.php_).
    * **regex:** Regex to match with the detection type (e.g. “_/^dev.mydomain.com$/m_“ will set the “development” environment when the detection type is _dev.example.com_).
* **preproduction:**
    * **type:** Detection type: check the **http_host** (e.g. _www.example.com_) or the **script_name** (e.g. _/path/to/the/connector/server.php_). /path/to/the/connector/server.php).
    * **regex:** Regex to match with the detection type (e.g. “_/^.*/staging/.*$/m_“ will set the “preproduction” environment when the detection type is "_/staging/_").
    
**Deployment**

The 360 Dialog template must be served by a public web server in order to allow 360 Dialog to send the events to it. The environment where the template has been developed and tested has the following specifications

* Apache 2.4
* PHP 7.3
* PHP Curl extension
* Non-CPU-bound
* The latest version of [Composer](https://getcomposer.org/) (Dependency Manager for PHP) to install all dependencies that Inbenta requires for the integration.
* If the client has a distributed infrastructure, this means that multiple servers can manage the user session, they must adapt their SessionHandler so that the entire session is shared among all its servers.

### Troubleshooting

**Missing HyperChat messages**

Check if your UI is subscribed to Hyperchat webhooks as described **HyperChat integration (optional)**. Also, check if the Hyperchat settings in the UI are valid in **conf/custom/chat.php**.

**The bot is not answering (webhook configuration)**

You can check the current webhook URL in your Whatsapp configuration. Also, check if the tokens in **conf/custom/api.php** (key and secret) are valid.

**The bot is not answering (application cache)**

If the previous tip doesn't work, maybe your application is caching older token values from extraInfo and you should delete the cached session files in your server. These files are stored in the configured system temporary path returned by the PHP function sys_get_temp_dir(). Usually, it’s “/tmp” or “/var/tmp” but may vary depending on your server system and configuration. When you locate the directory, remove all the files named like “cached-accesstoken-XXXX” and “cached-appdata-XXX”.