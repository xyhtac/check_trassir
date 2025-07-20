#!/usr/bin/php
<?php

/*

Icinga Plugin Script (Check Command). Check Trassir CCTV server health and channel (camera) archive via Trassir API.
https://github.com/xyhtac/check_trassir/
Max.Fischer <dev@monologic.ru>
Tested with Debian GNU/Linux 12 (bookworm) with Icinga v2.14.6

LICENSE:
This repository is distributed under the Apache License 2.0. See the LICENSE file for full terms and conditions.

DISCLAIMER NOTICE:
This plugin is developed independently and is based on the publicly available Trassir API SDK, as documented at:
https://trassir.com/software-updates/manual/sdk.html

Please be aware of the following:
 - The Trassir API SDK is subject to change without prior notice by its maintainers. This may impact the compatibility or functionality of this plugin in the future.
 - This project is not affiliated with, endorsed by, or officially supported by DSSL or Trassir.
 - "Trassir" and its associated logos and trademarks are the intellectual property of DSSL (https://www.dssl.ru/). All rights to those names and marks are reserved by their respective owners.
 - The authors of this plugin make no guarantees regarding its fitness for any particular purpose and are not liable for any damages resulting from its use.


INSTALLATION:
Plugin is supposed to be placed in Nagios plugins directory, i.e.:
/usr/lib/nagios/plugins/check_trassir.php - CHMOD 755

DEPENDENCIES:
This plugin is written in PHP and requires the following components to function correctly:
- PHP 5.3+ (Compatible with legacy environments; tested with 5.3 and newer)
- cURL extension for PHP (Used to communicate with the Trassir API over HTTPS)
- OpenSSL enabled in PHP (Required for secure HTTPS connections)

For Debian/Ubuntu systems:
> sudo apt update
> sudo apt install -y php php-curl php-openssl

For RHEL/CentOS systems:
> sudo yum install -y php php-curl openssl


TEST RUN:
Check channel example:
./check_trassir.php --host 10.0.1.1 --port 8080 --username username --password secret_password --channel Camera-1 --hours 8 --timezone 3

Check server example:
./check_trassir.php --host 10.0.1.1 --port 8080 --username username --password secret_password

Mode selection is made by setting '--channel' variable. If undefined, the checker runs in server health mode.


ICINGA CONFIG DEFINITIONS:
copy/paste following definitions yo your services.conf and commands.conf (recommended) or put conf.d/trassir-checker.conf to your conf.d
restart of icinga2 daemon is required: 
> systemctl restart icinga2

// Trassir archive channel checker.
//===========================
apply Service "check-trassir-archive" {
  import "generic-service"
  check_interval = 30m
  retry_interval = 3m
  check_timeout = 1m
  // Get the upstream server by name (from the IoT device var 'server')
  vars.server_host = get_host(host.vars.server)
  // Shared server-side trassir vars
  vars.host = vars.server_host.address
  vars.port = vars.server_host.vars.trassir["port"]
  vars.timezone = vars.server_host.vars.trassir["timezone"]
  vars.username = vars.server_host.vars.trassir["username"]
  vars.password = vars.server_host.vars.trassir["password"]
  vars.trassir_archive_checker = true
  // IoT-specific trassir vars
  vars.channel = host.vars.trassir["channel"]
  // Use default 24 hours if not defined on IoT device
  vars.hours = ""
  if (host.vars.trassir["hours"] != null && host.vars.trassir["hours"] != "") {
	vars.hours = host.vars.trassir["hours"]
  } else {
	vars.hours = "24"
  }
  enable_perfdata = true
  check_command = "check_trassir"
  assign where host.vars.trassir["channel"] && host.vars.server
}
apply Dependency "mute-check-trassir-archive-if-server-down" to Service {
  disable_checks = true
  disable_notifications = true
  parent_host_name = host.vars.server
  assign where host.vars.trassir["channel"] && host.vars.server
}

// Trassir Server Checker
// =======================
apply Service "check-trassir-server" {
  import "generic-service"
  check_interval = 10m
  retry_interval = 3m
  check_timeout = 1m
  vars.host = host.address
  vars.port = host.vars.trassir["port"]
  vars.timezone = host.vars.trassir["timezone"]
  vars.username = host.vars.trassir["username"]
  vars.password = host.vars.trassir["password"]
  vars.trassir_server_checker = true
  enable_perfdata = true
  check_command = "check_trassir"
  assign where host.vars.trassir["username"] && host.vars.trassir["password"]
}

// Command definition
// =======================
object CheckCommand "check_trassir" {
  import "plugin-check-command"
  command = [ PluginDir + "/check_trassir.php" ]
  arguments = {
    "--hours" = "$hours$"
    "--host" = "$host$"
    "--port" = "$port$"
    "--username" = "$username$"
    "--password" = "$password$"
    "--channel" = "$channel$"
    "--timezone" = "$timezone$"
    "--delay" = "$delay$"
  }
  vars.enable_perfdata = true
}


*/

$cache_dir = "/var/tmp/check_trassir/";		// location of local cache
$cache_life = 12;							// api cache lifetime in hours
$density_hours = 1;							// hour threshold for calculation of motion density metrics
$retry_count = 10;							// limit of calls for archive seek

$severity_map = [
    0 => 'OK',
    1 => 'WARNING',
    2 => 'CRITICAL'
];

// ============================================================================
// Server Health Check Settings Definition
// ============================================================================
// This associative array defines the list of health parameters to be checked
// via the Trassir server API. Each entry controls how a specific setting is
// evaluated, reported, and if applicable, included as performance data.
//
// Key: Setting name (used in /settings/health/[key]?sid=... API request)
//
// Value: An array of properties:
// - description (string) : Human-readable explanation of the setting.
// - severity    (int)    : Defines the alert level when a problem is detected:
//                          0 = OK (informational only),
//                          1 = WARNING,
//                          2 = CRITICAL
// - invert      (bool)   : If true, inverts the meaning of the value:
//                          For boolean: 0 = OK, 1 = Problem  -> becomes 0 = Problem, 1 = OK
//                          For int: use "< threshold" as problem instead of ">="
// - disable     (bool)   : If true, skip evaluation of this setting.
// - type        (string) : 'bool' (default) or 'int' to define evaluation logic.
// - threshold   (int|null): Required for 'int' type. Triggers alert when value
//                          exceeds (or falls below if invert=true) this number.
//
// Notes:
// - Boolean parameters only produce output when in non-OK state.
// - Integer parameters always output value and status, and include perfdata
//   if a threshold is defined.
//
// ============================================================================


$settings = array(
    'amerge_error' => array(
        'description' => 'There is an archive synchronization error on server',
        'severity'    => 1, 
        'invert'      => false,
        'disable'     => false,
        'type'        => 'bool',
        'threshold'   => null
    ),
    'channels_bitrate_exceeded' => array(
        'description' => 'Bitrate exceeded on channel',
        'severity'    => 2, 
        'invert'      => false,
        'disable'     => false,
        'type'        => 'bool',
        'threshold'   => null
    ),
    'channels_detector_error' => array(
        'description' => 'Server has channels with detector errors',
        'severity'    => 2, 
        'invert'      => false,
        'disable'     => false,
        'type'        => 'bool',
        'threshold'   => null
    ),
    'channels_detector_warning' => array(
        'description' => 'Server has channels with detector warnings',
        'severity'    => 1, 
        'invert'      => false,
        'disable'     => false,
        'type'        => 'bool',
        'threshold'   => null
    ),
    'db_connected' => array(
        'description' => 'Database disconnected',
        'severity'    => 2, 
        'invert'      => true,  
        'disable'     => false,
        'type'        => 'bool',
        'threshold'   => null
    ),
    'db_is_slow' => array(
        'description' => 'Databases work slowly',
        'severity'    => 1,
        'invert'      => false,
        'disable'     => false,
        'type'        => 'bool',
        'threshold'   => null
    ),
    'disks_error_count' => array(
        'description' => 'Disks have errors',
        'severity'    => 2,
        'invert'      => false,
        'disable'     => false,
        'type'        => 'bool',
        'threshold'   => 0
    ),
    'disks_is_slow' => array(
        'description' => 'Disks work slowly',
        'severity'    => 1,
        'invert'      => false,
        'disable'     => false,
        'type'        => 'bool',
        'threshold'   => null
    ),
    'plugins_ok' => array(
        'description' => 'Plugins have errors',
        'severity'    => 1, 
        'invert'      => true,
        'disable'     => true,
        'type'        => 'bool',
        'threshold'   => null
    ),
    'scripts_ok' => array(
        'description' => 'Scripts have errors',
        'severity'    => 1, 
        'invert'      => true,
        'disable'     => true,
        'type'        => 'bool',
        'threshold'   => null
    ),
	'cpu_usage' => array(
        'description' => 'CPU load, %',
        'severity'    => 1, 
        'invert'      => false,
        'disable'     => false,
        'type'        => 'int',
        'threshold'   => 85
    ),
	'gpu_usage' => array(
        'description' => 'GPU load, %',
        'severity'    => 1,
        'invert'      => false,
        'disable'     => true,
        'type'        => 'int',
        'threshold'   => 85
    ),
	'channels_network_online' => array(
        'description' => 'Network channels online',
        'severity'    => 0, 
        'invert'      => false,
        'disable'     => false,
        'type'        => 'int',
        'threshold'   => null
    ),
	'disks_stat_main_days' => array(
        'description' => 'Main stream archive depth, days',
        'severity'    => 1, 
        'invert'      => true,
        'disable'     => false,
        'type'        => 'int',
        'threshold'   => 30
    ),
	'disks_stat_main_gb' => array(
        'description' => 'Main stream archive volume, GB',
        'severity'    => 0, 
        'invert'      => false,
        'disable'     => false,
        'type'        => 'int',
        'threshold'   => null
    )
);





// Parse CLI options (PHP 5.3 compatible)
$options = parse_arguments($argv);

$debug    = isset($options['debug']) ? 1 : 0;
$hours    = isset($options['hours']) ? (int)$options['hours'] : 24;
$host     = isset($options['host']) ? $options['host'] : '';
$port     = isset($options['port']) ? $options['port'] : '';
$username = isset($options['username']) ? $options['username'] : '';
$password = isset($options['password']) ? $options['password'] : '';
$channel  = isset($options['channel']) ? $options['channel'] : '';

$timezone_offset = isset($options['timezone']) ? ((int)$options['timezone'] * 3600) : 0;
$delay = isset($options['delay']) ? (int)$options['delay'] : 700;


if (!$host || !$port || !$username || !$password ) {
    echo "UNKNOWN: Missing required input parameters.\n";
    exit(3);
}



// === Acquire API session ID ===
$sid = get_cached_sid($host, $port, $username, $password, $debug, $cache_dir);


// === Check Server Mode ===
if (!$channel) {
    debug_log("Channel ID not provided, performing server health check", $debug);

	$output_text = [];
	$output_status = 0;
	$perfdata = [];

	foreach ($settings as $setting_name => $info) {
		if (!empty($info['disable'])) {
			continue; // Skip disabled checks
		}

		$value = fetch_health_setting($host, $port, $sid, $setting_name, $debug);
		if (!is_numeric($value)) {
			debug_log("Invalid or missing value for $setting_name", $debug);
			continue;
		}

		$type = isset($info['type']) ? $info['type'] : 'bool';
		$invert = !empty($info['invert']);
		$severity = isset($info['severity']) ? $info['severity'] : 0;
		$description = isset($info['description']) ? $info['description'] : $setting_name;

		$has_problem = false;

		if ($type === 'bool') {
			// Boolean logic with optional inversion
			$bool_value = (bool)$value;
			$has_problem = $invert ? !$bool_value : $bool_value;

			if ($has_problem) {
				$output_status = max($output_status, $severity);
				$output_text[] = strtoupper($severity_map[$severity]) . ": " . $description;
			}
			// Else: OK state is silent for boolean parameters

		} elseif ($type === 'int') {
			$threshold = isset($info['threshold']) ? $info['threshold'] : null;
			$f_value = number_format($value, 2);

			if ($threshold !== null) {
				$perfdata[] = sprintf(
					"%s=%d;;;;%d",
					$setting_name,
					$f_value,
					$threshold
				);

				// Comparison logic with invert support
				$has_problem = $invert ? ($value < $threshold) : ($value >= $threshold);

				$prefix = $has_problem
					? strtoupper($severity_map[$severity]) . ": "
					: "OK: ";

				$output_text[] = $prefix . $description . " = $f_value";

				if ($has_problem) {
					$output_status = max($output_status, $severity);
				}
			} else {
				// No threshold → just display value, don't raise alert
				$output_text[] = "INFO: $description = $f_value";
			}
		}
	}

	// Final reporting
	if (empty($output_text) && $output_status > 0) {
		// Inconsistent state: status set to WARNING or CRITICAL but no explanation
		$severity_label = ($output_status === 2) ? "CRITICAL" : "WARNING";
		$output_text[] = "$severity_label: Inconsistent output.";
	}

	echo implode("\n", $output_text) . "\n";

	if (!empty($perfdata)) {
		echo "| " . implode(" ", $perfdata) . "\n";
	}

	exit($output_status);

}





// === Check Channel Mode ===
// === STEP 1: Get Channel GUID ===
$guid = get_cached_channel_guid($host, $port, $sid, $channel, $cache_life, $debug, $cache_dir);

$baseUrl = "https://$host:$port";

// === STEP 2: Get Token ===
$tokenUrl = "$baseUrl/get_video?channel=$guid&container=mjpeg&stream=archive_main&sid=$sid";
$response = curl_get_clean_json($tokenUrl);
$data = json_decode($response, true);
debug_log("Token Response: $response", $debug);

if (!$data || !isset($data['success']) || !$data['success']) {
    echo "UNKNOWN: Failed to get video token.\n";
    exit(3);
}
$token = $data['token'];

// === STEP 3: Start stream via HTTP to initialize archive ===
$mjpeg_fp = open_mjpeg_stream("http://$host:555/$token", $debug);
if (!$mjpeg_fp) {
    echo "UNKNOWN: Unable to connect to MJPEG stream.\n";
    exit(3);
}

// === STEP 4: Send archive seek command with current timestamp ===

$now = time() + $timezone_offset;
$timestamp = date('Ymd\THis', $now); // Format: 20180117T110734
$seekUrl = "$baseUrl/archive_command?command=seek&timestamp=$timestamp&direction=0&sid=$sid&token=$token";
$response = curl_get_clean_json($seekUrl);
$data = json_decode($response, true);
debug_log("Archive Seek Response: $response", $debug);

if (!$data || !isset($data['success']) || !$data['success']) {
    echo "UNKNOWN: Archive seek command failed.\n";
	if ($mjpeg_fp) {
		fclose($mjpeg_fp);
		debug_log("MJPEG stream closed gracefully", $debug);
	}
    exit(3);
}


// === STEP 5: Get detailed timeline and check event age and archive density ===

$selected_timeline = wait_for_timeline_data($host, $port, $sid, $token, $retry_count, $delay, $debug);

if ($selected_timeline === null) {
    echo "UNKNOWN: No valid timeline data found for channel $channel.\n";
	if ($mjpeg_fp) {
		fclose($mjpeg_fp);
		debug_log("MJPEG stream closed gracefully", $debug);
	}
    exit(3);
}


// === Proceed with timeline analysis ===
$selected_ts = 0;
$now_ts = time() + $timezone_offset;

$day_start_ts = strtotime($selected_timeline['day_start'] . ' 00:00:00');
$timeline_list = $selected_timeline['timeline'];

// Calculate thresholds
$threshold_event_ts   = $now_ts - ($hours * 3600);         // For event age
$threshold_density_ts = $now_ts - ($density_hours * 3600); // For density window

// Find last event (by end time)
$last_event           = $timeline_list[count($timeline_list) - 1];
$last_event_end_sec   = (int)$last_event['end'];
$last_event_ts        = $day_start_ts + $last_event_end_sec;
$last_event_datetime  = date('Y-m-d H:i:s', $last_event_ts);
$age_seconds          = $now_ts - $last_event_ts;
$age_minutes          = floor($age_seconds / 60);

// Count events within density window
$events_in_density_window = 0;
foreach ($timeline_list as $event) {
    $begin_ts = $day_start_ts + (int)$event['begin'];
    $end_ts   = $day_start_ts + (int)$event['end'];

    // Include events that overlap with the threshold window
    if (($begin_ts >= $threshold_density_ts) || ($end_ts >= $threshold_density_ts) || ($begin_ts <= $threshold_density_ts && $end_ts >= $threshold_density_ts)) {
        $events_in_density_window++;
    }
}

// Debug output
debug_log("Last event ended $age_minutes minutes ago at $last_event_datetime", $debug);
debug_log("Archive Density (events in last $density_hours hours): $events_in_density_window", $debug);




// === STEP 6: Close stream and report results ===

if ($mjpeg_fp) {
    fclose($mjpeg_fp);
    debug_log("MJPEG stream closed gracefully", $debug);
}

$perfdata = sprintf(
    "archive_density=%d;0;200",
    $events_in_density_window
);

if ($last_event_ts >= $threshold_event_ts) {
	echo "OK: Last timeline event $age_minutes minutes ago at $last_event_datetime. Archive Density: $events_in_density_window events in last $density_hours hours. | $perfdata\n";
	exit(0);
} else {
    echo "WARNING: No timeline events found in last $hours hours timespan. | $perfdata\n";
    exit(1);
}





// === Utility functions ===

function debug_log($msg, $debug) {
    if ($debug) {
        echo "[DEBUG] $msg\n";
    }
}

function wait_ms($milliseconds) {
    // usleep takes microseconds: 1000 microseconds = 1 millisecond
    usleep($milliseconds * 1000);
}


function curl_get_clean_json($url) {
    $ch = curl_init();

    // Basic cURL setup
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // For self-signed certificates, since you're using HTTPS with IPs
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "UNKNOWN: cURL error: " . curl_error($ch) . "\n";
        curl_close($ch);
        exit(3);
    }

    curl_close($ch);

    // Remove /* ... */ multiline comments
    $cleaned = preg_replace('!/\*.*?\*/!s', '', $response);

    return trim($cleaned);
}



function open_mjpeg_stream($url, $debug = 0) {
    $parts = parse_url($url);
    $host = $parts['host'];
    $port = isset($parts['port']) ? $parts['port'] : 80;
    $path = isset($parts['path']) ? $parts['path'] : '/';

    $fp = fsockopen($host, $port, $errno, $errstr, 5);
    if (!$fp) {
        echo "API ERR: Could not connect to MJPEG stream: $errstr ($errno)\n";
        return false;
    }

    $headers = "GET $path HTTP/1.1\r\n";
    $headers .= "Host: $host\r\n";
    $headers .= "Connection: keep-alive\r\n";
    $headers .= "\r\n";

    fwrite($fp, $headers);

    // Read the HTTP response header (just a few lines)
    $response = '';
    for ($i = 0; $i < 10; $i++) {
        $line = fgets($fp, 512);
        if ($line === false || trim($line) === '') break;
        $response .= $line;
    }

    if ($debug) {
        echo "[DEBUG] MJPEG Stream Header:\n$response\n";
    }

    return $fp;
}


function parse_arguments($argv) {
    $args = array();
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (substr($arg, 0, 2) === '--') {
            $key = substr($arg, 2);
            // Next element is the value if it exists and is not another option
            if (isset($argv[$i + 1]) && substr($argv[$i + 1], 0, 2) !== '--') {
                $args[$key] = $argv[++$i];
            } else {
                $args[$key] = true; // flag with no value
            }
        }
    }
    return $args;
}

function get_cached_sid($host, $port, $username, $password, $debug = 0, $cache_dir = '/tmp/check_trassir/') {
    $baseUrl = "https://$host:$port";

    // Ensure cache directory exists
    if (!is_dir($cache_dir)) {
        if (!mkdir($cache_dir, 0755, true)) {
            echo "UNKNOWN: Failed to create cache directory: $cache_dir\n";
            exit(3);
        }
    }

    // Sanitize and build unique cache filename
    $safe_host = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $host);
    //$cache_file = $cache_dir . $safe_host . '.sid.cache';
	$cache_file = rtrim($cache_dir, '/') . "/$safe_host.sid.cache";

    // Try reading from cache
    if (file_exists($cache_file)) {
        $sid = trim(file_get_contents($cache_file));
        debug_log("[$host] Read SID from cache: $sid", $debug);

        // Validate cached SID
        $healthUrl = "$baseUrl/health?sid=$sid";
        $response = curl_get_clean_json($healthUrl);
        debug_log("[$host] Health check response: $response", $debug);

        $data = json_decode($response, true);
        if (is_array($data) && isset($data['cpu_load'])) {
            debug_log("[$host] SID is valid, using cached SID.", $debug);
            return $sid;
        } else {
            debug_log("[$host] Cached SID is invalid or expired.", $debug);
        }
    }

    // Request new SID
    $loginUrl = "$baseUrl/login?username=$username&password=$password";
    $response = curl_get_clean_json($loginUrl);
    debug_log("[$host] Login response: $response", $debug);

    $data = json_decode($response, true);
    if (is_array($data) && isset($data['success']) && $data['success'] && isset($data['sid'])) {
        $sid = $data['sid'];
        file_put_contents($cache_file, $sid);
		chmod($cache_file, 0755);
        debug_log("[$host] New SID acquired and cached: $sid", $debug);
        return $sid;
    }

    echo "UNKNOWN: Failed to acquire valid session ID for $host.\n";
    exit(3);
}



function get_cached_channel_guid($host, $port, $sid, $channel_name, $max_age_hours, $debug = 0, $cache_dir = '/tmp/check_trassir/') {
    // Ensure cache directory exists
    if (!is_dir($cache_dir)) {
        if (!mkdir($cache_dir, 0755, true)) {
            echo "UNKNOWN: Failed to create cache directory: $cache_dir\n";
            exit(3);
        }
    }

    $baseUrl = "https://$host:$port";
    $safe_host = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $host);
    $cache_file = rtrim($cache_dir, '/') . "/$safe_host.channels.cache";

    $reload = true;
    if (file_exists($cache_file)) {
        $cache = json_decode(file_get_contents($cache_file), true);
        if (is_array($cache) && isset($cache['timestamp']) && isset($cache['channels'])) {
            $cache_age = time() - $cache['timestamp'];
            if ($cache_age <= ($max_age_hours * 3600)) {
                debug_log("Using cached channel list for $host (age: $cache_age sec)", $debug);
                $data = $cache;
                $reload = false;
            }
        }
    }

    if ($reload) {
        $channelUrl = "$baseUrl/channels?sid=$sid";
        $response = curl_get_clean_json($channelUrl);
        debug_log("Channel list response for $host: $response", $debug);
        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['channels'])) {
            echo "UNKNOWN: Failed to fetch valid channel list from server for $host.\n";
            exit(3);
        }

        $data['timestamp'] = time();
        file_put_contents($cache_file, json_encode($data));
		chmod($cache_file, 0755);
        debug_log("Channel list updated and cached for $host", $debug);
    }

    $guid = null;
    foreach ($data['channels'] as $ch) {
        // if (isset($ch['name']) && $ch['name'] === $channel_name) {
		if (isset($ch['name']) && strpos( $ch['name'], $channel_name) !== false ) {
            $guid = $ch['guid'];
            break;
        }
    }

    if (!$guid) {
        echo "UNKNOWN: Channel not found on $host: $channel_name\n";
        exit(3);
    }

    return $guid;
}


function fetch_health_setting($host, $port, $sid, $setting, $debug = 0) {
    $url = "https://$host:$port/settings/health/$setting?sid=$sid";
    
    // Use your existing curl_get_clean_json function or api_request_json
    $json_str = curl_get_clean_json($url);
    if ($debug) debug_log("Raw JSON from $setting: $json_str", $debug);

    $data = json_decode($json_str, true);
    if ($data === null) {
        if ($debug) debug_log("CRITICAL: Failed to decode JSON for setting '$setting'", $debug);
        return null;
    }

    if (!isset($data['value'])) {
        if ($debug) debug_log("CRITICAL: 'value' not found in JSON for setting '$setting'", $debug);
        return null;
    }

    $value = $data['value'];

    // Interpret value: 0 = Ok, 1 = Problem
    $status_text = ($value == 0) ? 'Ok' : (($value == 1) ? 'Problem' : 'Unknown');

    if ($debug) debug_log("Health setting '$setting' value: $value ($status_text)", $debug);

    return $value;
}


function wait_for_timeline_data($host, $port, $sid, $channel_token, $retry_count, $delay, $debug = false) {
    $timeline_url = "https://$host:$port/archive_status?type=timeline&sid=$sid";

    for ($i = 0; $i < $retry_count; $i++) {
        wait_ms($delay);  // Delay between attempts
        $timeline_json = curl_get_clean_json($timeline_url);
        debug_log("Timeline JSON (attempt $i): $timeline_json", $debug);

        $timeline_data = json_decode($timeline_json, true);

        if (!is_array($timeline_data)) {
            debug_log("Invalid timeline response format.", $debug);
            continue;
        }

        $best_entry = null;
        $best_distance = PHP_INT_MAX;
        $now_ts = time();

        foreach ($timeline_data as $entry) {
            if (!isset($entry['token']) || !isset($entry['day_start']) || !is_array($entry['timeline'])) {
                continue;
            }

            if ($entry['token'] !== $channel_token) {
                continue;
            }

            if (empty($entry['timeline'])) {
                continue;
            }

			// fix bug that makes api return today date as 1970-01-01
			if ($entry['day_start'] === "1970-01-01") {
				$entry['day_start'] = date('Y-m-d');
			}
			
			$day_ts = strtotime($entry['day_start'] . ' 00:00:00');
			
            if ($day_ts === false) {
                continue;
            }

            $distance = abs($now_ts - $day_ts);
            if ($distance < $best_distance) {
                $best_distance = $distance;
                $best_entry = $entry;
            }
        }

        if ($best_entry !== null) {
            debug_log("Valid timeline found with date " . $best_entry['day_start'], $debug);
            return $best_entry;
        }

        debug_log("No valid timeline data found on attempt $i", $debug);
    }

    debug_log("Failed to get timeline data after $retry_count attempts", $debug);
    return null;
}
