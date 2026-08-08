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
require_once('certdeploy/certdeploy_backup.inc');

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
	$rc_config = '';
	$rc_config .= "certdeploy_enable=\"{$enabled}\"\n";
	$rc_config .= 'certdeploy_config_dir="' . certdeploy_quote_rc_value(CERTDEPLOY_CONFIG_DIR) . "\"\n";
	$rc_config .= 'certdeploy_log_file="' . certdeploy_quote_rc_value(CERTDEPLOY_LOG_FILE) . "\"\n";
	$rc_config .= 'certdeploy_socket="' . certdeploy_quote_rc_value(CERTDEPLOY_SOCKET) . "\"\n";

	file_put_contents(CERTDEPLOY_RCCONF, $rc_config, LOCK_EX);
}

function certdeploy_write_newsyslog_conf() {
	global $g;

	$confdir = $g['varetc_path'] . '/newsyslog.conf.d';
	if (!is_dir($confdir)) {
		mkdir($confdir, 0755, true);
	}

	$line = system_log_rotation_make_line(
		CERTDEPLOY_LOG_FILE,
		'root:wheel',
		600,
		5,
		1000,
		'$W0D16',
		'B',
		'/var/run/certdeploy/certdeploy.pid',
		'SIGUSR1'
	);

	$conf = "# Automatically generated for CertDeploy. Do not edit.\n" . $line;
	@file_put_contents("{$confdir}/certdeploy.log.conf", $conf, LOCK_EX);
}

function certdeploy_install() {
	certdeploy_ensure_directories();
	certdeploy_write_rc_conf();
	certdeploy_write_newsyslog_conf();

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
	certdeploy_write_newsyslog_conf();
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
	$bytes = random_bytes(16);
	$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
	$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
	return vsprintf('%s%s%s%s-%s%s-%s%s-%s%s-%s%s%s%s%s%s', str_split(bin2hex($bytes), 2));
}

function certdeploy_next_config_item_index($path) {
	$i = 0;
	while (config_get_path("{$path}/item/{$i}") !== null) {
		$i++;
	}
	return $i;
}

?>
