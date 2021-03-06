#!/usr/bin/php
<?php

/**
 * Decode html entities
 */
function decode($s) {
	return html_entity_decode($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Do custom markup, like eventum issue link -> issue markup
 */
function markup($s) {
	// decode html entities first
	$s = decode($s);

	// use issue markup
	$url = EVENTUM_URL;
	$s = preg_replace("#\Q$url\E/view.php\?id=(\d+)#", '[[issue>$1]]', $s);

	// wiki page markup
	$url = DOKUWIKI_URL;
	$s = preg_replace("#\Q$url\E/(\S+)\b#", '[[$1]]', $s);

	// do not allow newlines, use wiki markup
	$s = preg_replace("/\r?\n/", ' \\\\\\\\ ', $s);
	return $s;
}

/**
 * @see https://stackoverflow.com/a/10590242
 */
function get_headers_from_curl_response($header_text) {
	$headers = array();
	foreach (explode("\r\n", $header_text) as $i => $line) {
		if ($i === 0) {
			$headers['http_code'] = $line;
		} else {
			list($key, $value) = explode(': ', $line);
			$headers[$key] = $value;
		}
	}

	return $headers;
}

function slack_api($method, $params = array()) {
	printf("slack: %s %s\n", $method, json_encode($params));
	$params['token'] = SLACK_TOKEN;
	$url = "https://slack.com/api/{$method}?" . http_build_query($params, null, '&');

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	$res = curl_exec($ch);
	curl_close($ch);
	if (!$res) {
		error_log('No response!');
		exit(1);
	}
	list($headers_text, $body) = explode("\r\n\r\n", $res, 2);
	$data = json_decode($body);
	if (!$data->ok || $data->ok !== true) {
		$headers = get_headers_from_curl_response($headers_text);
		if (isset($headers['Retry-After'])) {
			$retryAfter = (int)$headers['Retry-After'];
			echo "Rate limited; retrying after $retryAfter\n";
			sleep($retryAfter);
			return slack_api($method, $params);
		}

		error_log("Response from API NOT OK: $body");
		print_r($headers);
		exit(1);
	}

	return $data;
}

/**
 * Fetch channels list from slack api
 */
function get_channels_list() {
	$data = slack_api('channels.list');
	if (!$data->channels || !count($data->channels)) {
		error_log('No channels in response!' . var_export($data, 1));
		exit(1);
	}
	return $data;
}


/**
 * Format channels list into dokuwiki syntax
 */
function format_channels_list($data) {
	$output = array();

	$tr = function ($data, $separator = '|') {
		array_unshift($data, '');
		$data[] = '';
		return implode($separator, $data);
	};

	$headers = array('Channel Name', 'Channel Purpose', 'Channel Topic', 'First Message');

	$output[] = "====== Slack Channels ======\n";
	$output[] = sprintf("\n/* this page is generated by %s, changes will be lost */\n\n", __FILE__);;

	$active = $inactive = array();
	$team_url = SLACK_TEAM_URL;
	foreach ($data->channels as $channel) {
		$topic = markup($channel->topic->value);

		$history = slack_api('channels.history', array('channel' => $channel->id, 'oldest' => 1, 'count' => 1));
		$first_msg = strftime('%Y-%m-%d', $history->messages[0]->ts);

		$row = array(
			"[[{$team_url}/messages/{$channel->name}|#{$channel->name}]]",
			markup($channel->purpose->value),
			$topic,
			$first_msg,
		);

		if ($channel->is_archived) {
			$history = slack_api('channels.history', array('channel' => $channel->id, 'count' => 1));
			$last_msg = strftime('%Y-%m-%d', $history->messages[0]->ts);
			$row[] = $last_msg;
			$inactive[] = $tr($row);
		} else {
			$active[] = $tr($row);
		}
	}

	if ($active) {
		$output[] = "\n====== Active Channels ======\n";
		$output[] = $tr($headers, '^');
		$output[] = implode("\n", $active);
	}

	if ($inactive) {
		$output[] = "\n====== Archived Channels ======\n";
		$headers[] = 'Last Message';
		$output[] = $tr($headers, '^');
		$output[] = implode("\n", $inactive);
	}

	return implode("\n", $output);
}

/**
 * commit $contents to $page
 */
function dw_commit($page, $contents, $message) {
	$tmpfile = tempnam(sys_get_temp_dir(), 'sl2dw');
	file_put_contents($tmpfile, $contents);

	$dwpage = DWPAGE;
	exec("{$dwpage} commit -m '{$message}' -t {$tmpfile} {$page} 2>&1 | grep -v ^S:");
	unlink($tmpfile);
}

function load_config($configfile) {
	$config = parse_ini_file($configfile);
	foreach ($config as $const => $value) {
		define($const, $value);
	}
}

function main($arguments) {
	if (!$arguments) {
		throw new InvalidArgumentException("No config given on commandline");
	}
	load_config($arguments[0]);

	$data = get_channels_list();
	$contents = format_channels_list($data);
	dw_commit(DOKUWIKI_PAGE, $contents, "updated slack channels info");
}

define('PROGRAM', array_shift($argv));
main($argv);
