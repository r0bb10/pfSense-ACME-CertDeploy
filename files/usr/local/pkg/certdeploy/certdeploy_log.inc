<?php

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

?>
