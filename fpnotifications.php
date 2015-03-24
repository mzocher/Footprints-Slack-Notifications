<?php
$debug = FALSE;
if($debug)
{
	ini_set('display_errors', true);
	error_reporting(E_ALL);
}
require_once("config.phpi");
require_once("functions.phpi");

$goBack = 119; //How many seconds to go back in time (putting right near the queries)

// Get our list of people we care to alert
$users = getSlackUsers("usernames", "FULL");
// Extra parameters for each Slack message
$extraparams = array("username" => "Footprints", "icon_url" => FOOTPRINTS_ICON);

// Get our tickets we should ignore
$oldIgnores = unserialize(file_get_contents('slack_notifications.bin'));
$newIgnores = array();
if(isset($oldIgnores['fieldchange'])) foreach($oldIgnores['fieldchange'] as $key => $item) if($item >=time()) $newIgnores['fieldchange'][$key] = $item;
if(isset($oldIgnores['assignment'])) foreach($oldIgnores['assignment'] as $key => $item) if($item >=time()) $newIgnores['assignment'][$key] = $item;

$changes = array();
$limitTo = date("Y-m-d H:i:s", time() - $goBack);
//***** Look for any new tickets
$query = "SELECT mrid, mrsubmitter FROM MASTER" . FOOTPRINTS_PROJECT . " WHERE mrsubmitdate>='$limitTo'";
$resultsNew = fpSearch($query);

//***** Look for any changes to fields
$query = "SELECT * from MASTER" . FOOTPRINTS_PROJECT . "_FIELDHISTORY WHERE mrTIMESTAMP>='$limitTo'";
$resultsFields = fpSearch($query);

//***** Look for any changes to assignments
$query = "SELECT * from MASTER" . FOOTPRINTS_PROJECT . "_ASSIGNMENT WHERE assignmenttypeid='1' AND assignee IS NOT NULL AND (assignmentbegindate>='$limitTo' OR assignmentenddate>='$limitTo')";
$resultsAssign = fpSearch($query);


foreach($resultsNew as $item)
{
	$number = $item->mrid;
	$changes[$number][] = array("by" => $item->mrsubmitter, "type"=> "creation", "note" => "Created by " . $item->mrsubmitter . ". ");
}

foreach($resultsFields as $item)
{
	if(isset($newIgnores['fieldchange'][$item->mrsequence])) continue;
	$number = $item->mrid;
	if($item->mrfieldname=="mrSTATUS") $item->mrfieldname=="status";
	if(strpos($item->mruserid, "_~_WEBSVCS")!==FALSE) $item->mruserid = substr($item->mruserid, 0, -10);
	$changes[$number][] = array("by" => $item->mruserid, "type"=> "fieldchange", "note" => $item->mruserid . " has changed " . $item->mrfieldname . " from " . cleanData($item->mroldfieldvalue) . " to " . cleanData($item->mrnewfieldvalue) . ". ");

	// Add to $newIgnores
	$newIgnores['fieldchange'][$item->mrsequence] = strtotime($item->mrtimestamp);
}

foreach($resultsAssign as $item)
{
	if(isset($newIgnores['assignment'][$item->masterassignmentid])) continue;
        $number = $item->mrid;
        if(strtotime($item->assignmentbegindate)>=strtotime($limitTo)) 
	{
		$changes[$number][] = array("by" => "", "type"=> "assignment", "note" => cleanData($item->assignee) . " has been assigned."); 
	        // Add to $newIgnores
	        $newIgnores['assignment'][$item->masterassignmentid] = strtotime($item->assignmentbegindate);
		// Figure out who did the assigning & unassigning
	}
	else if(strtotime($item->assignmentenddate)>=strtotime($limitTo))
	{
		//Notification for people on the ticket
		$changes[$number][] = array("by" => "", "type"=> "unassignment", "note" => cleanData($item->assignee) . " has been unassigned.");
	        // Add to $newIgnores
	        $newIgnores['assignment'][$item->masterassignmentid] = strtotime($item->assignmentenddate);
		// Figure out who did the assigning & unassigning
	}
}

// Write our list of ticket changes to ignore in case they happened in the FUTURE!
file_put_contents('slack_notifications.bin', serialize($newIgnores));
print_r($changes);
// If $ticketnums is empty, nothing has changed.  Die!
$ticketnums = array_keys($changes);
if(empty($ticketnums)) die();

// Figure out who needs to be alerted
$query = "SELECT mrid, mrassignees, mrtitle FROM MASTER" . FOOTPRINTS_PROJECT . " WHERE ";
foreach($ticketnums as $num) $query .= "mrID='$num' OR ";
$query = substr($query, 0, -3);
$assignees_data = fpSearch($query);
if($debug) print_r($assignees_data);

// ***** Send the messages out to assignees!
$directMessages = array();
$feedMessages = array();

foreach($assignees_data as $item) //Look at our tickets to find the title and who's assigned
{
	$number = $item->mrid;

	// Direct messages to the assignees
	$assignees = explode(" ", $item->mrassignees);
	foreach($assignees as $assignee) if(isset($users[$assignee])) //They have Slack, tell them the changes
	{
		foreach($changes[$number] as $change)
		{
			if($change['by']==$assignee) continue;
			if(isset($directMessages[$number][$assignee])) $directMessages[$number][$assignee] .= $change['note'];
			else $directMessages[$number][$assignee] = "Ticket #" . $number . " (" . $item->mrtitle . ") " . $change['note'] . " ";
		}
	}
	foreach($changes[$number] as $change) if($change['type']=='creation') $feedMessages[$number] = "Ticket #" . $number . " (" . $item->mrtitle . ") " . $change['note'] . " ";
}
foreach($directMessages as $number)
{
	foreach($number as $username => $message) 
	{
		sendSlackMsg($message, $users[$username], $extraparams);
		echo "\nMessage to " . $username . ": $message";
	}
}
foreach($feedMessages as $message) 
{
	sendSlackMsg($message, SLACK_CHANNEL_FEED, $extraparams);
	echo "\nMessage to FEED: $message";
}
