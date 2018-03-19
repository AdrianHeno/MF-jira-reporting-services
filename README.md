# Mentally Friendly Reporting Services

This project contains services for communicating with the Jira and 10K APIs and for creating reports from the data provided by these services.

The services are designed to be stand alone and all communication between services is handled via cURL, if changes are made to a service in isolation it should be versioned as a new end point to ensure that current dependancies don't break.

## Getting Started

Clone the repo into your web directory and update /application/config/config.php with your base URL and Jira/10K connection information.

### Prerequisites

PHP 5.6.30+


### How To Use

Each API has a Library found in /application/libraries/<Api Provider.php> and each service is a controller foud in /application/controllers/<Service Name.php>
  
Communication between sercies is handeld via cURL

## Current List of Services
### Jira Service

#### Usage Example
To get a json payload containing a sprint burndown image:
/mentallyfriendly-reporting-services/jira_tools/burndown/<project ID / Slug>

To create Sprint meetings for a project:
/mentallyfriendly-reporting-services/jira_tools/create_meetings/<project ID / Slug>


### Slack Bot Service

#### Usage Example
To get a payload containing a sprint burndown image:
/mentallyfriendly-reporting-services/slackbot/burndown?token=<slack token>&text=<project ID / Slug>

To create Sprint meetings for a project:
/mentallyfriendly-reporting-services/slackbot/create_meetings?token=<slack token>&text=<project ID / Slug>

## Built With

* Codeigniter(https://codeigniter.com/) - PHP framework
* Jira API (https://docs.atlassian.com/jira/REST/cloud/) & (https://docs.atlassian.com/jira-software/REST/cloud/?_ga=2.134384244.486504606.1511482855-235499694.1495512092)
* 10kFeet API (https://github.com/10Kft/10kft-api)


