<?php
function get_page_contents($title) {
	$data = '';
	while ($data == '') {
		$data = curl_get(WIKI_API_URL . '?action=query&titles=' . rawurlencode($title) . '&prop=revisions&rvprop=content&format=xml&salt=' . md5(time()));
	}
	$page_xml = @new SimpleXMLElement($data);
	$contents = (string) ($page_xml->query->pages->page->revisions->rev);
	return $contents;
}

function notify_user($user, $type, $info) {
	global $wikiusername;
	global $MESSAGE_PREFIX, $MESSAGE_SUFFIX, $SANDBOX_TIMEOUT, $DEFAULT_SANDBOX_TEXT, $UNSIGNED_MESSAGE_SUBJECT, $UNSIGNED_MESSAGE_BODY, $NOCAT_MESSAGE_SUBJECT, $NOCAT_MESSAGE_BODY, $RAPID_MESSAGE_SUBJECT, $RAPID_MESSAGE_BODY, $TOO_MANY_EDITS_COUNT, $TOO_MANY_EDITS_TIME, $NOBOTS_OVERRIDE_MESSAGE;
	//function to notify user
	//check if this is on the ignore list
	$page_ignore_list = explode("\n", file_get_contents('conf/ignore.txt')); //check if the page is ignored
	if (in_array($info['page'], $page_ignore_list)) {
		echo $info['page'] . ' is ignored. NOT sending notification.' . "\n";
		return;
	}
	//check if the user's talk page allows bots, and if it does, check if the name is on the nobots override list
	$overridenobots = false;
	$talk = get_page_contents('User_talk:' . $user);
	if (stristr($talk, '{{nobots}}')) { //check for nobots
		preg_match_all('%<nowiki>(.*?)' . preg_quote('{{nobots}}') . '(.*?)</nowiki>%msi', $talk, $nowikimatches);
		preg_match_all('%' . preg_quote('{{nobots}}') . '%msi', $talk, $nobotsmatches);
		if (sizeof($nowikimatches[0]) < sizeof($nobotsmatches[0])) {
			$nobots_override_list = explode("\n", file_get_contents('conf/nobotsoverride.txt')); //check if user is on nobots override list
			if (!in_array($user, $nobots_override_list)) {
				echo $user . '\'s talk page does not allow bots. Skipping...' . "\n";
				return;
			} else {
				$overridenobots = true;
			}
		}
	}
	$dateformat = 'd M Y H:i:s';
	$datasignature = '';
	switch ($type) { //generate message
		case 'sign':
			$message = str_replace('($revid)', $info['revid'], str_replace('($page)', $info['page'], $UNSIGNED_MESSAGE_BODY));
			echo date($dateformat, time()) . ' ' . $user . ' did not sign post (' . $info['page'] . ' revision ' . $info['revid'] . '), notifying...' . "\n";
			$subject = $UNSIGNED_MESSAGE_SUBJECT;
			$summary = 'Unsigned post: Revision ' . $info['revid'] . ' of [[' . $info['page'] . ']]';
			$datasignature = 'nosign-' . $info['revid'];
			break;
		case 'excessive':
			$message = str_replace('($count)', $info['count'], str_replace('($page)', $info['page'], $RAPID_MESSAGE_BODY));
			echo date($dateformat, time()) . ' Too many edits (' . $info['count'] . ') from ' . $user . ' on page ' . $info['page'] . ', notifying...' . "\n";
			if (stristr($info['page'], 'talk')) {
				echo 'Ignoring talk pages, skipping...' . "\n";
				return;
			}
			$subject = $RAPID_MESSAGE_SUBJECT;
			$summary = 'Excessive editing on page: [[' . $info['page'] . ']]';
			$datasignature = 'rapid-' . $info['page'] . floor(time() / (60 * 60 * 24)) . '|' . 'rapid-' . $info['page'] . (floor(time() / (60 * 60 * 24)) + 1);
			break;
		case 'uncat':
			$message = str_replace('($page)', $info['page'], $NOCAT_MESSAGE_BODY);
			echo date($dateformat, time()) . ' Uncategorized page: ' . $info['page'] . ' by ' . $user . ', notifying...' . "\n";
			$summary = 'No category on new page: [[' . $info['page'] . ']]';
			$subject = $NOCAT_MESSAGE_SUBJECT;
			$datasignature = 'uncat-' . $info['page'];
			break;
	}
	//check if user was already notified
	$data = curl_get(WIKI_API_URL . '?action=query&prop=revisions&titles=User_talk:'.  rawurlencode($user) . '&rvlimit=50&rvprop=timestamp|user|comment&format=xml');
	$historyxml = new SimpleXMLElement($data);
	if (isset($historyxml->query->pages->page->revisions->rev)) {
		foreach ($historyxml->query->pages->page->revisions->rev as $rev) {
			if ((string)$rev->attributes()->user == $wikiusername && strstr((string)$rev->attributes()->comment, $datasignature)) {
				echo 'Already notified, skipping...' . "\n";
				return;
			}
		}
	}
	$message = $MESSAGE_PREFIX . $message . $MESSAGE_SUFFIX; //piece it together
	if ($overridenobots) { //mention if user was nobots overridden
		$message .= $NOBOTS_OVERRIDE_MESSAGE;
	}
	$message .= '~~~~';

	//submit the edit
	if (defined('anononly')) {
		echo ' >> Anonymous mode is enabled. The following message was not posted:' . "\n";
		echo ' >>>> Subject: ' . $subject . "\n";
		foreach (explode("\n", $message) as $line) {
			echo ' >>> ' . $line . "\n";
		}
		echo "\n";
	} else {
		checkshutoff();
		$tokenxml = new SimpleXMLElement(curl_post(WIKI_API_URL . '?action=query&prop=info|revisions&intoken=edit&titles=User_talk:' . rawurlencode($user) . '&format=xml', '', true)); //get token
		$edittoken = (string)$tokenxml->query->pages->page->attributes()->edittoken;

		$return = curl_post(WIKI_API_URL . '', 'action=edit&title=User_talk:' . $user . '&section=new&sectiontitle=' . $subject . '&summary=' . rawurlencode($summary . ' (' . $datasignature . ')') . '&text=' . rawurlencode($message) . '&tags=wikimonitor-notification&format=xml&bot=true&token=' . rawurlencode($edittoken)); //submit the edit
		$xml = new SimpleXMLElement($return);
		$revid = (int)$xml->edit->attributes()->newrevid;
	}
}

function submit_edit($title, $contents, $summary, $minor = false) {
	checkshutoff();
	$tokenxml = new SimpleXMLElement(curl_post(WIKI_API_URL . '?action=query&prop=info|revisions&intoken=edit&titles=' . rawurlencode($title) . '&format=xml', '')); //get token
	$edittoken = (string)$tokenxml->query->pages->page->attributes()->edittoken;
	$return = curl_post(WIKI_API_URL . '', 'action=edit&title=' . rawurlencode($title) . '&summary=' . $summary . '&text=' . rawurlencode($contents) . '&format=xml&bot=true' . ($minor = true ? '&minor=true' : '') . '&token=' . rawurlencode($edittoken)); //submit the edit
}

function loadconfig() { //load online configuration
	global $category_templates;
	global $MESSAGE_PREFIX, $MESSAGE_SUFFIX, $SANDBOX_TIMEOUT, $DEFAULT_SANDBOX_TEXT, $UNSIGNED_MESSAGE_SUBJECT, $UNSIGNED_MESSAGE_BODY, $NOCAT_MESSAGE_SUBJECT, $NOCAT_MESSAGE_BODY, $RAPID_MESSAGE_SUBJECT, $RAPID_MESSAGE_BODY, $TOO_MANY_EDITS_COUNT, $TOO_MANY_EDITS_TIME, $NOBOTS_OVERRIDE_MESSAGE;
	echo 'Loading config - this could take a while.' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', get_page_contents(CONFIG_LOCATION . '/CategoryTemplates'), $matches); //templates that contain a category
	$category_templates = explode(',', $matches[1]);
	echo 'Loaded config: category templates' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%ms', get_page_contents(CONFIG_LOCATION . '/MessagePrefix'), $matches); //prefix to add to messages
	$MESSAGE_PREFIX = $matches[1];
	echo 'Loaded config: message prefix' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%ms', get_page_contents(CONFIG_LOCATION . '/MessageSuffix'), $matches); //suffix to add to messages
	$MESSAGE_SUFFIX = $matches[1];
	echo 'Loaded config: message suffix' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', get_page_contents(CONFIG_LOCATION . '/SandboxTimeout'), $matches); //time to wait before clearing sandbox
	$SANDBOX_TIMEOUT = $matches[1];
	
	preg_match('%<code><pre><nowiki>(.*?)</nowiki></pre></code>%ms', get_page_contents(CONFIG_LOCATION . '/DefaultSandbox'), $matches); //default sandbox text
	$DEFAULT_SANDBOX_TEXT = $matches[1];
	echo 'Loaded config: default sandbox text' . "\n";

	preg_match('%<pre>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</pre>%ms', get_page_contents(CONFIG_LOCATION . '/UnsignedMessage'), $matches); //unsigned post message
	$UNSIGNED_MESSAGE_SUBJECT = $matches[1];
	$UNSIGNED_MESSAGE_BODY = $matches[2];
	echo 'Loaded config: unsigned messages' . "\n";

	preg_match('%<code><nowiki>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</nowiki></code>%msi', get_page_contents(CONFIG_LOCATION . '/NoCategoryMessage'), $matches); //no category message
	$NOCAT_MESSAGE_SUBJECT = $matches[1];
	$NOCAT_MESSAGE_BODY = $matches[2];
	echo 'Loaded config: no category messages' . "\n";

	preg_match('%<code><nowiki>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</nowiki></code>%ms', get_page_contents(CONFIG_LOCATION . '/RapidEditMessage'), $matches); //rapid editing message
	$RAPID_MESSAGE_SUBJECT = $matches[1];
	$RAPID_MESSAGE_BODY = $matches[2];
	echo 'Loaded config: quick editing messages' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', get_page_contents(CONFIG_LOCATION . '/TooManyEdits'), $matches); //too many edits
	$parts = explode(',', $matches[1]);
	$TOO_MANY_EDITS_COUNT = $parts[0];
	$TOO_MANY_EDITS_TIME = $parts[1];
	echo 'Loaded config: definition of too many edits' . "\n";
		
	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%ms', get_page_contents(CONFIG_LOCATION . '/NoBotsOverrideMessage'), $matches); //default sandbox text
	$NOBOTS_OVERRIDE_MESSAGE = $matches[1];
	echo 'Loaded config: NoBots override message' . "\n";
}

function login($wikiusername, $wikipassword) {
	global $logincount;
	echo 'Logging in...' . "\n";
	//log in
	$out = curl_get(WIKI_API_URL . '?format=xml&action=query&meta=tokens&type=login', true);
	$login_xml = new SimpleXMLElement($out);
	$token = (string)$login_xml->query->tokens->attributes()->logintoken;

	$out = curl_post(WIKI_API_URL . '',  'action=clientlogin&username=' . $wikiusername . '&password='.  $wikipassword . '&logintoken=' . rawurlencode($token) . '&loginreturnurl=' . rawurlencode(WIKI_API_URL) . '&format=xml');
	$login_xml = new SimpleXMLElement($out);
	if ((string)$login_xml->clientlogin->attributes()->status != 'PASS') {
		echo 'Login failed!'. "\n";
		switch ((string)$login_xml->clientlogin->attributes()->message) {
			case 'Throttled':
				echo 'Too many recent logins. Please wait ' . (int)$login_xml->login->attributes()->wait . ' seconds.' . "\n"; break;
			default:
				print_r($login_xml);
		}
		die;
	}
	$logincount++;
	if ($logincount == 1) {
		echo 'Login success!' . "\n";
	}
}

function checkshutoff() {
	$shutoffpage = get_page_contents(SHUTOFF_PAGE); //check for automatic shutoff
	if (!strstr($shutoffpage, '<div id="botenabled" style="font-weight:bold">true</div>')) {
		preg_match('%\(This page was last edited by (.*?)\)%', curl_get('http://wiki.scratch.mit.edu/wiki/User:WikiMonitor/Disable'), $matches);
		echo 'This bot has been disabled by ' . $matches[1] . "\n"; die;
	}
}