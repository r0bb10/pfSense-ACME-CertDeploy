<?php

require_once('certdeploy/certdeploy_acme.inc');
require_once('certdeploy/certdeploy_identities.inc');

function certdeploy_deployments() {
	return config_get_path('installedpackages/certdeploy/deployments/item', []);
}

function certdeploy_deployment_runtime_state($dep_id) {
	$path = '/var/db/certdeploy/state.json';
	if (!is_readable($path)) {
		return [];
	}
	$data = json_decode(file_get_contents($path), true);
	if (!is_array($data) || !is_array($data['deployments'] ?? null)) {
		return [];
	}
	return $data['deployments'][$dep_id] ?? [];
}

function certdeploy_deployment_display_status($dep) {
	if (($dep['enabled'] ?? '') !== 'on') {
		return 'disabled';
	}
	$state = certdeploy_deployment_runtime_state($dep['id'] ?? '');
	$status = $state['last_run_status'] ?? $dep['last_run_status'] ?? '';
	if ($status === '') {
		return 'never deployed';
	}
	return $status;
}

function certdeploy_deployment_status_text($dep) {
	$state = certdeploy_deployment_runtime_state($dep['id'] ?? '');
	$time = $state['last_deployed_time'] ?? $dep['last_deployed_time'] ?? 0;
	$status = $state['last_run_status'] ?? $dep['last_run_status'] ?? '';
	if ($status === '') {
		$status = 'never';
	}
	return [
		'time' => $time,
		'status' => $status,
	];
}

function certdeploy_deployment_sync_status($dep) {
	$cert_name = $dep['certificate_name'] ?? '';
	$stored = certdeploy_deployment_runtime_state($dep['id'] ?? '')['last_deployed_fingerprint'] ?? $dep['last_deployed_fingerprint'] ?? '';
	if ($cert_name === '') {
		return ['state' => 'nocert', 'stored' => $stored, 'current' => '', 'message' => gettext('No certificate')];
	}
	$certs = certdeploy_acme_certificates();
	if (!isset($certs[$cert_name])) {
		return ['state' => 'missing', 'stored' => $stored, 'current' => '', 'message' => gettext('Certificate missing')];
	}
	if (($certs[$cert_name]['status'] ?? '') !== 'active') {
		return ['state' => 'disabled', 'stored' => $stored, 'current' => '', 'message' => gettext('Certificate disabled')];
	}
	$current = certdeploy_acme_certificate_fingerprint($cert_name);
	if ($current === '') {
		return ['state' => 'error', 'stored' => $stored, 'current' => '', 'message' => gettext('Cannot read certificate')];
	}
	if ($stored === '') {
		return ['state' => 'outdated', 'stored' => '', 'current' => $current, 'message' => gettext('Never deployed')];
	}
	if ($current === $stored) {
		return ['state' => 'synced', 'stored' => $stored, 'current' => $current, 'message' => gettext('In sync')];
	}
	return ['state' => 'outdated', 'stored' => $stored, 'current' => $current, 'message' => gettext('Outdated')];
}

function certdeploy_deployment_artifact_sources($certificate_name) {
	if ($certificate_name === '') {
		return [];
	}
	$files = [];
	foreach (certdeploy_acme_certificate_files($certificate_name) as $basename => $info) {
		$files[$basename] = $info['label'];
	}
	return $files;
}

function certdeploy_deployment_validate(&$post, &$input_errors) {
	$name = trim($post['name'] ?? '');
	if ($name === '') {
		$input_errors[] = gettext('Name is required.');
	} elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $name)) {
		$input_errors[] = gettext('Name may contain only letters, numbers, dots, underscores, and hyphens.');
	}

	$cert = $post['certificate_name'] ?? '';
	if ($cert !== '') {
		$certs = certdeploy_acme_certificate_options();
		if (!isset($certs[$cert])) {
			$input_errors[] = gettext('A valid ACME certificate must be selected.');
		}
	}
	$fingerprint = trim($post['host_key_fingerprint'] ?? '');
	if ($fingerprint !== '' && !preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/', $fingerprint)) {
		$input_errors[] = gettext('Host key fingerprint must be a valid SHA256 fingerprint.');
	}

	if (($post['host'] ?? '') === '') {
		$input_errors[] = gettext('Host is required.');
	}
	if (!is_numericint($post['port'] ?? '') || (int)$post['port'] < 1 || (int)$post['port'] > 65535) {
		$input_errors[] = gettext('Port must be a number between 1 and 65535.');
	}

	$ident = $post['identity_id'] ?? '';
	if ($ident === '' || !isset(certdeploy_identity_options()[$ident])) {
		$input_errors[] = gettext('A valid SSH identity must be selected.');
	}

	$artifacts = certdeploy_parse_repeatables($post, ['artifact_source', 'artifact_filename']);
	$sources = certdeploy_deployment_artifact_sources($cert);
	foreach ($artifacts as $a) {
		if (($a['artifact_source'] ?? '') === '' && ($a['artifact_filename'] ?? '') === '') {
			continue;
		}
		if (($a['artifact_source'] ?? '') === '') {
			$input_errors[] = gettext('Artifact source is required.');
		}
		if (($a['artifact_filename'] ?? '') === '') {
			$input_errors[] = gettext('Artifact filename is required.');
		}
		if (($a['artifact_source'] ?? '') !== '' && !isset($sources[$a['artifact_source']])) {
			$input_errors[] = gettext('Artifact source must belong to the selected ACME certificate.');
		}
		$filename = $a['artifact_filename'] ?? '';
		if ($filename !== '' && ($filename !== basename($filename) || $filename === '.' || $filename === '..')) {
			$input_errors[] = gettext('Artifact filename must be a filename, not a path.');
		}
	}

	$destinations = certdeploy_parse_repeatables($post, ['destination_directory', 'destination_owner', 'destination_group', 'destination_mode']);
	$has_artifacts = false;
	foreach ($artifacts as $artifact) {
		if (($artifact['artifact_source'] ?? '') !== '' || ($artifact['artifact_filename'] ?? '') !== '') {
			$has_artifacts = true;
			break;
		}
	}
	$has_destination = false;
	foreach ($destinations as $d) {
		if (($d['destination_directory'] ?? '') === '') {
			continue;
		}
		$has_destination = true;
	}
	if ($has_artifacts && !$has_destination) {
		$input_errors[] = gettext('A destination directory is required when artifacts are configured.');
	}

}

function certdeploy_parse_repeatables($post, $fields) {
	$rows = [];
	if (!is_array($fields)) {
		return $rows;
	}
	foreach ($fields as $field) {
		$x = 0;
		while (isset($post[$field . $x])) {
			$rows[$x][$field] = $post[$field . $x];
			$x++;
		}
	}
	return array_values($rows);
}

function certdeploy_deployment_build($post, $old = []) {
	$dep = [];
	$dep['id'] = $old['id'] ?? certdeploy_uuid();
	$dep['name'] = $post['name'];
	$dep['enabled'] = empty($post['enabled']) ? 'off' : 'on';
	$dep['description'] = $post['description'] ?? '';
	$dep['certificate_name'] = $post['certificate_name'];
	$dep['host'] = $post['host'];
	$dep['port'] = (int)$post['port'];
	$dep['identity_id'] = $post['identity_id'];
	$dep['host_key_fingerprint'] = $post['host_key_fingerprint'] ?? '';
	$dep['host_key_ignore_changes'] = empty($post['host_key_ignore_changes']) ? 'off' : 'on';
	$dep['connect_timeout'] = is_numericint($post['connect_timeout'] ?? '') && (int)$post['connect_timeout'] <= 86400 ? (int)$post['connect_timeout'] : 0;
	$dep['cmd_timeout'] = is_numericint($post['cmd_timeout'] ?? '') && (int)$post['cmd_timeout'] <= 86400 ? (int)$post['cmd_timeout'] : 0;
	$dep['legacy_scp_fallback'] = empty($post['legacy_scp_fallback']) ? 'off' : 'on';
	$dep['disable_connection_reuse'] = empty($post['disable_connection_reuse']) ? 'off' : 'on';
	$dep['auto_deploy'] = empty($post['auto_deploy']) ? 'off' : 'on';

	$dep['artifacts'] = ['row' => []];
	$artifacts = certdeploy_parse_repeatables($post, ['artifact_source', 'artifact_filename']);
	foreach ($artifacts as $a) {
		if (($a['artifact_source'] ?? '') === '' && ($a['artifact_filename'] ?? '') === '') {
			continue;
		}
		$dep['artifacts']['row'][] = [
			'source' => $a['artifact_source'],
			'filename' => $a['artifact_filename'],
		];
	}

	// First destination row is the primary (SCP upload); the rest are extra
	// destinations copied on the target.
	$destinations = certdeploy_parse_repeatables($post, ['destination_directory', 'destination_owner', 'destination_group', 'destination_mode']);
	$destinations = array_values(array_filter($destinations, function ($d) {
		return ($d['destination_directory'] ?? '') !== '';
	}));
	$primary = array_shift($destinations);
	$dep['destination'] = [
		'directory' => $primary['destination_directory'] ?? '',
		'owner' => $primary['destination_owner'] ?? '',
		'group' => $primary['destination_group'] ?? '',
		'mode' => $primary['destination_mode'] ?? '',
	];
	$dep['extra_destinations'] = ['item' => []];
	foreach ($destinations as $d) {
		$dep['extra_destinations']['item'][] = [
			'directory' => $d['destination_directory'],
			'owner' => $d['destination_owner'] ?? '',
			'group' => $d['destination_group'] ?? '',
			'mode' => $d['destination_mode'] ?? '',
		];
	}

	$dep['post_upload_cmd'] = $post['post_upload_cmd'] ?? '';
	$dep['validation_cmd'] = $post['validation_cmd'] ?? '';
	$dep['reload_cmd'] = $post['reload_cmd'] ?? '';

	// Preserve runtime state.
	$dep['last_deployed_fingerprint'] = $old['last_deployed_fingerprint'] ?? '';
	$dep['last_deployed_time'] = $old['last_deployed_time'] ?? '';
	$dep['last_run_status'] = $old['last_run_status'] ?? '';

	return $dep;
}

// Reorder an indexed item list (identities or deployments) by the given
// ordered IDs. Any items not listed keep their relative order at the end.
function certdeploy_reorder_items($base_path, $ordered_ids) {
	$items = config_get_path($base_path, []);
	if (!is_array($items) || empty($items)) {
		return false;
	}

	$by_id = [];
	foreach ($items as $item) {
		$by_id[$item['id'] ?? ''] = $item;
	}

	$new = [];
	$seen = [];
	foreach ($ordered_ids as $id) {
		if (isset($by_id[$id])) {
			$new[] = $by_id[$id];
			$seen[$id] = true;
		}
	}

	// Append any items not referenced in the posted order.
	foreach ($items as $item) {
		$id = $item['id'] ?? '';
		if (!isset($seen[$id])) {
			$new[] = $item;
		}
	}

	if (count($new) !== count($items)) {
		return false;
	}

	config_set_path($base_path, $new);
	return true;
}

?>
