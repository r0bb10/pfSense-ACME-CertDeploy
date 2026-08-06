<?php

require_once('certs.inc');

function certdeploy_acme_certificates() {
	$certs = [];
	foreach (config_get_path('installedpackages/acme/certificates/item', []) as $cert) {
		$cm = lookup_cert_by_name($cert['name']);
		$cm = $cm['item'] ?? [];
		$descr = $cm['descr'] ?? $cert['name'];

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
				'cert' => "/conf/acme/{$descr}.crt",
				'key' => "/conf/acme/{$descr}.key",
				'fullchain' => "/conf/acme/{$descr}.fullchain",
			],
		];
	}
	return $certs;
}

function certdeploy_acme_certificate_options() {
	$opts = [];
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
