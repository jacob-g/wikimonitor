<?php
define('WIKI_API_URL', 'https://en.scratch-wiki.info/w/api.php');

//include necessary libaries
include 'includes/wikifunctions.php';
include 'includes/genfunctions.php';
//include configuration
include 'conf/logininfo.php';
include 'conf/wikiinfo.php';

//define('anononly', 1); //uncomment to disable logging in
//log in
$alreadyseen = array();
$already_notified = array();
$logincount = 0;

$lastreloadedconfig = time();
$force = false;
if (!empty($argv) && in_array('config', $argv)) {
	echo 'Reloading config' . "\n";
	$force = true;
}
loadconfig($force);

$clear_sandbox_time = 0; //start out with no clearing sandbox
while (true) {
	if (!defined('anononly')) {
		echo '[INFO] Logging in' . "\n";
		login($wikiusername, $wikipassword);
	}

	for ($cyclecount = 1; $cyclecount <= 20; $cyclecount++) { //every 10 cycles, log back in
		echo '[INFO] Starting cycle' . "\n";
		$rc_json = getRecentChanges();
		//check for excessive edits
		checkEditCounts($rc_json, $TOO_MANY_EDITS_COUNT, $TOO_MANY_EDITS_TIME);
		
		//check for unsigned posts
		checkUnsignedPosts($rc_json);
		
		//check for uncategorized pages/files
		//TODO: implement this

		sleep(90);
	}
}
