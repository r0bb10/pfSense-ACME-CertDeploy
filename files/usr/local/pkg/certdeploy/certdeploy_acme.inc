<?php

require_once('certs.inc');

function certdeploy_acme_certificates() {
	$certs = [];
	foreach (config_get_path('installedpackages/acme/certificates/item', []) as $cert) {
		$basename = $cert['name'];

		$cert_path = "/conf/acme/{$basename}.crt";
		$valid_from = '';
		$valid_to = '';
		$cn = '';
		if (is_readable($cert_path)) {
			$cert_info = openssl_x509_parse(file_get_contents($cert_path));
			if ($cert_info !== false) {
				$valid_from = !empty($cert_info['validFrom_time_t']) ? date('Y-m-d H:i:s', $cert_info['validFrom_time_t']) : '';
				$valid_to = !empty($cert_info['validTo_time_t']) ? date('Y-m-d H:i:s', $cert_info['validTo_time_t']) : '';
				$cn = $cert_info['subject']['CN'] ?? '';
			}
		}

		$cm = lookup_cert_by_name($cert['name']);
		$cm = $cm['item'] ?? [];
		$descr = $cm['descr'] ?? '';

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
			'valid_from' => $valid_from,
			'valid_to' => $valid_to,
			'cn' => $cn,
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

function certdeploy_acme_certificate_fingerprint($name) {
	$path = "/conf/acme/{$name}.fullchain";
	if (!is_readable($path)) {
		return '';
	}
	return 'SHA256:' . hash_file('sha256', $path);
}

?>
