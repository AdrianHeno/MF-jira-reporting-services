<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Slackbot extends CI_Controller {

	//Connects to the jira_tools create_meetings endpoint and formats responses for the slackbot
	function create_meetings(){
		if($_GET['token'] !== $this->config->item('artefacts_slack_token')){//If we don't get a valid token from slack, die
			die();
		}
		
		if(!isset($_GET['text']) || strlen($_GET['text']) < 2){ //Check if $_GET['text'] was passed in, if not send error message and die
			$slack_payload = array (
				'text' => 'Please supply a valid project name after the /artefacts'
			);
			
			//Send encode and send the payload
			$this->slack_acknowledgement($slack_payload);
			
			die();
		}else{
			$slack_payload = array (
				'text' => 'Working on that for you now, I will let you know when its done.'
			);
		}
		//Slack only gives us 3 seconds to respond...Nothing happens in Jira in under 3 seconds, so send a responce once validation passes and then use the responce url to notify the user once the operation is complete
		$this->slack_acknowledgement($slack_payload);
		
		//Contact the jira_tools endpoint and get it to create the meetings
		$response = $this->jira_tools_connector($this->config->item('base_url') . '/jira_tools/create_meetings/' . $_GET['text']);
		if(isset($response->success) && $response->success == "true"){//Did it work?
			$slack_payload = array (
				'text' => $response->result
			);
		}else{
			$slack_payload = array (
				'text' => 'Sorry, the meeting creation request failed.'
			);
		}

		//Send encode and send the payload back to the users slack client
		$this->post_to_slack($_GET['response_url'], $slack_payload);
	}
	
	//Used for cURLing the jira tools endpoint
	private function jira_tools_connector($url){
		set_time_limit(600);
		//Call the atlassian graph data API
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_VERBOSE, true);
		$verbose = fopen('php://temp', 'rw+');
		curl_setopt($curl, CURLOPT_STDERR, $verbose);
		
		$data = (curl_exec($curl));
		return json_decode($data);
	}
	
	/*
	 *This function handles the immediate responses to slack
	 */
	private function slack_acknowledgement($slack_payload){
		ob_start();
		header($_SERVER["SERVER_PROTOCOL"] . " 202 Accepted");
		header("Status: 202 Accepted");
		header("Content-Type: application/json");
		echo json_encode($slack_payload);//Send a payload back to slack so that the user knows that we are working on their request
		header('Content-Length: '.ob_get_length());
		ob_end_flush();
		ob_flush();
		flush();
	}
	
	/*
	 *A function to take care of posting delayed responces to slack
	 */
	private function post_to_slack($url, $payload){
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
		
		$json_response = curl_exec($curl);
		
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		
		if ( $status != 201 ) {
			die("Error: call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
		}
		
		curl_close($curl);
	}
	
	function burndown(){
		set_time_limit(600);
		if(!isset($_GET['token']) || $_GET['token'] !== $this->config->item('burndown_slack_token')){//If we don't get a valid token from slack, die
			die();
		}
		
		if(!isset($_GET['text']) || strlen($_GET['text']) < 2){ //Check if $_GET['text'] was passed in, if not send error message and die
			$slack_payload = array (
				'text' => 'Please supply a valid project name after the /burndown'
			);
			//Send encode and send the payload
			$this->slack_acknowledgement($slack_payload);
			
			die();
		}
		
		$slack_payload = array (
				'text' => 'You want a graph!? Ok give me a minute...'
			);
		//Slack only gives us 3 seconds to respond...Nothing happens in Jira in under 3 seconds, so send a response once validation passes and then use the responce url to notify the user once the operation is complete
		$this->slack_acknowledgement($slack_payload);
		
		//Contact the jira_tools endpoint and get it to provide us a burndown chart
		$text_array = explode(" ", $_GET['text']);//We support project and assignee burndowns, so if there are two words in the variable we need to split it and form the cirrect URL
		$burndown_string = $text_array[0]; //The URL will always have the project slug first
		if(isset($text_array[1])){//Check is a user name has also been supplied
			$burndown_string .= '/' . $text_array[1];//If yes update the URL string to include the username
		}
		$response = $this->jira_tools_connector($this->config->item('base_url') . '/jira_tools/burndown/' . $burndown_string);
		if(isset($response->success) && $response->success == "true"){//Did it work?
			/*
			 *Create array to house payload for slack
			 */
			$slack_payload = array (
				"response_type" => "in_channel",//This determines if the responce should be seen by all channel occuments or just the requester
				'attachments' => 
				array (
					0 =>
					array(
						'fallback' => $_GET['text'] . ' Current Sprint Burndown Chart',
						'color' => '#36a64f',
						'title' => $_GET['text'] . ' Current Sprint Burn Down',
						'title_link' => $response->url,
						'image_url' => $response->url,
						'thumb_url' => $response->url
					),
				),
			);
		}else{
			$slack_payload = array (
				'text' => 'Sorry, the burndown could not be generated'
			);
		}

		//encode and send the payload
		$this->post_to_slack($_GET['response_url'], $slack_payload);
		
	}
}