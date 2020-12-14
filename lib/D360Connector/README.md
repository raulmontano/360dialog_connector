### OBJECTIVE

This 360 Dialog connector extends from the [Chatbot API Connector](https://github.com/inbenta-integrations/chatbot_api_connector) library. This library includes a 360 Dialog API Client in order to send messages to WhatsApp users through our WhatsApp phone number. It translates WhatsApp messages into the Inbenta Chatbot API format and vice versa. Also, it implements some methods from the base HyperChat client in order to communicate with WhatsApp when the user is chatting.

### FUNCTIONALITIES
This connector inherits the functionalities from the `ChatbotConnector` library. Currently, the features provided by this application are:

* Simple answers
* Multiple options
* Polar questions
* Chained answers
* Escalate to HyperChat after a number of no-results answers
* Escalate to HyperChat when matching with an 'Escalation FAQ'
* Send information to webhook through forms

### HOW TO CUSTOMIZE

**Custom Behaviors**

If you need to customize the bot flow, you need to modify the class `D360Connector.php`. This class extends from the ChatbotConnector and here you can override all the parent methods.


### STRUCTURE

The `D360Connector` folder has some classes needed to use the ChatbotConnector with 360 Dialog. These classes are used in the D360Connector constructor in order to provide the application with the components needed to send information to 360 Dialog and to parse messages between WhatsApp, ChatbotAPI and HyperChat.


**External Digester folder**

This folder contains the class D360Digester. This class is a kind of "translator" between the Chatbot API and WhatsApp. Mainly, the work performed by this class is to convert a message from the Chatbot API into a message accepted by the 360 Dialog API. It also does the inverse work, translating messages from 360 Dialog into the format required by the Chatbot API.


**HyperChat API**

The class `D360HyperChatClient` instantiates a 360 Dialog client from the `external_id` parameter provided by HyperChat. This parameter is generated by the external client and passed to HyperChat when the chat is created. This parameter allows us to instantiate a new 360 Dialog client from WhatsApp phone numbers that is extracted from the external_id:
```php
    //Instances an external client
    protected function instanceExternalClient($externalId, $appConf)
    {
        $userNumber = D360APIClient::getUserNumberFromExternalId($externalId);
        if (is_null($userNumber)) {
            return null;
        }
        $companyNumber = D360APIClient::getCompanyNumberFromExternalId($externalId);
        if (is_null($companyNumber)) {
            return null;
        }
        $externalClient = new D360APIClient($appConf->get('360.api_key'));
        $externalClient->setSenderFromId($companyNumber, $userNumber);
        return $externalClient;
    }
```

This class extends from the class `HyperChatClient` that extends from the default HyperChatClient provided by the product team, and all the parent methods can be overwritten.