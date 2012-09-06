<?php

if(isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
	$user = $_SERVER['PHP_AUTH_USER'];
	$pass = $_SERVER['PHP_AUTH_PW'];
	if (isset($allowed_users[$user]) && $allowed_users[$user] == $pass) {
		// all good!
	} else {
		auth();
	}
} else {
	// check hostname
	if (isset($allowed_hosts)) {
		if (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], $allowed_hosts)) {
			// 
		} else {
			auth();
		}
	} else {
		auth();
	}
	
}

function auth() {
	$realm = 'Metlink';
	header('WWW-Authenticate: Basic realm="' . $realm . '"', true, 401);
	exit();
}