<?php
//THIS FILE IS NO LONGER USED. ALL FUNCTIONALITY IS NOW IN main-new.php.
define('WIKI_API_URL', 'https://wiki.scratch.mit.edu/w/api.php');
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
			$message = str_replace('($revid)', $info['revid'], str_replace('($page)', $info['page'], UNSIGNED_MESSAGE_BODY));
			echo date($dateformat, time()) . ' ' . $user . ' did not sign post (' . $info['page'] . ' revision ' . $info['revid'] . '), notifying...' . "\n";
			$subject = UNSIGNED_MESSAGE_SUBJECT;
			$summary = 'Unsigned post: Revision ' . $info['revid'] . ' of [[' . $info['page'] . ']]';
			$datasignature = 'nosign-' . $info['revid'];
			break;
		case 'excessive':
			$message = str_replace('($count)', $info['count'], str_replace('($page)', $info['page'], RAPID_MESSAGE_BODY));
			echo date($dateformat, time()) . ' Too many edits (' . $info['count'] . ') from ' . $user . ' on page ' . $info['page'] . ', notifying...' . "\n";
			if (stristr($info['page'], 'talk')) {
				echo 'Ignoring talk pages, skipping...' . "\n";
				return;
			}
			$subject = RAPID_MESSAGE_SUBJECT;
			$summary = 'Excessive editing on page: [[' . $info['page'] . ']]';
			$datasignature = 'rapid-' . $info['page'] . floor(time() / (60 * 60 * 24)) . '|' . 'rapid-' . $info['page'] . (floor(time() / (60 * 60 * 24)) + 1);
			break;
		case 'uncat':
			$message = str_replace('($page)', $info['page'], NOCAT_MESSAGE_BODY);
			echo date($dateformat, time()) . ' Uncategorized page: ' . $info['page'] . ' by ' . $user . ', notifying...' . "\n";
			$summary = 'No category on new page: [[' . $info['page'] . ']]';
			$subject = NOCAT_MESSAGE_SUBJECT;
			$datasignature = 'uncat-' . $info['page'];
			break;
	}
	//check if user was already notified
	$data = curl_get(WIKI_API_URL . '?action=query&prop=revisions&titles=User_talk:'.  rawurlencode($user) . '&rvlimit=50&rvprop=timestamp|user|comment&format=xml');
	$historyxml = new SimpleXMLElement($data);
	foreach ($historyxml->query->pages->page->revisions->rev as $rev) {
		if ((string)$rev->attributes()->user == $wikiusername && strstr((string)$rev->attributes()->comment, $datasignature)) {
			echo 'Already notified, skipping...' . "\n";
			return;
		}
	}
	$message = MESSAGE_PREFIX . $message . MESSAGE_SUFFIX; //piece it together
	if ($overridenobots) { //mention if user was nobots overridden
		$message .= '<br /><b>Important:</b> although your talk page has the <nowiki>{{NoBots}}</nowiki> template on it, an exception was added for your talk page to override it. See [[User:WikiMonitor#NoBots_override]] for details.';
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
		$tokenxml = new SimpleXMLElement(curl_post(WIKI_API_URL . '?action=query&prop=info|revisions&intoken=edit&titles=User_talk:' . rawurlencode($user) . '&format=xml', '', true)); //get token
		$edittoken = (string)$tokenxml->query->pages->page->attributes()->edittoken;

		$return = curl_post(WIKI_API_URL . '', 'action=edit&title=User_talk:' . $user . '&section=new&sectiontitle=' . $subject . '&summary=' . rawurlencode($summary . ' (' . $datasignature . ')') . '&text=' . rawurlencode($message) . '&format=xml&bot=true&token=' . rawurlencode($edittoken)); //submit the edit
	}
}

function curl_post($url, $postfields, $refuseblank = false) {
	$ch = curl_init ();
	curl_setopt ( $ch, CURLOPT_URL, $url);
	curl_setopt ( $ch, CURLOPT_FOLLOWLOCATION, 1 );
	curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt ( $ch, CURLOPT_POST, 1 );
	curl_setopt ( $ch, CURLOPT_POSTFIELDS, $postfields);
	curl_setopt ( $ch, CURLOPT_ENCODING, "" );
	curl_setopt ( $ch, CURLOPT_COOKIEFILE, getcwd () . '/cookies.txt' );
	curl_setopt ( $ch, CURLOPT_COOKIEJAR, getcwd () . '/cookies.txt' );
	curl_setopt ( $ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US; rv:1.9.2) Gecko/20100115 Firefox/3.6 (.NET CLR 3.5.30729)" );

	$out = '';
	if ($refuseblank) {
		while ($out == '') {
			$out = curl_exec ($ch);
		}
	} else {
		$out = curl_exec($ch);
	}
	curl_close($ch);
	return $out;
}

function curl_get($url, $refuseblank = false) {
	$ch = curl_init ();
	curl_setopt ( $ch, CURLOPT_URL, $url);
	curl_setopt ( $ch, CURLOPT_FOLLOWLOCATION, 1 );
	curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt ( $ch, CURLOPT_ENCODING, "" );
	curl_setopt ( $ch, CURLOPT_COOKIEFILE, getcwd () . '/cookies.txt' );
	curl_setopt ( $ch, CURLOPT_COOKIEJAR, getcwd () . '/cookies.txt' );
	curl_setopt ( $ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US; rv:1.9.2) Gecko/20100115 Firefox/3.6 (.NET CLR 3.5.30729)" );

	if (defined('SERVER_LOGIN')) { //my test server requires a login
		curl_setopt($ch, CURLOPT_USERPWD, SERVER_LOGIN);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	}
	$out = '';
	if ($refuseblank) {
		while ($out == '') {
			$out = curl_exec ($ch);
		}
	} else {
		$out = curl_exec($ch);
	}
	curl_close($ch);
	return $out;
}

function submit_edit($title, $contents, $summary, $minor = false) {
	$tokenxml = new SimpleXMLElement(curl_post(WIKI_API_URL . '?action=query&prop=info|revisions&intoken=edit&titles=' . rawurlencode($title) . '&format=xml', '')); //get token
	$edittoken = (string)$tokenxml->query->pages->page->attributes()->edittoken;
	$return = curl_post(WIKI_API_URL . '', 'action=edit&title=' . rawurlencode($title) . '&summary=' . $summary . '&text=' . rawurlencode($contents) . '&format=xml&bot=true' . ($minor = true ? '&minor=true' : '') . '&token=' . rawurlencode($edittoken)); //submit the edit
}

//define('anononly', 1); //uncomment to disable logging in
//log in
$alreadyseen = array();
$already_notified = array();
$logincount = 0;
include 'conf/logininfo.php';

function loadconfig() { //load online configuration
	global $category_templates;
	echo 'Loading config - this could take a while.' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', get_page_contents('User:WikiMonitor/Configuration/CategoryTemplates'), $matches); //templates that contain a category
	$category_templates = explode(',', $matches[1]);
	foreach ($category_templates as &$val) {
		$val = '{{' . $val;
	}
	echo 'Loaded config: category templates' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%ms', get_page_contents('User:WikiMonitor/Configuration/MessagePrefix'), $matches); //prefix to add to messages
	@define('MESSAGE_PREFIX', $matches[1]);
	echo 'Loaded config: message prefix' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%ms', get_page_contents('User:WikiMonitor/Configuration/MessageSuffix'), $matches); //suffix to add to messages
	@define('MESSAGE_SUFFIX', $matches[1]);
	echo 'Loaded config: message suffix' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', get_page_contents('User:WikiMonitor/Configuration/SandboxTimeout'), $matches); //time to wait before clearing sandbox
	@define('SANDBOX_TIMEOUT', $matches[1]);
	preg_match('%<code><pre><nowiki>(.*?)</nowiki></pre></code>%ms', get_page_contents('User:WikiMonitor/Configuration/DefaultSandbox'), $matches); //default sandbox text
	@define('DEFAULT_SANDBOX_TEXT', $matches[1]);
	echo 'Loaded config: default sandbox text' . "\n";

	preg_match('%<code><nowiki>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</nowiki></code>%ms', get_page_contents('User:WikiMonitor/Configuration/UnsignedMessage'), $matches); //unsigned post message
	@define('UNSIGNED_MESSAGE_SUBJECT', $matches[1]);
	@define('UNSIGNED_MESSAGE_BODY', $matches[2]);
	echo 'Loaded config: unsigned messages' . "\n";

	preg_match('%<code><nowiki>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</nowiki></code>%msi', get_page_contents('User:WikiMonitor/Configuration/NoCategoryMessage'), $matches); //no category message
	@define('NOCAT_MESSAGE_SUBJECT', $matches[1]);
	@define('NOCAT_MESSAGE_BODY', $matches[2]);
	echo 'Loaded config: no category messages' . "\n";

	preg_match('%<code><nowiki>\{Subj:(.*?)\}.*?\{Msg:(.*?)\}</nowiki></code>%ms', get_page_contents('User:WikiMonitor/Configuration/RapidEditMessage'), $matches); //rapid editing message
	@define('RAPID_MESSAGE_SUBJECT', $matches[1]);
	@define('RAPID_MESSAGE_BODY', $matches[2]);
	echo 'Loaded config: quick editing messages' . "\n";

	preg_match('%<code><nowiki>\{(.*?)\}</nowiki></code>%', get_page_contents('User:WikiMonitor/Configuration/TooManyEdits'), $matches); //too many edits
	$parts = explode(',', $matches[1]);
	@define('TOO_MANY_EDITS_COUNT', $parts[0]);
	@define('TOO_MANY_EDITS_TIME', $parts[1]);
	echo 'Loaded config: definition of too many edits' . "\n";
}

loadconfig();

$clear_sandbox_time = 0; //start out with no clearing sandbox
while (true) {
	if (!defined('anononly')) {
		//log in
		$out = curl_post(WIKI_API_URL . '', 'action=login&lgname='.  $wikiusername . '&lgpassword=' . $wikipassword . '&format=xml', true);
		$login_xml = new SimpleXMLElement($out);
		$token = (string)$login_xml->login->attributes()->token;

		$login_xml = new SimpleXMLElement(curl_post(WIKI_API_URL . '',  'action=login&lgname=' . $wikiusername . '&lgpassword='.  $wikipassword . '&lgtoken=' . $token . '&format=xml'));
		if ((string)$login_xml->login->attributes()->result != 'Success') {
			echo 'Login failed!'. "\n";
			switch ((string)$login_xml->login->attributes()->result) {
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

	for ($cyclecount = 1; $cyclecount <= 30; $cyclecount++) { //every 30 cycles, log back in
		echo 'Starting cycle at ' . date('d M Y H:i:s') . '...' . "\n";
		$shutoffpage = get_page_contents('User:WikiMonitor/Disable'); //check for automatic shutoff
		if (!strstr($shutoffpage, '<div id="botenabled" style="font-weight:bold">true</div>')) {
			preg_match('%\(This page was last edited by (.*?)\)%', curl_get('http://wiki.scratch.mit.edu/wiki/User:WikiMonitor/Disable'), $matches);
			echo 'This bot has been disabled by ' . $matches[1] . "\n"; die;
		}

		$recentchangesfullxml = new SimpleXMLElement(curl_get(WIKI_API_URL . '?action=query&list=recentchanges&rcprop=title|ids|sizes|flags|user|timestamp&rclimit=150&format=xml&salt=' . time(), true)); //get recent changes list
		$recentchangesxml = $recentchangesfullxml->query->recentchanges->rc;
		$editcounts = array();
		$lastedits = array();
		$pages = array();
		foreach ($recentchangesxml as $val) {
			//check for excessive edits, defined as 5+ in 30 minutes
			if (strtotime((string)$val->attributes()->timestamp) > time() - (60 * TOO_MANY_EDITS_TIME)) {
				$pageid = (int)$val->attributes()->pageid;
				if ($pageid != 0) {
					if (isset($editcounts[$pageid])) {
						$editcounts[$pageid]++;
					} else {
						$editcounts[$pageid] = 1;
						$pages[$pageid] = (string)$val->attributes()->title;
					}
				}
			}
		}
		asort($editcounts);
		foreach ($editcounts as $pageid => $val) {
			if ($val >= TOO_MANY_EDITS_COUNT) {
				$edited_users = array();
				//we've had over the required amount of edits, let's see who caused them
				foreach ($recentchangesxml as $change) {
					if ((int)$change->attributes()->pageid == $pageid) {
						$user = (string)$change->attributes()->user;
						if (isset($edited_users[$user])) {
							$edited_users[$user]++;
						} else {
							$edited_users[$user] = 1;
						}
					}
				}
				foreach ($edited_users as $user => $count) {
					if ($count >= TOO_MANY_EDITS_COUNT) {
						//too many edits - notify the user
						if (!in_array($user . $pageid, $already_notified)) {
							$page = $pages[$pageid];
							if (strpos($page, 'File:') === 0 || strpos($page, 'Category:') === 0) {
								$page = ':' . $page;
							}
							//check that this page isn't on the rapid ignore list
							$send = true;
							$rapidignorepagecontents = get_page_contents('User:WikiMonitor/Configuration/PublicRapidIgnoreList');
							preg_match('%<\!-- liststart -->(.*?)<\!-- listend -->%ms', $rapidignorepagecontents, $rapidignorelistmatches);
							$rapidignorelist = explode("\n", str_replace("\r", "\n", $rapidignorelistmatches[1]));
							unset($rapidignorelistmatches, $rapidignorepagecontents);
							foreach ($rapidignorelist as $pagetoignore) {
								if ($pagetoignore == $page) {
									if (!stristr($pagetoignore, 'user:')) {
										echo 'Ignoring rapid notification to ' . $user . ' for page ' . $page . "\n";
										$send = false;
									}
								}
							}
							unset($rapidignorelist);
							if ($send) {
								notify_user($user, 'excessive', array('count' => $count, 'page' => $page));
							}
							$already_notified[] = $user . $pageid;
						}
					}
				}
			}
		}

		$seensandbox = false;
		foreach ($recentchangesxml as $val) {
			$id = (string)$val->attributes()->revid;
			if (!in_array($id, $alreadyseen)) {
				$alreadyseen[] = $id;
				$title = (string)$val->attributes()->title;

				//did it involve editing configuration
				if (strpos((string)$val->attributes()->title, 'User:WikiMonitor/Configuration') === 0) {
					echo 'Reloading configuration...' . "\n";
					loadconfig();
				}

				//check if it's the sandbox
				if ((string)$val->attributes()->title == 'Scratch Wiki:Sandbox' && !$seensandbox) {
					$seensandbox = true;
					if ($val->attributes()->user != $wikiusername) {
						$clear_sandbox_time = strtotime((string)$val->attributes()->timestamp) + (SANDBOX_TIMEOUT * 60);
						echo 'Clearing sandbox at ' . date('d M Y H:i:s', $clear_sandbox_time) . "\n";
					}
				}

				//check if user signed post
				//ignore minor edits
				if ($val->attributes()->type != 'new' && !isset($val->attributes()->minor) && ((int)$val->attributes()->newlen - (int)$val->attributes()->oldlen) > 15 && stristr($title, 'talk:')) {
					//it's a talk page! did the user sign their post?
					$page_contents = get_page_contents($title); //make sure that other people have signed posts on it
					if (stristr($page_contents, '<scratchsig>')) {
						$rev_xml = new SimpleXMLElement(curl_get(WIKI_API_URL . '?action=query&prop=revisions&titles=' . rawurlencode($title) . '&rvlimit=1&rvprop=timestamp|user|comment&rvstartid=' . $id . '&rvdiffto=prev&rvlimit=1&format=xml', true));
						//print_r($rev_xml->query); die;
						$diff = (string)$rev_xml->query->pages->page->revisions->rev->diff;
						$comment = (string)$rev_xml->query->pages->page->revisions->rev->attributes()->comment;
						preg_match_all('%<tr>(.*?)</tr>%', $diff, $matches);
						$ok = true;
						/*foreach ($matches[1] as $diffline) {
							if (stristr($diffline, '<ins class="diffchange">:') || stristr($diffline, '<ins class="diffchange">{{outdent|') || strstr($comment, 'new section')) {
								$ok = false;
							}
							if (stristr($diffline, '(UTC)') || stristr($diffline, '(GMT)') || stristr($diffline, '<scratchsig>') || stristr($diffline, '/sig')) {
								$ok = true;
								break;
							}
						}*/
						$ok = false;
						$diff = array();
						foreach ($matches[1] as $diffline) {
							if (preg_match('%<td class=\'diff-marker\'>(&#160;|\+|-)</td><td class=\'diff-(addedline|context)\'><div>(.*?)</div></td>%', $diffline, $diffmatches)) {
								//[2] tells you add/remove/context, [3] is the content of the line
								preg_match_all('%<ins class=".*?">(.*?)</ins>%', $diffmatches[3], $signmatches);
								foreach ($signmatches[1] as $match) {
									if (stristr($match, 'scratchsig') || stristr($match, '(UTC)')) {
										$ok = true; break;
									}
								}
								$diff[] = array('type' => $diffmatches[2], 'content' => $diffmatches[3]);
							}
						}
						if (!$ok) {
							$linesbeforeaddition = 0;
							$endofsection = false;
							for ($i = sizeof($diff) - 1; $i >= 0; $i--) {
								$part = $diff[$i];
								if ($part['type'] == 'context') {
									$linesbeforeaddition++;
									if (strstr($part['content'], '==')) {
										$endofsection = true;
									} else if (trim($part['content']) == '') {
									} else {
										$endofsection = false;
									}
								} else if ($part['type'] == 'addedline') {
									if (!preg_match('%</ins>$%', $part['content'])) {
										$endofsection = false;
										$linesbeforeaddition = 1;
									}
									break;
								}
							}
							if (!$endofsection && $linesbeforeaddition > 0) {
								$ok = true;
							}
						}
						unset($diff);
						if (!$ok) {
							//no signature, check if the user fixed it, but make sure 3 minutes have elapsed
							$ignore = false;
							$origedittime = strtotime((string)$val->attributes()->timestamp);
							if ($origedittime > time() - 180) {
								echo 'Sleeping ' . (180 - (time() - $origedittime)) . ' seconds...' . "\n";
								sleep(180 - (time() - $origedittime));
							}
							$recentchangesfullxml2 = new SimpleXMLElement(curl_get(WIKI_API_URL . '?action=query&list=recentchanges&rcprop=title|ids|sizes|flags|user|timestamp&rclimit=150&format=xml&salt=' . time(), true)); //get recent changes list
							$recentchangesxml2 = $recentchangesfullxml2->query->recentchanges->rc;
							foreach ($recentchangesxml2 as $change) { //TODO: make sure the user actually added the sig
								$edittime = strtotime((string)$change->attributes()->timestamp);
								if ($edittime <= $origedittime) {
									//already passed the edit in question, so it wasn't fixed
									break;
								}
								if ((string)$change->attributes()->title == $title && (string)$change->attributes()->user == (string)$val->attributes()->user) {
									//the user fixed it in a subsequent edit, so don't notify them
									$ignore = true;
									echo 'Ignoring because it was fixed later (' . $title . ', ' . $val->attributes()->user . ')' . "\n";
									break;
								}
							}

							if (!$ignore) { //user did not fix it
								notify_user($val->attributes()->user, 'sign', array('revid' => $id, 'page' => $title));
							}
						}
					}
				}

				//check for uncategorized new pages
				if ($val->attributes()->type == 'new' && !stristr($title, 'talk:') && !stristr($title, 'user:') && !stristr($title, 'file:') && !stristr($title, 'mediawiki:')) {
					$contents = get_page_contents($title);
					if (!stristr($contents, '[[Category:') && $contents != '' && !stristr($contents, '#redirect')) {
						$ok = false;
						foreach ($category_templates as $template) {
							if (stristr($contents, '{{' . $template)) {
								$ok = true;
								break;
							}
						}
						if (!$ok) {
							if (stristr($title, 'category:')) {
								$title = ':' . $title;
							}
							//uncategorized
							notify_user($val->attributes()->user, 'uncat', array('page' => $title));
						}
					}
				}
			}
		}

		//should we clear the sandbox?
		if ($clear_sandbox_time != 0 && time() > $clear_sandbox_time) {
			if (trim(get_page_contents('Scratch Wiki:Sandbox')) != trim(DEFAULT_SANDBOX_TEXT)) {
				echo 'Clearing sandbox!' . "\n";
				submit_edit('Scratch Wiki:Sandbox', DEFAULT_SANDBOX_TEXT, 'Cleared the sandbox', false);
			}
			$clear_sandbox_time = 0;
		}

		//check for uncategorized new files
		$uploadlogxml = new SimpleXMLElement(curl_get(WIKI_API_URL . '?action=query&list=logevents&letype=upload&lelimit=20&format=xml', true)); //check upload log
		$deletelogxml = new SimpleXMLElement(curl_get(WIKI_API_URL . '?action=query&list=logevents&letype=delete&lelimit=500&format=xml', true)); //let's also get the delete log
		$movelogxml = new SimpleXMLElement(curl_get(WIKI_API_URL . '?action=query&list=logevents&letype=move&lelimit=50&format=xml', true)); //don't forget the move log
		foreach ($uploadlogxml->query->logevents->item as $item) {
			$id = (string)$item->attributes()->logid;
			if (!in_array($id, $alreadyseen)) {
				$alreadyseen[] = $id;
				if ((string)$item->attributes()->action == 'upload') { //it's a new file
					$time = strtotime((string)$item->attributes()->timestamp);
					if ($time > time() - 180) {
						echo 'Sleeping ' . (180 - (time() - $time)) . ' seconds...' . "\n";
						sleep(180 - (time() - $time));
					}
					$contents = get_page_contents((string)$item->attributes()->title) . "\n";
					if (!stristr($contents, '[[Category:')) {
						//uncategorized
						$notify = true;
						//check for category templates
						foreach ($category_templates as $template) {
							if (stristr($contents, '{{' . $template)) {
								$notify = false;
								break;
							}
						}
						//check the delete log
						foreach ($deletelogxml->query->logevents->item as $delete_item_xml) {
							if ((string)$item->attributes()->title == (string)$delete_item_xml->attributes()->title) {
								$notify = false;
								break;
							}
						}
						foreach ($movelogxml->query->logevents->item as $move_item_xml) {
							if ((string)$item->attributes()->title == (string)$move_item_xml->attributes()->title) {
								$notify = false;
								break;
							}
						}
						if ($notify) {
							notify_user((string)$item->attributes()->user, 'uncat', array('page' => ':' . (string)$item->attributes()->title));
						}
					}
				}
			}
		}

		sleep(90);
	}
}
