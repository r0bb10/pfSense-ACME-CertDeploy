<?php

require_once('functions.inc');
require_once('globals.inc');
require_once('service-utils.inc');
require_once('util.inc');

require_once('certdeploy/certdeploy_general.inc');
require_once('certdeploy/certdeploy_acme.inc');
require_once('certdeploy/certdeploy_identities.inc');
require_once('certdeploy/certdeploy_deployments.inc');
require_once('certdeploy/certdeploy_log.inc');

const CERTDEPLOY_CONFIG_DIR = '/usr/local/etc/certdeploy';
const CERTDEPLOY_KEYS_DIR = '/usr/local/etc/certdeploy/keys';
const CERTDEPLOY_STATE_DIR = '/var/db/certdeploy';
const CERTDEPLOY_LOG_FILE = '/var/log/certdeploy.log';
const CERTDEPLOY_SOCKET = '/var/run/certdeploy/certdeploy.sock';
const CERTDEPLOY_RCCONF = '/usr/local/etc/rc.conf.d/certdeploy';
const CERTDEPLOY_CONFIG_PATH = 'installedpackages/certdeploy';

function certdeploy_settings() {
	return config_get_path(CERTDEPLOY_CONFIG_PATH . '/config/0', []);
}

function certdeploy_enabled() {
	$settings = certdeploy_settings();
	return (($settings['enable'] ?? '') === 'on');
}

function certdeploy_quote_rc_value($value) {
	return str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], (string)$value);
}

function certdeploy_write_rc_conf() {
	$settings = certdeploy_settings();

	if (!is_dir(dirname(CERTDEPLOY_RCCONF))) {
		mkdir(dirname(CERTDEPLOY_RCCONF), 0755, true);
	}

	$enabled = certdeploy_enabled() ? 'YES' : 'NO';
	$rcconf = '';
	$rcconf .= "certdeploy_enable=\"{$enabled}\"\n";
	$rcconf .= 'certdeploy_config_dir="' . certdeploy_quote_rc_value(CERTDEPLOY_CONFIG_DIR) . "\"\n";
	$rcconf .= 'certdeploy_log_file="' . certdeploy_quote_rc_value(CERTDEPLOY_LOG_FILE) . "\"\n";
	$rcconf .= 'certdeploy_socket="' . certdeploy_quote_rc_value(CERTDEPLOY_SOCKET) . "\"\n";

	file_put_contents(CERTDEPLOY_RCCONF, $rcconf, LOCK_EX);
}

function certdeploy_install() {
	certdeploy_ensure_directories();
	certdeploy_write_rc_conf();

	$services = config_get_path('installedpackages/service', []);
	foreach ($services as $idx => $svc) {
		if (($svc['name'] ?? '') === 'certdeploy') {
			config_set_path("installedpackages/service/{$idx}/description", 'Acme certificate deployer daemon');
			write_config('CertDeploy service description updated');
			break;
		}
	}
}

function certdeploy_deinstall() {
	if (is_service_running('certdeploy')) {
		stop_service('certdeploy');
	}
	@unlink(CERTDEPLOY_RCCONF);
}

function certdeploy_sync() {
	certdeploy_ensure_directories();
	certdeploy_write_rc_conf();
	certdeploy_write_daemon_config();

	if (certdeploy_enabled()) {
		if (is_service_running('certdeploy')) {
			restart_service('certdeploy');
		} else {
			start_service('certdeploy');
		}
	} elseif (is_service_running('certdeploy')) {
		stop_service('certdeploy');
	}
}

function certdeploy_ensure_directories() {
	foreach ([CERTDEPLOY_CONFIG_DIR, CERTDEPLOY_KEYS_DIR, CERTDEPLOY_STATE_DIR, '/var/run/certdeploy'] as $dir) {
		if (!is_dir($dir)) {
			mkdir($dir, 0750, true);
		}
	}
	chmod(CERTDEPLOY_KEYS_DIR, 0700);
}

function certdeploy_write_daemon_config() {
	$settings = certdeploy_settings();
	$config = [
		'enable' => certdeploy_enabled(),
		'acme_monitor' => ($settings['acme_monitor'] ?? '') === 'on',
		'scan_interval' => (int)($settings['scan_interval'] ?? 60),
		'reconcile' => ($settings['reconcile'] ?? '') === 'on',
		'reconcile_interval' => (int)($settings['reconcile_interval'] ?? 3600),
		'max_concurrent_targets' => (int)($settings['max_concurrent_targets'] ?? 3),
		'default_ssh_timeout' => (int)($settings['default_ssh_timeout'] ?? 30),
		'default_cmd_timeout' => (int)($settings['default_cmd_timeout'] ?? 60),
		'default_retry_count' => (int)($settings['default_retry_count'] ?? 3),
		'default_retry_delay' => (int)($settings['default_retry_delay'] ?? 60),
		'log_level' => $settings['log_level'] ?? 'info',
		'config_path' => '/cf/conf/config.xml',
		'state_file' => CERTDEPLOY_STATE_DIR . '/state.json',
		'log_file' => CERTDEPLOY_LOG_FILE,
		'socket' => CERTDEPLOY_SOCKET,
	];

	$json = json_encode($config, JSON_PRETTY_PRINT);
	$tmp = CERTDEPLOY_CONFIG_DIR . '/config.json.tmp';
	file_put_contents($tmp, $json, LOCK_EX);
	chmod($tmp, 0640);
	rename($tmp, CERTDEPLOY_CONFIG_DIR . '/config.json');
}

function certdeploy_uuid() {
	return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		mt_rand(0, 0xffff), mt_rand(0, 0xffff),
		mt_rand(0, 0xffff),
		mt_rand(0, 0x0fff) | 0x4000,
		mt_rand(0, 0x3fff) | 0x8000,
		mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
	);
}

function certdeploy_array_next_index($path) {
	$i = 0;
	while (config_get_path("{$path}/item/{$i}") !== null) {
		$i++;
	}
	return $i;
}

?>
