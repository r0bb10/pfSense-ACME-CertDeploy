<?php

function certdeploy_backup_password_is_valid($password) {
	return is_string($password) && strlen($password) >= 12;
}

function certdeploy_backup_build($password) {
	if (!certdeploy_backup_password_is_valid($password)) {
		return ['error' => gettext('Backup passphrase must be at least 12 characters.')];
	}
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

	$payload = json_encode([
		'config' => $config,
		'keys' => $keys,
	], JSON_UNESCAPED_SLASHES);
	if ($payload === false) {
		return ['error' => gettext('Unable to encode backup data.')];
	}

	$iterations = 200000;
	$salt = random_bytes(16);
	$nonce = random_bytes(12);
	$key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
	$tag = '';
	$ciphertext = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
	if ($ciphertext === false) {
		return ['error' => gettext('Unable to encrypt backup data.')];
	}

	return [
		'format' => 'certdeploy',
		'version' => 2,
		'created' => time(),
		'kdf' => 'pbkdf2-sha256',
		'iterations' => $iterations,
		'salt' => base64_encode($salt),
		'nonce' => base64_encode($nonce),
		'tag' => base64_encode($tag),
		'ciphertext' => base64_encode($ciphertext),
	];
}

function certdeploy_backup_download($password) {
	$backup = certdeploy_backup_build($password);
	if (isset($backup['error'])) {
		return $backup['error'];
	}
	$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	$filename = 'certdeploy-config-' . date('Ymd-His') . '.json';

	header('Content-Type: application/json');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Content-Length: ' . strlen($json));
	echo $json;
	exit;
}

function certdeploy_backup_parse($json, $password) {
	$data = json_decode($json, true);
	if (!is_array($data)) {
		return ['error' => gettext('The uploaded file is not valid JSON.')];
	}
	if (($data['format'] ?? '') !== 'certdeploy') {
		return ['error' => gettext('The uploaded file is not a CertDeploy backup.')];
	}
	if (($data['version'] ?? 0) !== 2 || ($data['kdf'] ?? '') !== 'pbkdf2-sha256') {
		return ['error' => gettext('The backup format is unsupported or unencrypted.')];
	}
	if (!certdeploy_backup_password_is_valid($password)) {
		return ['error' => gettext('Backup passphrase must be at least 12 characters.')];
	}
	$iterations = $data['iterations'] ?? 0;
	if (!is_int($iterations) || $iterations < 100000 || $iterations > 1000000) {
		return ['error' => gettext('The backup encryption parameters are invalid.')];
	}
	$salt = base64_decode($data['salt'] ?? '', true);
	$nonce = base64_decode($data['nonce'] ?? '', true);
	$tag = base64_decode($data['tag'] ?? '', true);
	$ciphertext = base64_decode($data['ciphertext'] ?? '', true);
	if ($salt === false || strlen($salt) !== 16 || $nonce === false || strlen($nonce) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
		return ['error' => gettext('The backup encryption data is invalid.')];
	}
	$key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
	$payload = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
	if ($payload === false) {
		return ['error' => gettext('Unable to decrypt backup. Check the passphrase.')];
	}
	$contents = json_decode($payload, true);
	if (!is_array($contents) || !is_array($contents['config'] ?? null) || !is_array($contents['keys'] ?? null)) {
		return ['error' => gettext('The decrypted backup data is invalid.')];
	}
	return ['data' => $contents];
}

function certdeploy_backup_restore($data) {
	$errors = [];

	$key_dir = CERTDEPLOY_KEYS_DIR;
	if (!is_dir($key_dir)) {
		mkdir($key_dir, 0700, true);
	}

	$keys = [];
	foreach ($data['keys'] as $id => $b64) {
		if (!certdeploy_identity_id_is_valid($id)) {
			$errors[] = gettext('Backup contains an invalid identity key ID.');
			continue;
		}
		$pem = base64_decode((string)$b64, true);
		if ($pem === false) {
			$errors[] = gettext('Backup contains malformed key data.');
			continue;
		}
		$keys[$id] = $pem;
	}

	$config = $data['config'];
	$identities = $config['identities']['item'] ?? [];
	if (!is_array($identities)) {
		return [gettext('Backup contains invalid identities.')];
	}
	foreach ($identities as &$identity) {
		$id = $identity['id'] ?? '';
		if (!certdeploy_identity_id_is_valid($id) || !isset($keys[$id])) {
			$errors[] = gettext('Backup contains an identity without a valid private key.');
			continue;
		}
		$identity['private_key_path'] = $key_dir . '/' . $id;
	}
	unset($identity);
	if (!empty($errors)) {
		return $errors;
	}

	foreach ($keys as $id => $pem) {
		$path = $key_dir . '/' . $id;
		$tmp = $path . '.tmp';
		file_put_contents($tmp, $pem);
		chmod($tmp, 0600);
		rename($tmp, $path);
	}

	$config['identities']['item'] = $identities;
	$config['identities'] = $config['identities'] ?? [];
	$config['deployments'] = $config['deployments'] ?? [];
	$config['config'] = $config['config'] ?? [];

	config_set_path('installedpackages/certdeploy', $config);
	write_config('CertDeploy configuration restored from backup');

	certdeploy_sync();
	return [];
}

?>
