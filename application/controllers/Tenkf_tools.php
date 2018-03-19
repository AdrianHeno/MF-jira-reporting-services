<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Tenkf_tools extends CI_Controller {
	
	function __construct() {
		parent::__construct();
		$this->load->library('tenkf');
		$this->load->library('jira');

	}

	public function get_phase_and_sprint($tenk_project_id, $jira_project_key){
		$jira_sprint_id = $this->jira->get_current_sprint_id($jira_project_key);//Get the current Sprint ID (if you are using parrallel sprints this will break)
		$jira_sprint = $this->jira->get_sprint($jira_sprint_id);//Get the sprint
		$jira_sprint_start = explode('T', $jira_sprint->startDate);//Explode the Jira date time stamp
		$jira_sprint_start = $jira_sprint_start[0];//Get just the date portion
		$jira_sprint_start = strtotime($jira_sprint_start);//Converto to timestamp
			
		$tenK_phases = $this->tenkf->get_phases($tenk_project_id);
		
		$tenk_phase_id = null;
		foreach($tenK_phases->data as $phase){
			$phase_start = strtotime($phase->starts_at);//Get 10k start date and convert to timestamp
			$phase_end = strtotime($phase->ends_at);//Get 10k end date and convert to timestamp
			if($jira_sprint_start >= $phase_start && $jira_sprint_start < $phase_end){//Did the jira sprint start during the 10K phase? If it did its probably the one we want
				$tenk_phase_id = $phase->id;//Get the phase ID so we can create the relationship
				break;//Break out of the loop, we are done here. (unless ops did something funky)
			}
		}
		
		$tenk_time_entries_by_user = array();
		if($tenk_phase_id != null){
			$tenk_time_entries = $this->tenkf->get_time_entries($tenk_phase_id);
			foreach($tenk_time_entries->data as $time_entry){
				$tenk_time_entries_by_user['user_id'] = $time_entry['user_id'];
				$tenk_time_entries_by_user['user_id'] = $time_entry['user_id'];
			}
		}
	}
	
   
   
}