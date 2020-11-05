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
	echo "\033[1;44m" . '[INFO]' . "\033[0m" . ' Reloading config' . "\n";
	$force = true;
}
loadconfig($force);

$clear_sandbox_time = 0; //start out with no clearing sandbox
while (true) {
	if (!defined('anononly')) {
		login($wikiusername, $wikipassword);
	}

	for ($cyclecount = 1; $cyclecount <= 20; $cyclecount++) { //every 10 cycles, log back in
		echo "\033[1;44m" . '[INFO]' . "\033[0m" . ' Starting cycle' . "\n";
		$rc_json = getRecentChanges();
		
		//check for excessive edits
		checkEditCounts($rc_json, $TOO_MANY_EDITS_COUNT, $TOO_MANY_EDITS_TIME);
		
		//check for unsigned posts
		checkUnsignedPosts($rc_json);
		
		//check for uncategorized pages/files
		checkMissingCategories($rc_json);
		
		//check for whether or not the sandbox needs to be cleared
		checkSandbox($rc_json, $SANDBOX_TIMEOUT, $DEFAULT_SANDBOX_TEXT);
		
		sleep(90);
	}
}
