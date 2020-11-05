<?php
define('WIKI_API_URL', 'https://en.scratch-wiki.info/w/api.php');
define('DEBUG', 1);
include realpath(dirname(__FILE__) . '/..') . '/includes/wikifunctions.php';
include realpath(dirname(__FILE__) . '/..') . '/includes/genfunctions.php';

//format: 'oldid|newid' => false for signed/not new message, true for unsigned
$tests = array(
	'192139|192173' => false,
	'192137|192138' => false,
	'195404|195423' => false,
	'195528|195530' => false,
	'195689|195695' => true,
	'159306|195683' => false,
	'195637|195647' => false,
	'190522|194388' => true,
	'166513|166789' => false,
	'182669|182672' => true,
	'151911|152087' => false,
	'109871|195787' => true,
	'106820|106644' => true,
	'195787|195788' => true,
	'195788|195789' => true,
	'195943|195944' => true,
	'197721|197833' => false,
	'197736|197737' => false
);

foreach ($tests as $test => $expected) {
	echo "\033[1;44m" . 'Testing: ' . $test . "\033[0m" . "\n";
	$edit_json = apiQuery(array(
		'action' => 'query',
		'prop' => 'revisions',
		'revids' => $test,
		'rvprop' => 'content'
	), 'get', true);
	$key = key($edit_json['query']['pages']);
	$old = $edit_json['query']['pages'][$key]['revisions'][0]['*'];
	$new = $edit_json['query']['pages'][$key]['revisions'][1]['*'];
	echo 'Expecting ' . ($expected ? 'UNSIGNED' : 'SIGNED') . "\n";
	if (checkUnsignedDiff($old, $new) == $expected) {
		echo "\033[1;42m" . 'Result: [PASS]' . "\033[0m" . "\n";
	} else {
		$revids = explode('|', $test);
		echo "\033[1;41m" . 'Result: [FAIL]' . "\033[0m" . "\n";
		echo 'View edit at https://en.scratch-wiki.info/w/index.php?diff=' . $revids[1] . "\n";
		die;
	}
	echo "\n";
}