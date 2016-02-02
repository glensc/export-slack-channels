#!/usr/bin/php
<?php
$tok = getenv('slack_api_token');
$url = "https://slack.com/api/channels.list?token=$tok";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$res = curl_exec($ch);
curl_close($ch);
if (!$res){
	error_log('No response!');
	exit(1);
}
$data = json_decode($res);
if(!$data->ok || $data->ok != true) {
	error_log('Response from API NOT OK: '. var_export($res,1));
	exit(1);
}
if(!$data->channels || !count($data->channels)){
	error_log('No channels in response!'. var_export($res,1));
	exit(1);
}

$fmt = ('|%-90s|%-150s|%-52s|' . PHP_EOL);
printf($fmt, 'Channel Name', 'Channel Purpose', 'Channel Topic');
foreach($data->channels as $chan) {
	printf($fmt,
	'[[https://team.slack.com/messages/' . $chan->name . '|#' . $chan->name . ']]',
	$chan->purpose->value,
	$chan->topic->value);
}
