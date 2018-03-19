<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Jira_tools extends CI_Controller {
	
	function __construct() {
		parent::__construct();
		$this->load->library('jira');

	}

	/*
	 *Checks if meeting issues have already been created for all of a projects FUTURE sprints on a per assignee basis
	 *if none are found it creates them
	 *Assignees are found either as a comma seperated strinf in the project description OR as a single user over ride in the $_GET['text']
	 */
	function create_meetings($project_key){
		$assignee_override = array();
		$meetings = $this->sprint_meetings();
		
		$issues_created = 0;
		$project = $this->jira->get_project(strtoupper($project_key));//Get the project, we will need its ID and description later
		if(isset($assignee_override[0])){//Check if a user override was supplied
			$project_team = $assignee_override;//If it was use this instead of the user in the project description
		}else{
			$project_team = explode(',', $project->description);//We are storing the users in the project description as a comma seperated string. Turn this into an array
		}
		$project_boards = $this->jira->get_boards($project_key);//Get all boards that this project is a part of
		foreach($project_boards->values as $project_board){
			$sprints = $this->jira->get_sprints($project_board->id);//Get all sprints for this board
			foreach($sprints->values as $sprint){
				if($sprint->state == "future"){//If sprint is in the future
					foreach($project_team as $assignee){//For each user in the production team
						$jql = "project=" . $project_key . " AND assignee=" . $assignee . " AND sprint =" . $sprint->id . " AND labels = Meetings";
						$results = $this->jira->jql($jql);
						if(isset($results->errorMessages[0])){//If the JQL is invalid and Jira gives us an error pass it on, not great but better than nothing
							$this->send_error($results->errorMessages[0]);
						}
						if($results->total == 0){//If there aren't any results lets create some!
							foreach($meetings as $meeting){
								if($this->jira->create_meeting_issue($project->id, $sprint->id, $assignee, $meeting->summary, $meeting->description, $meeting->originalEstimate, $meeting->labels) === TRUE){//If the issue is successfully created increment the counter
									++$issues_created;
								}
							}
						}
					}
				}
			}
		}
		
		//Build and Send the payload
		$payload['status'] = 200;
		$payload['success'] = 'true';
		$payload['result'] = $issues_created . ' JIRA Issues Created. Have a great day!';
		$this->output->set_status_header('200');
		$this->output->set_header('Content-Type: application/json; charset=utf-8');
		echo json_encode($payload);
	}
	
	function burndown($project_key, $assignee = null){		
		/*
		 * The relationships between projects and boards is many to many.
		 * We need to use the project Key to find the projects boards and hope there is only one board
		 * The board ID is then used to build the URL to get the burndown json
		 */
			
		$boards = $this->jira->get_boards(strtoupper($project_key));//Project key needs to be upper case
		
		$current_sprint_id = $this->jira->get_current_sprint_id($project_key);//Get the current sprint ID and use it to build the URL for the burndown json
		
		$data = $this->jira->jira_graph_connect($boards->values[0]->id, $current_sprint_id);
		
		//Define the sprint timebox
		$sprint_start_date = '3000-12-31';
		$sprint_end_date = '2000-12-31';
		foreach($data->workRateData->rates as $rates){
			//Atlassian store ther unix time stamp as number of miliseconds since epoch -.-
			if(date("Y-m-d", substr($rates->start, 0, -3)) < $sprint_start_date){//Find sprint start date
				$sprint_start_date = date("Y-m-d", substr($rates->start, 0, -3));
			}
			if(date("Y-m-d", substr($rates->end, 0, -3)) > $sprint_end_date){//Find sprint end date
				$sprint_end_date = date("Y-m-d", substr($rates->end, 0, -3));
			}
		}

		//Find how much of the sprint has been completed
		$total_seconds = 0;
		$issue_array = array();
		foreach($data->changes as $key => $changes){//$changes is provided in chronological order, so we can just add remove add remove until we get to the end and we should have the right result
			
			if($assignee !== null){//If this is set then this is a personal burndown, so we need to exclude all issues that don't match this assignee
				$assignee_issue = $this->jira->get_issue($changes[0]->key);
				if(!isset($assignee_issue->fields->assignee->name) || $assignee_issue->fields->assignee->name != $assignee){//Does this issue assignee match the passed in assignee?
					continue;//If it doesn't stop this loop and start the next
				}
			}
			
			if(isset($changes[0]->statC->newValue)){//If an item has a value add it to the array
				$issue_array[$changes[0]->key]['value'] = $changes[0]->statC->newValue;
			}elseif(isset($changes[0]->added) && $changes[0]->added == false){//If the item has "added": false this means it was removed from the sprint and needs to be unset from our array
				unset($issue_array[$changes[0]->key]);
			}
			if(isset($changes[0]->column->done) && $changes[0]->column->done == true){//If done is true then record the timestamp it was completed
				$issue_array[$changes[0]->key]['done'] = date("Y-m-d", substr($key, 0, -3));
			}
		}

		/*
		 *Now that we have a clean array loop and count to get the total
		 */
		foreach($issue_array as $ia){
			if(isset($ia['value'])){
				$total_seconds = $total_seconds + $ia['value'];//If the changes has hours add them to the total
			}
		}
		$total_hours = ($total_seconds/60)/60;//Convert time from seconds to hours
		
		/*
		 *Find how many week days there are between sprint start and sprint end excluding weekends
		 *Add these days to an array that we can use to contain our burndown progress
		 */
		$sprint_days = array();
		$begin = new DateTime( $sprint_start_date );
		$end = new DateTime( $sprint_end_date );
		$end = $end->modify( '+1 day' ); 
		
		$interval = new DateInterval('P1D');
		$daterange = new DatePeriod($begin, $interval ,$end);
		
		foreach($daterange as $date){
			if($date->format("l") !== "Saturday" && $date->format("l") !== "Sunday"){//Don't include weekends
				$sprint_days[$date->format("Y-m-d")] = 0;
			}
		}
		
		
		/*
		 *Now that we have an array of days and an array of issues,
		 *loop through the array of issues and add their value to the total for each day
		 */
		foreach($issue_array as $issue){
			if(isset($issue['done']) && isset($issue['value'])){
				//If for what ever reason someone does work on a weekend, move move the work date to the following monday so that it doesn't break the graph.
				if(date('w', strtotime($issue['done'])) == 0 || date('w', strtotime($issue['done'])) == 6) {//Is the day on a weekend?
					$issue['done'] = date('Y-m-d', strtotime("next monday", strtotime($issue['done'])));//If so move the time entry to the next monday
				}
				$sprint_days[$issue['done']] = $sprint_days[$issue['done']] + $issue['value'];
			}
		}
		
		/*
		 *Now that we have all of the data in a useable format, build the graph URL
		 */
		$bench_increment = round($total_hours/count($sprint_days), 2);
		$bench_daily = $total_hours;

		$bench_string = "";
		while($bench_daily > 0){//Create a string for the linear line for the optimal burndown bench mark
			$bench_string = $bench_string . round($bench_daily, 0) . ",";
			$bench_daily = $bench_daily - $bench_increment;//Reduce increment as we count down
		}
		$bench_string = $bench_string . "0";
		
		$progress_string = "";
		$progress_total = $total_hours;
		foreach($sprint_days as $key => $sprint_day){//Create a string for the sprint progress burn down
			if($key > $this->jira->get_day_in_sydney()){//Line needs to stop after today so break the loop if the $key is greater than today
				break;
			}
			$progress_total = $progress_total - (($sprint_day/60)/60);//Convert from seconds to hours and then subtract from total
			$progress_string = $progress_string . "," . $progress_total;
		}
		$progress_string = $total_hours . $progress_string;

		//Using Image Charts to generate graphs https://image-charts.com/documentation
		$chart_url = "https://image-charts.com/chart?cht=lc&chg=10,10,3,2&chd=t:" . $bench_string . "|" . $progress_string . "&chds=0," . $total_hours . "&chs=500x500&chco=999999,FF0000&chxt=x,y&chxr=0,0," . count($sprint_days) . ",1|1,0," . $total_hours  . "&chma=30,30,30,30";
		
		//Build and Send the payload
		$payload['status'] = 200;
		$payload['success'] = 'true';
		$payload['url'] = $chart_url;
		$this->output->set_status_header('200');
		$this->output->set_header('Content-Type: application/json; charset=utf-8');
		echo json_encode($payload);
	}
	
	private function send_error($message = 'failed', $status = '400'){
		$this->output->set_header('Content-Type: application/json; charset=utf-8');
		$this->output->set_status_header($status);
		echo json_encode(array('status' => $status, 'error' => $message));
		die();
   }
   
   private function sprint_meetings(){	
		$meetings[0] = (object) array('summary' => 'Agency Standup', 'description' => 'All hands stand-up, 9.05am - 9.20am', 'originalEstimate' => '2.5h', 'labels' => 'Meetings');
		$meetings[1] = (object) array('summary' => 'Project Standup', 'description' => 'Project team stand-up, 9.20am - 9.35am', 'originalEstimate' => '2.5h', 'labels' => 'Meetings');
		$meetings[2] = (object) array('summary' => 'Sprint Planning', 'description' => 'As a team define our sprint goals and outline the tasks needed to reach those goals', 'originalEstimate' => '2h', 'labels' => 'Meetings');
		$meetings[3] = (object) array('summary' => 'First Client Check-in', 'description' => 'First client check-in of the sprint (Week 1 Tuesday)', 'originalEstimate' => '1h', 'labels' => 'Meetings');
		$meetings[4] = (object) array('summary' => 'Second Client Check-in', 'description' => 'Second client check-in of the sprint (Week 1 Thursday)', 'originalEstimate' => '1h', 'labels' => 'Meetings');
		$meetings[5] = (object) array('summary' => 'Third Client Check-in', 'description' => 'Third client check-in of the sprint (Week 2 Tuesday)', 'originalEstimate' => '1h', 'labels' => 'Meetings');
		$meetings[6] = (object) array('summary' => 'Sprint Demo', 'description' => 'End of sprint demo to all stakeholders (Week 2 Friday)', 'originalEstimate' => '1.5h', 'labels' => 'Meetings');
		$meetings[7] = (object) array('summary' => 'Sprint Retrospective', 'description' => 'Team regroup and reflection of what did and didn\'t go well (Week 2 Friday)', 'originalEstimate' => '1h', 'labels' => 'Meetings');

		return $meetings;
   }
   
   
}