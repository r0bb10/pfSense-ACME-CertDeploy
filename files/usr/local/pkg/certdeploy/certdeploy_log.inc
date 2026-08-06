<?php

const CERTDEPLOY_LOG_FILE = '/var/log/certdeploy.log';

function certdeploy_log_lines($limit = 250) {
	if (!is_readable(CERTDEPLOY_LOG_FILE)) {
		return [];
	}
	$lines = file(CERTDEPLOY_LOG_FILE, FILE_IGNORE_NEW_LINES);
	if ($lines === false) {
		return [];
	}
	return array_slice($lines, -$limit);
}

function certdeploy_log_filter_component($lines, $component) {
	if ($component === '') {
		return $lines;
	}
	return array_values(array_filter($lines, function ($line) use ($component) {
		return strpos($line, '[' . $component . ']') !== false;
	}));
}

function certdeploy_log_filter_level($lines, $level) {
	if ($level === '') {
		return $lines;
	}
	return array_values(array_filter($lines, function ($line) use ($level) {
		return strpos($line, '[' . $level . ']') !== false;
	}));
}

?>
