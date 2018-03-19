<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Jira {
	
	protected $CI;
	
	public function __construct(){
		$this->CI =& get_instance();
	}
	
	/**
	 * Creates multiple issues
	 * */
	public function create_meeting_issue($project_id, $sprint_id, $assignee, $summary, $description, $originalEstimate, $labels){
		$username = $this->CI->config->item('jira_username');
		$password = $this->CI->config->item('jira_password');
		//define json payload
		$data_string = '{"issueUpdates": [
						{
							"fields": {
							   "project":
							   { 
								  "id": "' . $project_id . '"
							   },
							   "summary": "' . $summary . '",
							   "description": "' . $description . '",
							   "issuetype": {
								  "id": "10100"
							   },
							   "customfield_10115":	'. $sprint_id .',
							    "assignee": {
									"name": "' . $assignee . '"
								},
							   "timetracking": {
									"originalEstimate": "' . $originalEstimate . '"
								},
								"labels":[
									"' . $labels . '"
								]
						   }
						}
					]}';
		
		$url = $this->CI->config->item('jira_base_url') . "issue/bulk";
		$curl = curl_init();
		
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			'Authorization: Basic ' . base64_encode("$username:$password"),
			'Content-Type: application/json',
			'Content-Length: ' . strlen($data_string))
		);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);    
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_VERBOSE, true);
		
		$result = curl_exec($curl);
		return TRUE;
		
	}
	
	private function jira_connect($resource = array()){//Takes an array containing the specifics of the end point
		if(stripos($resource[0], 'board') !== FALSE || stripos($resource[0], 'sprint') !== FALSE){//Unfortunately the JIRA Rest 2.0 doesn't include board or sprint endpoints, to get these we need to use the old 1.0 API
			$jira_url = $this->CI->config->item('jira_base_url_v1');
		}else{
			$jira_url = $this->CI->config->item('jira_base_url');
		}
		
		$url = $jira_url . implode("/", $resource);//Inplode the array to buld the URI

		$username = $this->CI->config->item('jira_username');
		$password = $this->CI->config->item('jira_password');
		//Do cURL stuff
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode("$username:$password")));
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_VERBOSE, true);
		$verbose = fopen('php://temp', 'rw+');
		curl_setopt($curl, CURLOPT_STDERR, $verbose);
		
		$result = (curl_exec($curl));
		return json_decode($result);
	}
	
	function jira_graph_connect($board_id, $sprint_id){
		$username = $this->CI->config->item('jira_username');
		$password = $this->CI->config->item('jira_password');
		
		set_time_limit(600);
		//Call the atlassian graph data API
		$url = "https://mentally-friendly.atlassian.net/rest/greenhopper/1.0/rapid/charts/scopechangeburndownchart.json?rapidViewId=" . $board_id . "&sprintId=" . $sprint_id . "&statisticFieldId=field_timeoriginalestimate";
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode("$username:$password")));
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
	
	function get_project($project_key){//Get project by KEY eg WINE
		$project = $this->jira_connect(array('project', $project_key));
		return $project;
	}
	
	function get_boards($project_key){//Get all agile boards for a project
		$boards = $this->jira_connect(array('board?projectKeyOrId=' . $project_key));
		return $boards;
	}
	
	function get_sprints($board_id){//Get all sprints for an agile board
		$sprints = $this->jira_connect(array('board', $board_id, 'sprint'));
		return $sprints;
	}
	
	function get_sprint($sprint_id){//Get a single sprint
		$sprint = $this->jira_connect(array('sprint', $sprint_id));
		return $sprint;
	}	
	
	private function get_current_sprint($project){//get the current sprint for a project
		$result = $this->jql('project = ' . $project . ' and Sprint in(openSprints())');
		return $result;
	}
	
	
	function get_day_in_sydney(){//The server is in the USA so today isn't starting until 2pm. We need today to start at 12am
		$tz = 'Australia/Sydney';
		$timestamp = time();
		$dt = new DateTime("now", new DateTimeZone($tz)); //first argument "must" be a string
		$dt->setTimestamp($timestamp);
		return $dt->format('Y-m-d');
	}
	
	
	public function get_current_sprint_id($project){//Get the current sprint ID for a project
		$jql_result = $this->get_current_sprint($project);//Get the current sprint for the project

		foreach($jql_result->issues as $issue){//We only need to check 1 of the issues not all
			$sprint_string = explode("[id=", $issue->fields->customfield_10115[0]); //The ID is stored in a string with a bunch of other cruft
			$sprint_id = explode(",",$sprint_string[1]);
			return $sprint_id[0];
			break;//We have what we need, break out of loop
		}
	}
	
	function jql($jql = null){//Performs JQL search
		set_time_limit(600);
		$jql = $this->jql_convert($jql);//Convet Human readable issue properties to custom fields
		$issue_list = $this->jira_connect(array('search', "?maxResults=1000&jql=" . $jql));
		return $issue_list;

	}
	
	function jql_convert($query){//Converts human readable custom field names to CF IDs
		$query = str_replace('"Epic Link"', "cf[10005]",$query);
		return urlencode($query);
	}
	
	function get_issue($key){
		$issue = $this->jira_connect(array("issue", $key));
		return $issue;
	}
}