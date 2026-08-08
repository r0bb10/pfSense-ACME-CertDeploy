<?php

function certdeploy_backup_build() {
	$config = config_get_path('installedpackages/certdeploy', []);
	if (!is_array($config)) {
		$config = [];
	}

	$keys = [];
	foreach ($config['identities']['item'] ?? [] as $ident) {
		$id = $ident['id'] ?? '';
		$path = $ident['private_key_path'] ?? '';
		if ($id === '' || $path === '' || !is_file($path)) {
			continue;
		}
		$keys[$id] = base64_encode(file_get_contents($path));
	}

	return [
		'format' => 'certdeploy',
		'version' => 1,
		'created' => time(),
		'config' => $config,
		'keys' => $keys,
	];
}

function certdeploy_backup_download() {
	$backup = certdeploy_backup_build();
	$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	$filename = 'certdeploy-config-' . date('Ymd-His') . '.json';

	header('Content-Type: application/json');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Content-Length: ' . strlen($json));
	echo $json;
	exit;
}

function certdeploy_backup_parse($json) {
	$data = json_decode($json, true);
	if (!is_array($data)) {
		return ['error' => gettext('The uploaded file is not valid JSON.')];
	}
	if (($data['format'] ?? '') !== 'certdeploy') {
		return ['error' => gettext('The uploaded file is not a CertDeploy backup.')];
	}
	if (!is_array($data['config'] ?? null)) {
		return ['error' => gettext('The backup contains no configuration.')];
	}
	if (!is_array($data['keys'] ?? null)) {
		return ['error' => gettext('The backup contains no key data.')];
	}
	return ['data' => $data];
}

function certdeploy_backup_restore($data) {
	$errors = [];

	$key_dir = CERTDEPLOY_KEYS_DIR;
	if (!is_dir($key_dir)) {
		mkdir($key_dir, 0700, true);
	}

	foreach ($data['keys'] as $id => $b64) {
		if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string)$id)) {
			$errors[] = gettext('Backup contains an invalid identity key ID.');
			continue;
		}
		$pem = base64_decode((string)$b64, true);
		if ($pem === false) {
			$errors[] = gettext('Backup contains malformed key data.');
			continue;
		}
		$path = $key_dir . '/' . $id;
		$tmp = $path . '.tmp';
		file_put_contents($tmp, $pem);
		chmod($tmp, 0600);
		rename($tmp, $path);
	}

	if (!empty($errors)) {
		return $errors;
	}

	$config = $data['config'];
	$config['identities'] = $config['identities'] ?? [];
	$config['deployments'] = $config['deployments'] ?? [];
	$config['config'] = $config['config'] ?? [];

	config_set_path('installedpackages/certdeploy', $config);
	write_config('CertDeploy configuration restored from backup');

	certdeploy_sync();
	return [];
}

?>
