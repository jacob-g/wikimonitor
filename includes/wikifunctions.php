<?php
function get_page_contents($title) {
	//TODO: convert this to JSON
	$data = '';
	while ($data == '') {
		$data = curl_get(WIKI_API_URL . '?action=query&titles=' . rawurlencode($title) . '&prop=revisions&rvprop=content&format=xml&salt=' . md5(time()));
	}
	$page_xml = @new SimpleXMLElement($data);
	$contents = (string) ($page_xml->query->pages->page->revisions->rev);
	return $contents;
}

function notify_user($user, $type, $info) {
	//TODO: convert all API calls in here to JSON
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
				echo ' -> [CANCEL] ' . $user . '\'s talk page does not allow bots' . "\n";
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
			$subject = $UNSIGNED_MESSAGE_SUBJECT;
			$summary = 'Unsigned post: [[Special:Diff/' . $info['revid'] . '|Revision ' . $info['revid'] . ']] of [[' . $info['page'] . ']]';
			$datasignature = 'nosign-' . $info['revid'];
			break;
		case 'excessive':
			$message = str_replace('($count)', $info['count'], str_replace('($page)', $info['page'], $RAPID_MESSAGE_BODY));
			$subject = $RAPID_MESSAGE_SUBJECT;
			$summary = 'Excessive editing on page: [[' . $info['page'] . ']]';
			$datasignature = 'rapid-' . $info['page'] . floor(time() / (60 * 60 * 24)) . '|' . 'rapid-' . $info['page'] . (floor(time() / (60 * 60 * 24)) + 1);
			break;
		case 'uncat':
			$message = str_replace('($page)', $info['page'], $NOCAT_MESSAGE_BODY);
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
				echo ' -> [CANCEL] Has already been notified' . "\n";
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
	}
}

function submit_edit($title, $contents, $summary, $minor = false) {
	checkshutoff();
	$tokenxml = new SimpleXMLElement(curl_post(WIKI_API_URL . '?action=query&prop=info|revisions&intoken=edit&titles=' . rawurlencode($title) . '&format=xml', '')); //get token
	$edittoken = (string)$tokenxml->query->pages->page->attributes()->edittoken;
	$return = curl_post(WIKI_API_URL . '', 'action=edit&title=' . rawurlencode($title) . '&summary=' . $summary . '&text=' . rawurlencode($contents) . '&format=xml&bot=true' . ($minor = true ? '&minor=true' : '') . '&token=' . rawurlencode($edittoken)); //submit the edit
}

function loadconfig($force = false) { //load online configuration
	global $category_templates;
	global $MESSAGE_PREFIX, $MESSAGE_SUFFIX, $SANDBOX_TIMEOUT, $DEFAULT_SANDBOX_TEXT, $UNSIGNED_MESSAGE_SUBJECT, $UNSIGNED_MESSAGE_BODY, $NOCAT_MESSAGE_SUBJECT, $NOCAT_MESSAGE_BODY, $RAPID_MESSAGE_SUBJECT, $RAPID_MESSAGE_BODY, $TOO_MANY_EDITS_COUNT, $TOO_MANY_EDITS_TIME, $NOBOTS_OVERRIDE_MESSAGE;
	echo 'Loading config - this could take a while.' . "\n";
	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', loadconfigoption('CategoryTemplates', $force), $matches); //templates that contain a category
	$category_templates = explode(',', $matches[1]);
	echo 'Loaded config: category templates' . "\n";
	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%ms', loadconfigoption('MessagePrefix', $force), $matches); //prefix to add to messages
	$MESSAGE_PREFIX = $matches[1];
	echo 'Loaded config: message prefix' . "\n";
	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%ms', loadconfigoption('MessageSuffix', $force), $matches); //suffix to add to messages
	$MESSAGE_SUFFIX = $matches[1];
	echo 'Loaded config: message suffix' . "\n";
	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', loadconfigoption('SandboxTimeout', $force), $matches); //time to wait before clearing sandbox
	$SANDBOX_TIMEOUT = $matches[1];
	
	preg_match('%<code><pre><nowiki>(.*?)</nowiki></pre></code>%ms', loadconfigoption('DefaultSandbox', $force), $matches); //default sandbox text
	$DEFAULT_SANDBOX_TEXT = $matches[1];
	echo 'Loaded config: default sandbox text' . "\n";
	preg_match('%<pre>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</pre>%ms', loadconfigoption('UnsignedMessage', $force), $matches); //unsigned post message
	$UNSIGNED_MESSAGE_SUBJECT = $matches[1];
	$UNSIGNED_MESSAGE_BODY = $matches[2];
	echo 'Loaded config: unsigned messages' . "\n";
	preg_match('%<code><nowiki>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</nowiki></code>%msi', loadconfigoption('NoCategoryMessage', $force), $matches); //no category message
	$NOCAT_MESSAGE_SUBJECT = $matches[1];
	$NOCAT_MESSAGE_BODY = $matches[2];
	echo 'Loaded config: no category messages' . "\n";
	preg_match('%<code><nowiki>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</nowiki></code>%ms', loadconfigoption('RapidEditMessage', $force), $matches); //rapid editing message
	$RAPID_MESSAGE_SUBJECT = $matches[1];
	$RAPID_MESSAGE_BODY = $matches[2];
	echo 'Loaded config: quick editing messages' . "\n";
	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', loadconfigoption('TooManyEdits', $force), $matches); //too many edits
	$parts = explode(',', $matches[1]);
	$TOO_MANY_EDITS_COUNT = $parts[0];
	$TOO_MANY_EDITS_TIME = $parts[1];
	echo 'Loaded config: definition of too many edits' . "\n";
		
	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%ms', loadconfigoption('NoBotsOverrideMessage', $force), $matches); //default sandbox text
	$NOBOTS_OVERRIDE_MESSAGE = $matches[1];
	echo 'Loaded config: NoBots override message' . "\n";
}

function loadconfigoption($option, $force) {
	if (file_exists('conf/wikiconfig/' . $option) && !$force) {
		$contents = file_get_contents('conf/wikiconfig/' . $option);
	} else {
		echo ' -> Downloading new version from Wiki' . "\n";
		$contents = get_page_contents(CONFIG_LOCATION . '/' . $option);
		file_put_contents('conf/wikiconfig/' . $option, $contents);
	}
	return $contents;
}

function login($wikiusername, $wikipassword) {
	global $logincount;
	echo '[INFO] Logging in...' . "\n";
	//log in
	$out = curl_get(WIKI_API_URL . '?format=xml&action=query&meta=tokens&type=login', true);
	$login_xml = new SimpleXMLElement($out);
	$token = (string)$login_xml->query->tokens->attributes()->logintoken;

	$out = curl_post(WIKI_API_URL . '',  'action=clientlogin&username=' . $wikiusername . '&password='.  $wikipassword . '&logintoken=' . rawurlencode($token) . '&loginreturnurl=' . rawurlencode(WIKI_API_URL) . '&format=xml');
	$login_xml = new SimpleXMLElement($out);
	if ((string)$login_xml->clientlogin->attributes()->status != 'PASS') {
		echo '[ERROR] Login failed!'. "\n";
		switch ((string)$login_xml->clientlogin->attributes()->message) {
			case 'Throttled':
				echo '[INFO] Too many recent logins. Please wait ' . (int)$login_xml->login->attributes()->wait . ' seconds.' . "\n"; break;
			default:
				print_r($login_xml);
		}
		die;
	}
	$logincount++;
	if ($logincount == 1) {
		echo '[INFO] Login success!' . "\n";
	}
}

function checkshutoff() {
	$shutoffpage = get_page_contents(SHUTOFF_PAGE); //check for automatic shutoff
	if (!strstr($shutoffpage, '<div id="botenabled" style="font-weight:bold">true</div>')) {
		echo '[TERMINATE] THIS BOT HAS BEEN DISABLED!' . "\n"; die;
	}
}

function apiQuery($params, $method = 'get', $array = false) {
	$params['format'] = 'json';
	if ($method == 'get') {
		$result = curl_get(WIKI_API_URL . '?' . http_build_query($params), true);
	} else if ($method == 'post') {
		$result = curl_post(WIKI_API_URL, http_build_query($params), true);
	}
	return json_decode($result, $array);
}

function getRecentChanges() {
	$rc_xml = false;
	//keep if it returns blank, ping again
	while (empty($rc_xml)) {
		$rc_xml = apiQuery(array(
			'action' => 'query',
			'list' => 'recentchanges',
			'rcprop' => 'title|ids|sizes|flags|user|timestamp|loginfo',
			'rclimit' => 150
		));
	}
	return $rc_xml->query->recentchanges;
}

//check for excessive edits
function checkEditCounts($rc_json, $limit, $period) {
	static $already_notified;
	$counts = array();
	$over_limit_counts = array();
	//cycle through the RC
	foreach ($rc_json as $edit) {
		$timestamp = strtotime((string)$edit->timestamp);
		if ($timestamp >= time() - $period * 60) {
			$type = (string)$edit->type;
			if ($type == 'edit') {
				$title = (string)$edit->title;
				if (!stristr($title, 'talk:')) {
					//we have a non-talk edit, so add that to the tally of the number of edits made by this user in this time
					$user = (string)$edit->user;
					if (isset($counts[$title][$user])) {
						$counts[$title][$user]++;
					} else {
						$counts[$title][$user] = 1;
					}
					//if we have too many, mark it as being over the limit
					if ($counts[$title][$user] > $limit) {
						$over_limit_counts[$user][$title] = $counts[$title][$user];
					}
				}
			}
		}
	}
	if (!empty($over_limit_counts)) {
		if (!isset($already_notified)) {
			$already_notified = array();
		}
		foreach ($over_limit_counts as $user => $pages) {
			foreach ($pages as $page => $count) {
				//make sure we don't notify the same user multiple times (wait until two times the period have passed)
				if (!isset($already_notified[$user][$page]) || $already_notified[$user][$page] < time() - 2 * $period) {
					echo '[NOTIF] [TOOMANYEDITS] ' . $user . ' made ' . $count . ' edits to ' . $page . ' in the last ' . $period . ' minutes' . "\n";
					notify_user($user, 'excessive', array('count' => $count, 'page' => $page));
					$already_notified[$user][$page] = time();
				}
			}
		}
	}
}

include realpath(dirname(__FILE__) . '/..') . '/lib/diffengine/lib/Diff.php';
include realpath(dirname(__FILE__) . '/..') . '/lib/diffengine/lib/Diff/Renderer/Text/Unified.php';

function getDiff($old, $new) {
	static $renderer;
	$diff = new Diff(explode("\n", $old), explode("\n", $new), array('ignoreWhitespace' => false, 'ignoreCase' => false));
	if (!isset($renderer)) {
		$renderer = new Diff_Renderer_Text_Unified;
	}
	return $diff->render($renderer);
}


//check for unsigned posts
function checkUnsignedPosts($rc_json) {
	static $already_seen_edits;
	if (!isset($already_seen_edits)) {
		$already_seen_edits = array();
	}
	foreach ($rc_json as $edit) {
		$type = (string)$edit->type;
		$id = (int)$edit->rcid;
		if ($type == 'edit' && !in_array($id, $already_seen_edits)) {
			//we haven't seen this edit before, proceed
			$title = (string)$edit->title;
			if (stristr($title, 'talk:') && !isset($edit->minor)) {
				//it's a talk page edit and not marked as minor, see if it's a new message
				$oldid = (int)$edit->old_revid;
				$newid = (int)$edit->revid;
				$pageid = (int)$edit->pageid;
				$timestamp = strtotime((string)$edit->timestamp);
				$edit_json = apiQuery(array(
					'action' => 'query',
					'prop' => 'revisions',
					'revids' => $oldid . '|' . $newid,
					'rvprop' => 'content'
				));
				if (isset($edit_json->query->pages->$pageid->revisions[0]->texthidden) || isset($edit_json->query->pages->$pageid->revisions[1]->texthidden)) {
					//one or both revisions was censored
				} else {
					//see if the edit contains an unsigned post
					$user = (string)$edit->user;
					$oldtext = $edit_json->query->pages->$pageid->revisions[0]->{'*'};
					$newtext = $edit_json->query->pages->$pageid->revisions[1]->{'*'};
					if (checkUnsignedDiff($oldtext, $newtext)) {
						if ($timestamp > time() - 180) {
							echo '[INFO] Sleeping ' . (180 - (time() - $timestamp)) . ' seconds to wait for ' . $user . ' to sign post on ' . $title . "\n";
							sleep(180 - (time() - $timestamp));
						}
						$contribs = apiQuery(array(
							'action' => 'query',
							'list' => 'usercontribs',
							'ucuser' => $user,
							'ucprop' => 'ids|title|size',
							'uclimit' => 30
						))->query->usercontribs;
						$fixed = false;
						foreach ($contribs as $contrib) {
							$newpageid = (string)$contrib->pageid;
							$size = (string)$contrib->size;
							$newrevid = (string)$contrib->revid;
							if ($newpageid == $pageid && $size > 0 && $newrevid > $newid) {
								$fixed = true;
								break;
							}
						}
						if (!$fixed) {
							echo '[NOTIF] [UNSIGNED] Unsigned post in revision ' . $newid . ' of page ' . $title . ' by ' . $user . "\n";
							notify_user($user, 'sign', array('revid' => $newid, 'page' => $title));
						}
					}
				}
			}
			$already_seen_edits[] = $id;
		}
	}
}

//check a diff for whether not it has an unsigned post
function checkUnsignedDiff($oldtext, $newtext) {
	$lines = explode("\n", $oldtext);
	$diff = getDiff($oldtext, $newtext);
	$difflines = explode("\n", $diff);
	$delta = 2;
	foreach ($difflines as $diffline) {
		if (preg_match('%@@ (\+|-)(\d+)%', $diffline, $matches)) {
			$insertpos = $matches[2];
		} else if (strpos($diffline, '+') === 0 || strpos($diffline, '-') === 0) {
			array_splice($lines, $insertpos + $delta, 0, array($diffline));
			$delta++;
		}
	}	
	
	$lines = array_reverse($lines);
	
	$header_direct_after = true; //if there is a header after the inserted block
	$header_after = true;
	$header_before = false; //if there is a header before the inserted text
	$seen_insertion = false; //if we have seen any insertions
	$seen_sig = false; //if there is a signature on an inserted line
	$exited_block = false;
	foreach ($lines as $line) {
		if (defined('DEBUG')) {
			echo $line;
		}
		if (strpos($line, '+') === 0) {
			//we are adding something
			if (defined('DEBUG')) {
				echo ' [INSERTION]';
			}
			$seen_insertion = true;
			if (stristr($line, '(UTC)')) {
				$seen_sig = true;
				break;
			}
			//this inserts lines in multiple disconnected places, ignore it
			if ($exited_block) {
				$seen_insertion = false;
				break;
			}
		} else if ($seen_insertion) {
			$exited_block = true;
		}
		if (preg_match('%^\+?[^a-zA-Z0-9=]*==[^=].*==[^a-zA-Z0-9=]*$%', $line)) {
			//this line is a header
			if (!$seen_insertion) {
				$header_direct_after = true;
				$header_after = true;
				if (defined('DEBUG')) {
					echo ' [HEADER AFTER]';
				}
			} else {
				$header_before = true;
				if (defined('DEBUG')) {
					echo ' [HEADER BEFORE]';
				}
			}
		} else if (!$seen_insertion && trim($line) != '' && strpos($line, '+') !== 0) {
			$header_direct_after = false;
		}
		if (defined('DEBUG')) {
			echo "\n";
		}
	}
	if (defined('DEBUG')) {
		echo 'SEEN SIG: ' . ($seen_sig ? 'YES' : 'NO') . "\n";
		echo 'SEEN INSERTION: ' . ($seen_insertion ? 'YES' : 'NO') . "\n";
		echo 'HEADER BEFORE: ' . ($header_before ? 'YES' : 'NO') . "\n";
		echo 'HEADER AFTER: ' . ($header_after ? 'YES' : 'NO') . "\n";
		echo 'HEADER DIRECT AFTER: ' . ($header_direct_after ? 'YES' : 'NO') . "\n";
	}
	if (!$seen_sig && $seen_insertion && (($header_direct_after && $header_after && $header_before) || (!$header_before && !$header_after))) {
		return true;
	} else {
		return false;
	}
}

//check for missing categories
function checkMissingCategories($rc_json) {
	global $category_templates;
	static $already_seen_edits;
	if (!isset($already_seen_edits)) {
		$already_seen_edits = array();
	}
	
	foreach ($rc_json as $edit) {
		$type = (string)$edit->type;
		$id = (int)$edit->rcid;
		if (!in_array($id, $already_seen_edits)) {
			$namespace = (int)$edit->ns;
			$title = (string)$edit->title;
			$oldrevid = (string)$edit->old_revid;
			if ($type == 'log') {
				$logtype = (string)$edit->logtype;
			}
			//it's either an uploaded file or a new non-user page
			if (($type == 'new' && strpos($title, 'User') !== 0 && !stristr($title, 'talk:') && !stristr($title, 'mediawiki:')) || ($type == 'log' && $logtype == 'upload' && $oldrevid == 0)) {
				$timestamp = strtotime((string)$edit->timestamp);
				
				$user = (string)$edit->user;
				//check for category
				$has_category = checkForCategory($title, $category_templates);
				if (!$has_category && $timestamp > time() - 180) {
					//give the uploader three minutes to categorize
					echo '[INFO] Sleeping ' . (180 - (time() - $timestamp)) . ' seconds to wait for ' . $user . ' to add category on ' . $title . "\n";
					sleep(180 - (time() - $timestamp));
				}
				
				//see if the file has been moved and follow it
				$new_rc = apiQuery(array(
					'action' => 'query',
					'list' => 'recentchanges',
					'rcprop' => 'title|loginfo',
					'rclimit' => 150,
					'rcdir' => 'newer',
					'rcstart' => $timestamp
				))->query->recentchanges;
				foreach ($new_rc as $new_edit) {
					$new_type = (string)$new_edit->type;
					if ($new_type == 'log') {
						$new_logtype = (string)$new_edit->logaction;
						$old_title = (string)$new_edit->title;
						if ($new_logtype == 'move' && $old_title == $title) {
							$title = (string)$new_edit->logparams->target_title; //we moved the page, so follow the move
						}
					}
				}
				
				$has_category = $has_category || checkForCategory($title, $category_templates);
				if (!$has_category) {
					echo '[NOTIF] [UNCAT] ' . $user . ' did not include category on page ' . $title . "\n";
					if (strpos($title, 'File:') === 0 || strpos($title, 'Category:') === 0) {
						$title = ':' . $title;
					}
					notify_user($user, 'uncat', array('page' => $title));
				}
			}
			$already_seen_edits[] = $id;
		}
	}
}

//check if a page has a category or is otherwise exempt from having one
function checkForCategory($title, $category_templates) {
	$contents = get_page_contents($title);
	//if the page is a redirect, has a category, or has a category template, than it's good
	if (stristr($contents, '#REDIRECT')
		|| preg_match('%\[\[\W*Category:%i', $contents)
		|| preg_match('%\{\{\W*(' . implode('|', $category_templates) . ')%i', $contents)) {
		return true;
	} else {
		return false;
	}
}

function checkSandbox($rc_json, $SANDBOX_TIMEOUT, $DEFAULT_SANDBOX_TEXT) {
	static $timetoclearsandbox, $already_seen_edits;
	$dateformat = 'd M Y H:i:s';
	if (!isset($already_seen_edits)) {
		$already_seen_edits = array();
	}
	foreach ($rc_json as $edit) {
		$title = (string)$edit->title;
		$id = (int)$edit->rcid;
		if (!in_array($id, $already_seen_edits)) {
			$timestamp = strtotime((string)$edit->timestamp);
			if ($title == 'Scratch Wiki:Sandbox' && get_page_contents('Scratch Wiki:Sandbox') != $DEFAULT_SANDBOX_TEXT) {
				//since the RC are in reverse order, we need to make sure we only update the sandbox clear time if it's later
				$newtimetoclearsandbox = $timestamp + $SANDBOX_TIMEOUT * 60;
				if ($newtimetoclearsandbox > $timetoclearsandbox) {
					$timetoclearsandbox = $newtimetoclearsandbox;
					echo '[INFO] Scheduling sandbox clearing at ' . gmdate($dateformat, $timetoclearsandbox) . ' (UTC)' . "\n";
				}
			}
			$already_seen_edits[] = $id;
		}
	}
	if (isset($timetoclearsandbox) && $timetoclearsandbox <= time() && get_page_contents('Scratch Wiki:Sandbox') != $DEFAULT_SANDBOX_TEXT) {
		unset($timetoclearsandbox);
		echo '[EDIT] Clearing sandbox' . "\n";
		submit_edit('Scratch Wiki:Sandbox', $DEFAULT_SANDBOX_TEXT, 'Automatically clearing sandbox', true);
	}
}
