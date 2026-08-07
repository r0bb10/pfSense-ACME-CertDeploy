<?php

function certdeploy_general_defaults() {
	return [
		'enable' => 'off',
		'acme_monitor' => 'on',
		'scan_interval' => 60,
		'reconcile' => 'on',
		'reconcile_interval' => 3600,
		'max_concurrent_targets' => 3,
		'default_ssh_timeout' => 30,
		'default_cmd_timeout' => 60,
		'default_retry_count' => 3,
		'default_retry_delay' => 60,
		'log_level' => 'info',
	];
}

function certdeploy_general_validate(&$post, &$input_errors) {
	$interval = (int)($post['scan_interval'] ?? 0);
	if ($interval < 10 || $interval > 86400) {
		$input_errors[] = gettext('ACME scan interval must be between 10 and 86400 seconds.');
	}
	$reconcile = (int)($post['reconcile_interval'] ?? 0);
	if ($reconcile < 60 || $reconcile > 86400) {
		$input_errors[] = gettext('Reconciliation interval must be between 60 and 86400 seconds.');
	}
	$concurrent = (int)($post['max_concurrent_targets'] ?? 0);
	if ($concurrent < 1 || $concurrent > 50) {
		$input_errors[] = gettext('Maximum concurrent targets must be between 1 and 50.');
	}
	foreach (['default_ssh_timeout', 'default_cmd_timeout', 'default_retry_delay'] as $f) {
		if ((int)($post[$f] ?? 0) < 1) {
			$input_errors[] = gettext(ucfirst(str_replace('_', ' ', $f)) . ' must be at least 1 second.');
		}
	}
	$levels = ['debug', 'info', 'warn', 'error'];
	if (!in_array($post['log_level'] ?? '', $levels, true)) {
		$input_errors[] = gettext('Log level must be debug, info, warn, or error.');
	}
}

function certdeploy_general_build($post) {
	return [
		'enable' => empty($post['enable']) ? 'off' : 'on',
		'acme_monitor' => empty($post['acme_monitor']) ? 'off' : 'on',
		'scan_interval' => (int)$post['scan_interval'],
		'reconcile' => empty($post['reconcile']) ? 'off' : 'on',
		'reconcile_interval' => (int)$post['reconcile_interval'],
		'max_concurrent_targets' => (int)$post['max_concurrent_targets'],
		'default_ssh_timeout' => (int)$post['default_ssh_timeout'],
		'default_cmd_timeout' => (int)$post['default_cmd_timeout'],
		'default_retry_count' => (int)$post['default_retry_count'],
		'default_retry_delay' => (int)$post['default_retry_delay'],
		'log_level' => $post['log_level'],
	];
}

function certdeploy_global_state() {
	$path = '/var/db/certdeploy/state.json';
	if (!is_readable($path)) {
		return [];
	}
	$data = json_decode(file_get_contents($path), true);
	return is_array($data) ? $data : [];
}

?>
