<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Tenkf {
	
	protected $CI;
	
	public function __construct(){
		$this->CI =& get_instance();
	}
	
	/*
	 * For details on data structure please refer to:
	 * https://github.com/10Kft/10kft-api
	 */
	
	//Connects to 10,000ft api and returns a decoded json object
	private function tenkf_connect($resource = array()){//Takes an array containing the specifics of the end point
		$url = $this->CI->config->item('tenkf_base_url') . implode("/", $resource);//Inplode the array to buld the URI
		//Do cURL stuff
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'auth: ' . $this->CI->config->item('tenkf_token')));
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
	
	function get_project($project_id){
		$project = $this->tenkf_connect(array('projects', $project_id));
		return $project;
	}
	
	function get_phases($project_id){//Phases are just projects that have a parent id
		$phases = $this->tenkf_connect(array('projects', $project_id, 'phases?per_page=20000&fields=tags,budget_items,project_state,summary,custom_field_values,phase_count'));
		return $phases;
	}
	
	function get_assignments($phase_id){//Phases are just projects that have a parent id
		$assignments = $this->tenkf_connect(array('projects', $phase_id, 'assignments?per_page=20000'));
		return $assignments;
	}
	
	function get_time_entries($phase_id){
		$time_entries = $this->tenkf_connect(array('projects', $phase_id, 'time_entries?per_page=20000'));
		return $time_entries;
	}
}