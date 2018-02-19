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
loadconfig();

$clear_sandbox_time = 0; //start out with no clearing sandbox
while (true) {
	if (!defined('anononly')) {
		login($wikiusername, $wikipassword);
	}

	for ($cyclecount = 1; $cyclecount <= 10; $cyclecount++) { //every 10 cycles, log back in
		echo 'Starting cycle at ' . date('d M Y H:i:s') . '...' . "\n";
		checkshutoff();

		$recentchangesfullxml = new SimpleXMLElement(curl_get(WIKI_API_URL . '?action=query&list=recentchanges&rcprop=title|ids|sizes|flags|user|timestamp&rclimit=150&format=xml&salt=' . time(), true)); //get recent changes list
		$recentchangesxml = $recentchangesfullxml->query->recentchanges->rc;
		$editcounts = array();
		$lastedits = array();
		$pages = array();
		foreach ($recentchangesxml as $val) {
			//check for excessive edits, defined as 5+ in 30 minutes
			if (strtotime((string)$val->attributes()->timestamp) > time() - (60 * $TOO_MANY_EDITS_TIME)) {
				$pageid = (int)$val->attributes()->pageid;
				if ($pageid != 0) {
					if (!isset($editcounts[$pageid])) {
						$editcounts[$pageid] = array();
						$pages[$pageid] = (string)$val->attributes()->title;
					}
					$user = (string)$val->attributes()->user;
					if (!isset($editcounts[$pageid][$user])) {
						$editcounts[$pageid][$user] = 0;
					}
					$editcounts[$pageid][$user]++;
				}
			}
		}
		asort($editcounts);
		foreach ($editcounts as $pageid => $users) {
			foreach ($users as $user => $count) {
				if ($count >= $TOO_MANY_EDITS_COUNT) {
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

		$seensandbox = false;
		foreach ($recentchangesxml as $val) {
			$id = (string)$val->attributes()->revid;
			if (!in_array($id, $alreadyseen)) {
				$alreadyseen[] = $id;
				$title = (string)$val->attributes()->title;

				//did it involve editing configuration
				if (strpos((string)$val->attributes()->title, CONFIG_LOCATION) === 0) {
					$time = strtotime((string)$val->attributes()->timestamp);
					if ($time > $lastreloadedconfig) {
						$lastreloadedconfig = time();
						echo 'Reloading configuration...' . "\n";
						loadconfig();
					}
				}

				//check if it's the sandbox
				if ((string)$val->attributes()->title == 'Scratch Wiki:Sandbox' && !$seensandbox) {
					$seensandbox = true;
					if ($val->attributes()->user != $wikiusername) {
						$clear_sandbox_time = strtotime((string)$val->attributes()->timestamp) + ($SANDBOX_TIMEOUT * 60);
						echo 'Clearing sandbox at ' . date('d M Y H:i:s', $clear_sandbox_time) . "\n";
					}
				}

				//check if user signed post
				if ($val->attributes()->type != 'new' && !isset($val->attributes()->minor) && ((int)$val->attributes()->newlen - (int)$val->attributes()->oldlen) > 15 && stristr($title, 'talk:')) {
					//it's a talk page! did the user sign their post?
					$page_contents = get_page_contents($title); //make sure that other people have signed posts on it
					if (preg_match('%==(.*?)==%', $page_contents, $matches)) {
						$first_heading = $matches[0];
					} else {
						$first_heading = '';
					}
					if (stristr($page_contents, '<scratchsig>')) {
						$rev_xml = new SimpleXMLElement(curl_get(WIKI_API_URL . '?format=xml&action=query&titles=' . rawurlencode($title) . '&prop=revisions&rvdiffto=prev&rvstartid=' . $id . '&rvendid=' . $id));
						$diff = (string)$rev_xml->query->pages->page->revisions->rev->diff;
						$comment = (string)$rev_xml->query->pages->page->revisions->rev->attributes()->comment;
						preg_match_all('%<tr>(.*?)</tr>%', $diff, $matches);
						$ok = false;
						$diff = array();
						foreach ($matches[1] as $diffline) {
							if (preg_match('%<td class=\'diff-marker\'>(&#160;|\+|-)</td><td class=\'diff-(addedline|context)\'><div>(.*?)</div></td>%', $diffline, $diffmatches)) {
								//[2] tells you add/remove/context, [3] is the content of the line
								preg_match_all('%<ins class=".*?">(.*?)</ins>%', $diffmatches[3], $signmatches);
								foreach ($signmatches[1] as $match) {
									if (stristr($match, 'scratchsig') || stristr($match, '(UTC)') || stristr($match, '(CET)')) {
										$ok = true; break;
									}
								}
								$diff[] = array('type' => $diffmatches[2], 'content' => $diffmatches[3]);
							}
						}
						if (!$ok) {
							$newsectionafter = true;
							$lastheading = false;
							$after = true;
							$before = false;
							for ($i = sizeof($diff) - 1; $i >= 0; $i--) {
								$line = $diff[$i];
								if ($line['type'] == 'context') {
									if ($after) {
										if (strpos(trim($line['content']), '==') === 0) {
											$newsectionafter = true;
											$lastheading = $line['content'];
										} else if (trim($line['content']) != '') {
											$newsectionafter = false;
										}
									} else {
										$before = true;
									}
								} else {
									$after = false;
								}
							}
							if (!$newsectionafter || $lastheading === $first_heading) {
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
								//sleep(180 - (time() - $origedittime));
							}
							$recentchangesfullxml2 = new SimpleXMLElement(curl_get(WIKI_API_URL . '?action=query&list=recentchanges&rcprop=title|ids|sizes|flags|user|timestamp&rclimit=150&format=xml&salt=' . time(), true)); //get recent changes list
							$recentchangesxml2 = $recentchangesfullxml2->query->recentchanges->rc;
							foreach ($recentchangesxml2 as $change) {
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
			if (trim(get_page_contents('Scratch Wiki:Sandbox')) != trim($DEFAULT_SANDBOX_TEXT)) {
				echo 'Clearing sandbox!' . "\n";
				submit_edit('Scratch Wiki:Sandbox', $DEFAULT_SANDBOX_TEXT, 'Cleared the sandbox', false);
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
