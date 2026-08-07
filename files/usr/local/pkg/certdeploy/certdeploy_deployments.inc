<?php

require_once('certdeploy/certdeploy_acme.inc');
require_once('certdeploy/certdeploy_identities.inc');

function certdeploy_deployments() {
	return config_get_path('installedpackages/certdeploy/deployments/item', []);
}

function certdeploy_deployment_get($id) {
	foreach (certdeploy_deployments() as $idx => $dep) {
		if (($dep['id'] ?? '') === $id) {
			return [$idx, $dep];
		}
	}
	return [null, []];
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

function certdeploy_deployment_state($dep) {
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

function certdeploy_deployment_host_key_policies() {
	return ['fingerprint' => 'Require configured fingerprint'];
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

	$artifacts = certdeploy_parse_repeatables($post, ['artifact_source', 'artifact_filename', 'artifact_owner', 'artifact_file_group', 'artifact_mode']);
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
	$dep['connect_timeout'] = is_numericint($post['connect_timeout'] ?? '') ? (int)$post['connect_timeout'] : 0;
	$dep['cmd_timeout'] = is_numericint($post['cmd_timeout'] ?? '') ? (int)$post['cmd_timeout'] : 0;
	$dep['use_sftp'] = empty($post['use_sftp']) ? 'off' : 'on';
	$dep['legacy_scp_fallback'] = empty($post['legacy_scp_fallback']) ? 'off' : 'on';
	$dep['disable_connection_reuse'] = empty($post['disable_connection_reuse']) ? 'off' : 'on';
	$dep['ssh_opts'] = $post['ssh_opts'] ?? '';
	$dep['auto_deploy'] = empty($post['auto_deploy']) ? 'off' : 'on';
	$dep['readiness_check'] = empty($post['readiness_check']) ? 'off' : 'on';

	$dep['artifacts'] = ['row' => []];
	$artifacts = certdeploy_parse_repeatables($post, ['artifact_source', 'artifact_filename', 'artifact_owner', 'artifact_file_group', 'artifact_mode']);
	foreach ($artifacts as $a) {
		if (($a['artifact_source'] ?? '') === '' && ($a['artifact_filename'] ?? '') === '') {
			continue;
		}
		$dep['artifacts']['row'][] = [
			'source' => $a['artifact_source'],
			'filename' => $a['artifact_filename'],
			'owner' => $a['artifact_owner'] ?? '',
			'file_group' => $a['artifact_file_group'] ?? '',
			'mode' => $a['artifact_mode'] ?? '',
		];
	}

	$dep['destination'] = [
		'directory' => $post['destination_directory'] ?? '',
		'dir_owner' => $post['destination_dir_owner'] ?? '',
		'dir_group' => $post['destination_dir_group'] ?? '',
		'dir_mode' => $post['destination_dir_mode'] ?? '',
	];

	$dep['post_upload_cmd'] = $post['post_upload_cmd'] ?? '';
	$dep['validation_cmd'] = $post['validation_cmd'] ?? '';
	$dep['reload_cmd'] = $post['reload_cmd'] ?? '';

	// Preserve runtime state.
	$dep['last_deployed_fingerprint'] = $old['last_deployed_fingerprint'] ?? '';
	$dep['last_deployed_time'] = $old['last_deployed_time'] ?? '';
	$dep['last_run_status'] = $old['last_run_status'] ?? '';

	return $dep;
}

?>
