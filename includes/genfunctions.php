<?php
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