<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

$id = $_GET['id'] ?? $_POST['id'] ?? '';
$is_new = true;
$pconfig = [];

if ($id !== '') {
	foreach (certdeploy_deployments() as $idx => $dep) {
		if (($dep['id'] ?? '') === $id) {
			$pconfig = $dep;
			$is_new = false;
			break;
		}
	}
}

if ($_POST && isset($_POST['save'])) {
	$input_errors = [];
	certdeploy_deployment_validate($_POST, $input_errors);
	if (empty($input_errors)) {
		$dep = certdeploy_deployment_build($_POST, $pconfig);
		if ($is_new) {
			$idx = certdeploy_array_next_index('installedpackages/certdeploy/deployments');
		} else {
			foreach (certdeploy_deployments() as $i => $existing) {
				if (($existing['id'] ?? '') === $id) {
					$idx = $i;
					break;
				}
			}
		}
		config_set_path("installedpackages/certdeploy/deployments/item/{$idx}", $dep);
		write_config('CertDeploy deployment saved');
		certdeploy_sync();
		header('Location: /certdeploy/certdeploy_deployments.php');
		exit;
	}
	// On validation error, keep form values.
	$pconfig = certdeploy_deployment_build($_POST, $pconfig);
}

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Deployments'), $is_new ? gettext('Add') : gettext('Edit')];
include('head.inc');

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

$tab_array = [
	[gettext('General'), false, '/certdeploy/certdeploy_general.php'],
	[gettext('Identities'), false, '/certdeploy/certdeploy_identities.php'],
	[gettext('Deployments'), true, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Log'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

$form = new Form(false);

// General section
$section = new Form_Section(gettext('General'));

$section->addInput(new Form_Checkbox(
	'enabled',
	gettext('Enable'),
	gettext('Enable this deployment'),
	($pconfig['enabled'] ?? '') === 'on'
));

$section->addInput(new Form_Input(
	'name',
	gettext('Name'),
	'text',
	$pconfig['name'] ?? ''
));

$section->addInput(new Form_Input(
	'description',
	gettext('Description'),
	'text',
	$pconfig['description'] ?? ''
));

$section->addInput(new Form_Select(
	'certificate_name',
	gettext('ACME Certificate'),
	$pconfig['certificate_name'] ?? '',
	certdeploy_acme_certificate_options()
));

$form->add($section);

// Target section
$section = new Form_Section(gettext('Target Connection'));

$section->addInput(new Form_Input(
	'host',
	gettext('Host'),
	'text',
	$pconfig['host'] ?? ''
))->setHelp(gettext('Hostname or IP address of the remote SSH server.'));

$section->addInput(new Form_Input(
	'port',
	gettext('Port'),
	'number',
	$pconfig['port'] ?? 22,
	['min' => 1, 'max' => 65535]
));

$section->addInput(new Form_Input(
	'user',
	gettext('User'),
	'text',
	$pconfig['user'] ?? 'root'
));

$section->addInput(new Form_Select(
	'identity_id',
	gettext('SSH Identity'),
	$pconfig['identity_id'] ?? '',
	certdeploy_identity_options()
));

$section->addInput(new Form_Input(
	'host_key_fingerprint',
	gettext('Host Key Fingerprint'),
	'text',
	$pconfig['host_key_fingerprint'] ?? ''
))->setHelp(gettext('SHA256 fingerprint of the remote host key. Use the scan helper to populate this.'));

$form->add($section);

// Files section
$section = new Form_Section(gettext('Files'));

$artifacts = $pconfig['artifacts']['row'] ?? [[]];
$last = count($artifacts) - 1;
foreach ($artifacts as $c => $art) {
	$group = new Form_Group($c === 0 ? gettext('Artifacts') : '');
	$group->addClass('repeatable');

	$group->add(new Form_Select(
		'artifact_type' . $c,
		gettext('Type'),
		$art['type'] ?? '',
		certdeploy_deployment_artifact_types()
	))->setWidth(2);

	$group->add(new Form_Input(
		'artifact_filename' . $c,
		gettext('Filename'),
		'text',
		$art['filename'] ?? ''
	))->setHelp($c === $last ? gettext('Remote filename for this artifact.') : '')->setWidth(2);

	$group->add(new Form_Input(
		'artifact_owner' . $c,
		gettext('Owner'),
		'text',
		$art['owner'] ?? ''
	))->setWidth(2);

	$group->add(new Form_Input(
		'artifact_group' . $c,
		gettext('Group'),
		'text',
		$art['group'] ?? ''
	))->setWidth(2);

	$group->add(new Form_Input(
		'artifact_mode' . $c,
		gettext('Mode'),
		'text',
		$art['mode'] ?? ''
	))->setWidth(1);

	$group->add(new Form_Button(
		'delete_row' . $c,
		gettext('Delete'),
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning btn-sm')->setWidth(1);

	$section->add($group);
}

$section->addInput(new Form_Button(
	'add_row',
	gettext('Add Artifact'),
	null,
	'fa-solid fa-plus'
))->addClass('btn-success btn-sm addbtn');

$destinations = $pconfig['destinations']['row'] ?? [[]];
$last = count($destinations) - 1;
foreach ($destinations as $c => $dest) {
	$group = new Form_Group($c === 0 ? gettext('Destinations') : '');
	$group->addClass('repeatable');

	$group->add(new Form_Input(
		'destination_directory' . $c,
		gettext('Directory'),
		'text',
		$dest['directory'] ?? ''
	))->setHelp($c === $last ? gettext('Remote directory where files will be placed.') : '')->setWidth(3);

	$group->add(new Form_Checkbox(
		'destination_create_dir' . $c,
		gettext('Create'),
		gettext('Create if missing'),
		($dest['create_dir'] ?? '') === 'on'
	))->setWidth(1);

	$group->add(new Form_Input(
		'destination_dir_owner' . $c,
		gettext('Owner'),
		'text',
		$dest['dir_owner'] ?? ''
	))->setWidth(2);

	$group->add(new Form_Input(
		'destination_dir_group' . $c,
		gettext('Group'),
		'text',
		$dest['dir_group'] ?? ''
	))->setWidth(2);

	$group->add(new Form_Input(
		'destination_dir_mode' . $c,
		gettext('Mode'),
		'text',
		$dest['dir_mode'] ?? ''
	))->setWidth(1);

	$group->add(new Form_Button(
		'delete_row' . $c,
		gettext('Delete'),
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning btn-sm')->setWidth(1);

	$section->add($group);
}

$section->addInput(new Form_Button(
	'add_row',
	gettext('Add Destination'),
	null,
	'fa-solid fa-plus'
))->addClass('btn-success btn-sm addbtn');

$form->add($section);

// Commands section
$section = new Form_Section(gettext('Commands'));

$section->addInput(new Form_Input(
	'post_upload_cmd',
	gettext('Post-upload Command'),
	'text',
	$pconfig['post_upload_cmd'] ?? ''
))->setHelp(gettext('Optional command to run after files are uploaded, before activation.'));

$section->addInput(new Form_Input(
	'validation_cmd',
	gettext('Validation Command'),
	'text',
	$pconfig['validation_cmd'] ?? ''
))->setHelp(gettext('Optional command to validate configuration before reload.'));

$section->addInput(new Form_Input(
	'reload_cmd',
	gettext('Reload Command'),
	'text',
	$pconfig['reload_cmd'] ?? ''
))->setHelp(gettext('Command to restart or reload the remote service.'));

$form->add($section);

// Advanced section
$section = new Form_Section(gettext('Advanced'));

$section->addInput(new Form_Input(
	'connect_timeout',
	gettext('Connect Timeout'),
	'number',
	$pconfig['connect_timeout'] ?? '',
	['min' => 1]
))->setHelp(gettext('Override default SSH connect timeout. Leave blank to use default.'));

$section->addInput(new Form_Input(
	'cmd_timeout',
	gettext('Command Timeout'),
	'number',
	$pconfig['cmd_timeout'] ?? '',
	['min' => 1]
))->setHelp(gettext('Override default command timeout. Leave blank to use default.'));

$section->addInput(new Form_Checkbox(
	'use_sftp',
	gettext('Use SFTP'),
	gettext('Use SCP-over-SFTP mode'),
	($pconfig['use_sftp'] ?? '') === 'on'
));

$section->addInput(new Form_Checkbox(
	'legacy_scp_fallback',
	gettext('Legacy SCP'),
	gettext('Fallback to legacy scp -O if SFTP fails'),
	($pconfig['legacy_scp_fallback'] ?? '') === 'on'
));

$section->addInput(new Form_Checkbox(
	'auto_deploy',
	gettext('Auto Deploy'),
	gettext('Deploy automatically when certificate fingerprint changes'),
	($pconfig['auto_deploy'] ?? '') === 'on'
));

$section->addInput(new Form_Checkbox(
	'readiness_check',
	gettext('Readiness Check'),
	gettext('Check target readiness before deployment'),
	($pconfig['readiness_check'] ?? '') === 'on'
));

$section->addInput(new Form_Input(
	'ssh_opts',
	gettext('Extra SSH Options'),
	'text',
	$pconfig['ssh_opts'] ?? ''
))->setHelp(gettext('Additional ssh/scp options as space-separated arguments.'));

$form->add($section);

$form->addGlobal(new Form_Button(
	'save',
	gettext('Save'),
	null,
	'fa-solid fa-save'
))->addClass('btn-primary btn-sm');

print($form);

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Standard pfSense repeatable row handling.
	checkLastRow();
});
//]]>
</script>

<?php include('foot.inc'); ?>
