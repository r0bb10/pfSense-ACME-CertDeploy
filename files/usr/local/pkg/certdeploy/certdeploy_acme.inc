<?php

require_once('certs.inc');

function certdeploy_acme_certificates() {
	$certs = [];
	foreach (config_get_path('installedpackages/acme/certificates/item', []) as $cert) {
		$cm = lookup_cert_by_name($cert['name']);
		$cm = $cm['item'] ?? [];
		$descr = $cm['descr'] ?? '';
		$basename = $cert['name'];

		$sans = [];
		if (is_array($cert['a_domainlist']) && is_array($cert['a_domainlist']['item'])) {
			foreach ($cert['a_domainlist']['item'] as $domain) {
				if (($domain['status'] ?? '') !== 'disable') {
					$sans[] = $domain['name'];
				}
			}
		}

		$certs[$cert['name']] = [
			'name' => $cert['name'],
			'descr' => $descr,
			'status' => $cert['status'] ?? 'active',
			'lastrenewal' => $cert['lastrenewal'] ?? 0,
			'valid_from' => $cm['valid_from'] ?? '',
			'valid_to' => $cm['valid_to'] ?? '',
			'cn' => $cm['cn'] ?? '',
			'sans' => $sans,
			'paths' => [
				'cert' => "/conf/acme/{$basename}.crt",
				'key' => "/conf/acme/{$basename}.key",
				'fullchain' => "/conf/acme/{$basename}.fullchain",
			],
		];
	}
	return $certs;
}

function certdeploy_acme_certificate_files($name) {
	$certs = certdeploy_acme_certificates();
	if (!isset($certs[$name])) {
		return [];
	}
	$cert = $certs[$name];
	$files = [];
	foreach (['cert' => 'Certificate', 'key' => 'Private Key', 'fullchain' => 'Full Chain'] as $type => $label) {
		$path = $cert['paths'][$type] ?? '';
		if ($path === '' || !is_readable($path)) {
			continue;
		}
		$stat = stat($path);
		$owner = '';
		$group = '';
		if ($stat !== false) {
			$owner = function_exists('posix_getpwuid') ? (posix_getpwuid($stat['uid'])['name'] ?? '') : '';
			$group = function_exists('posix_getgrgid') ? (posix_getgrgid($stat['gid'])['name'] ?? '') : '';
		}
		$basename = basename($path);
		$files[$basename] = [
			'type' => $type,
			'path' => $path,
			'label' => $basename,
			'owner' => $owner,
			'group' => $group,
			'mode' => $stat !== false ? sprintf('%04o', $stat['mode'] & 07777) : '',
		];
	}
	return $files;
}

function certdeploy_acme_certificate_options() {
	$opts = ['' => gettext('None')];
	foreach (certdeploy_acme_certificates() as $name => $cert) {
		$label = $name;
		if ($cert['descr'] && $cert['descr'] !== $name) {
			$label .= " ({$cert['descr']})";
		}
		if ($cert['status'] !== 'active') {
			$label .= ' [disabled]';
		}
		$opts[$name] = $label;
	}
	return $opts;
}

?>
