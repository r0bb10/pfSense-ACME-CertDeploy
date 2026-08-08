<?php

require_once('util.inc');

function certdeploy_identity_id_is_valid($id) {
	return is_string($id) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id);
}

function certdeploy_identity_key_path($id) {
	if (!certdeploy_identity_id_is_valid($id)) {
		return false;
	}
	return CERTDEPLOY_KEYS_DIR . '/' . $id;
}

function certdeploy_identities() {
	return config_get_path('installedpackages/certdeploy/identities/item', []);
}

function certdeploy_identity_options() {
	$opts = [];
	foreach (certdeploy_identities() as $ident) {
		$id = $ident['id'] ?? '';
		if ($id === '') {
			continue;
		}
		$label = $ident['name'] ?? $id;
		if (!empty($ident['description'])) {
			$label .= ' - ' . $ident['description'];
		}
		$opts[$id] = $label;
	}
	return $opts;
}

function certdeploy_identity_fingerprint_from_public_key($public_key) {
	$public_key = trim($public_key);
	if ($public_key === '') {
		return '';
	}
	$parts = explode(' ', $public_key);
	if (count($parts) < 2) {
		return '';
	}
	$blob = base64_decode($parts[1]);
	if ($blob === false) {
		return '';
	}
	return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}

function certdeploy_identity_generate($name, $type = 'ed25519', $uuid = null) {
	$key_dir = CERTDEPLOY_KEYS_DIR;
	if (!certdeploy_identity_id_is_valid($uuid)) {
		return ['error' => gettext('Invalid identity ID.')];
	}
	$real_path = $key_dir . '/' . $uuid;
	$tmp_path = tempnam('/tmp', 'cdkey');
	@unlink($tmp_path);
	if ($type === 'ed25519') {
		exec('/usr/bin/ssh-keygen -q -t ed25519 -f ' . escapeshellarg($tmp_path) . ' -N "" -C ' . escapeshellarg($name) . ' 2>&1', $out, $rc);
	} else {
		$bits = ($type === 'rsa4096') ? 4096 : (($type === 'rsa3072') ? 3072 : 2048);
		exec('/usr/bin/ssh-keygen -q -t rsa -b ' . escapeshellarg($bits) . ' -f ' . escapeshellarg($tmp_path) . ' -N "" -C ' . escapeshellarg($name) . ' 2>&1', $out, $rc);
	}
	if ($rc !== 0 || !is_readable($tmp_path) || !is_readable($tmp_path . '.pub')) {
		@unlink($tmp_path);
		@unlink($tmp_path . '.pub');
		return ['error' => implode("\n", $out)];
	}
	$private_pem = file_get_contents($tmp_path);
	$public = trim(file_get_contents($tmp_path . '.pub'));
	$fingerprint = certdeploy_identity_fingerprint_from_public_key($public);
	@unlink($tmp_path);
	@unlink($tmp_path . '.pub');
	return [
		'identity_id' => $uuid,
		'private_key_pem' => $private_pem,
		'private_key_path' => $real_path,
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
	return certdeploy_identity_fingerprint_from_public_key($pub);
}

function certdeploy_identity_save_import($name, $key_pem, $uuid = null) {
	$key_dir = CERTDEPLOY_KEYS_DIR;
	if (!certdeploy_identity_id_is_valid($uuid)) {
		return false;
	}
	if (!is_dir($key_dir)) {
		mkdir($key_dir, 0700, true);
	}
	$path = $key_dir . '/' . $uuid;
	$tmp = $path . '.tmp';
	// Browsers may submit CRLF line endings in textareas; normalize to LF.
	$key_pem = str_replace(["\r\n", "\r"], "\n", $key_pem);
	file_put_contents($tmp, $key_pem);
	chmod($tmp, 0600);
	rename($tmp, $path);
	return $path;
}

?>
