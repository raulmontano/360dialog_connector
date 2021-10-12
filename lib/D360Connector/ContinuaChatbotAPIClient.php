<?php

namespace Inbenta\D360Connector;

use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;

class ContinuaChatbotAPIClient extends ChatbotAPIClient
{

	public function startConversation($conf = array(), $userType = 0, $environment = 'development', $source = null)
  {

		   $return = parent::startConversation($conf,$userType,$environment,$source);

  		 if($return){
  		     $this->session->set('conversationStarted', TRUE);
  		 }

		    return $return;

    }

}
