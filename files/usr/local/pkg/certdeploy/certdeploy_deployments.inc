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

function certdeploy_deployment_state($dep) {
	if (($dep['enabled'] ?? '') !== 'on') {
		return 'disabled';
	}
	if (($dep['last_run_status'] ?? '') === '') {
		return 'never deployed';
	}
	return $dep['last_run_status'];
}

function certdeploy_deployment_artifact_types() {
	return ['crt' => 'Certificate', 'key' => 'Private Key', 'fullchain' => 'Full Chain'];
}

function certdeploy_deployment_default_filename($type) {
	$map = ['crt' => 'cert.crt', 'key' => 'cert.key', 'fullchain' => 'fullchain.crt'];
	return $map[$type] ?? '';
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
	$certs = certdeploy_acme_certificate_options();
	if ($cert === '' || !isset($certs[$cert])) {
		$input_errors[] = gettext('A valid ACME certificate must be selected.');
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

	$artifacts = certdeploy_parse_repeatables($post, ['artifact_type', 'artifact_filename', 'artifact_owner', 'artifact_file_group', 'artifact_mode']);
	if (empty($artifacts)) {
		$input_errors[] = gettext('At least one artifact must be configured.');
	}
	foreach ($artifacts as $a) {
		if (($a['artifact_type'] ?? '') === '') {
			$input_errors[] = gettext('Artifact type is required.');
		}
		if (($a['artifact_filename'] ?? '') === '') {
			$input_errors[] = gettext('Artifact filename is required.');
		}
	}

	$destinations = certdeploy_parse_repeatables($post, ['destination_directory', 'destination_create_dir', 'destination_dir_owner', 'destination_dir_group', 'destination_dir_mode']);
	if (empty($destinations)) {
		$input_errors[] = gettext('At least one destination directory is required.');
	}
	foreach ($destinations as $d) {
		if (($d['destination_directory'] ?? '') === '') {
			$input_errors[] = gettext('Destination directory is required.');
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
	$dep['ssh_opts'] = $post['ssh_opts'] ?? '';
	$dep['auto_deploy'] = empty($post['auto_deploy']) ? 'off' : 'on';
	$dep['readiness_check'] = empty($post['readiness_check']) ? 'off' : 'on';

	$dep['artifacts'] = ['row' => []];
	$artifacts = certdeploy_parse_repeatables($post, ['artifact_type', 'artifact_filename', 'artifact_owner', 'artifact_file_group', 'artifact_mode']);
	foreach ($artifacts as $a) {
		$dep['artifacts']['row'][] = [
			'type' => $a['artifact_type'],
			'filename' => $a['artifact_filename'],
			'owner' => $a['artifact_owner'] ?? '',
			'file_group' => $a['artifact_file_group'] ?? '',
			'mode' => $a['artifact_mode'] ?? '',
		];
	}

	$dep['destinations'] = ['row' => []];
	$destinations = certdeploy_parse_repeatables($post, ['destination_directory', 'destination_create_dir', 'destination_dir_owner', 'destination_dir_group', 'destination_dir_mode']);
	foreach ($destinations as $d) {
		$dep['destinations']['row'][] = [
			'directory' => $d['destination_directory'],
			'create_dir' => empty($d['destination_create_dir']) ? 'off' : 'on',
			'dir_owner' => $d['destination_dir_owner'] ?? '',
			'dir_group' => $d['destination_dir_group'] ?? '',
			'dir_mode' => $d['destination_dir_mode'] ?? '',
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

?>
