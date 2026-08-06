<?php

require_once('util.inc');

const CERTDEPLOY_KEYS_DIR = '/usr/local/etc/certdeploy/keys';

function certdeploy_identities() {
	return config_get_path('installedpackages/certdeploy/identities/item', []);
}

function certdeploy_identity_options() {
	$opts = [];
	foreach (certdeploy_identities() as $idx => $ident) {
		$label = $ident['name'] ?? ('identity-' . $idx);
		if (!empty($ident['description'])) {
			$label .= ' - ' . $ident['description'];
		}
		if (($ident['enabled'] ?? '') !== 'on') {
			$label .= ' [disabled]';
		}
		$opts[$idx] = $label;
	}
	return $opts;
}

function certdeploy_identity_generate($name, $type = 'ed25519') {
	$key_dir = CERTDEPLOY_KEYS_DIR;
	if (!is_dir($key_dir)) {
		mkdir($key_dir, 0700, true);
	}
	$path = $key_dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
	if ($type === 'ed25519') {
		exec('/usr/bin/ssh-keygen -t ed25519 -f ' . escapeshellarg($path) . ' -N "" -C ' . escapeshellarg($name) . ' 2>&1', $out, $rc);
	} else {
		$bits = ($type === 'rsa4096') ? 4096 : (($type === 'rsa3072') ? 3072 : 2048);
		exec('/usr/bin/ssh-keygen -t rsa -b ' . escapeshellarg($bits) . ' -f ' . escapeshellarg($path) . ' -N "" -C ' . escapeshellarg($name) . ' 2>&1', $out, $rc);
	}
	if ($rc !== 0) {
		return ['error' => implode("\n", $out)];
	}
	chmod($path, 0600);
	chmod($path . '.pub', 0644);
	$public = file_get_contents($path . '.pub');
	$public = trim($public);
	$fingerprint = exec('/usr/bin/ssh-keygen -lf ' . escapeshellarg($path . '.pub') . ' -E sha256');
	preg_match('/SHA256:[A-Za-z0-9+\/]+/', $fingerprint, $m);
	$fingerprint = $m[0] ?? '';
	return [
		'private_key_path' => $path,
		'public_key' => $public,
		'fingerprint' => $fingerprint,
	];
}

function certdeploy_identity_public_key($private_key_path) {
	if (!is_readable($private_key_path)) {
		return '';
	}
	$pub = $private_key_path . '.pub';
	if (is_readable($pub)) {
		return trim(file_get_contents($pub));
	}
	$tmp = tempnam('/tmp', 'cdpub');
	exec('/usr/bin/ssh-keygen -y -f ' . escapeshellarg($private_key_path) . ' > ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
	if ($rc === 0 && is_readable($tmp)) {
		$key = trim(file_get_contents($tmp));
		unlink($tmp);
		return $key;
	}
	@unlink($tmp);
	return '';
}

function certdeploy_identity_fingerprint($private_key_path) {
	$pub = certdeploy_identity_public_key($private_key_path);
	if ($pub === '') {
		return '';
	}
	$tmp = tempnam('/tmp', 'cdfp');
	file_put_contents($tmp, $pub . "\n");
	$fp = exec('/usr/bin/ssh-keygen -lf ' . escapeshellarg($tmp) . ' -E sha256');
	unlink($tmp);
	preg_match('/SHA256:[A-Za-z0-9+\/]+/', $fp, $m);
	return $m[0] ?? '';
}

function certdeploy_identity_save_import($name, $key_pem) {
	$key_dir = CERTDEPLOY_KEYS_DIR;
	if (!is_dir($key_dir)) {
		mkdir($key_dir, 0700, true);
	}
	$path = $key_dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
	$tmp = $path . '.tmp';
	file_put_contents($tmp, $key_pem);
	chmod($tmp, 0600);
	rename($tmp, $path);
	return $path;
}

?>
