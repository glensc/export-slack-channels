#!/usr/bin/php
<?php
$tok = 'TOKEN';
$url = "https://slack.com/api/channels.list?token=$tok";
$dwpage = '/usr/share/dokuwiki/bin/dwpage.php';
$page = 'it:slack_channels';

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

ob_start();
$fmt = ('|%-90s|%-150s|%-52s|' . PHP_EOL);
$header = sprintf($fmt, 'Channel Name', 'Channel Purpose', 'Channel Topic');
$header = str_replace("|", "^", $header);
echo $header;

foreach($data->channels as $chan) {
	printf($fmt,
	'[[https://team.slack.com/messages/' . $chan->name . '|#' . $chan->name . ']]',
	$chan->purpose->value,
	$chan->topic->value);
}
$contents = ob_get_clean();

# commit to wiki
$tmpfile = tempnam(sys_get_temp_dir(), 'sl2dw');
file_put_contents($tmpfile, $contents);

$message = "updated slack channels info";
exec("{$dwpage} commit -m '{$message}' -t {$tmpfile} {$page} 2>&1 | grep -v ^S:");
unlink($tmpfile);
