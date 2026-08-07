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
	$runtime_state = certdeploy_deployment_runtime_state($id);
	if (empty($pconfig['host_key_fingerprint']) && !empty($runtime_state['host_key_fingerprint'])) {
		$pconfig['host_key_fingerprint'] = $runtime_state['host_key_fingerprint'];
	}
}

if ($_POST) {
	if (isset($_POST['act']) && $_POST['act'] === 'certfiles') {
		$name = $_POST['certificate_name'] ?? '';
		header('Content-Type: application/json');
		echo json_encode(certdeploy_acme_certificate_files($name));
		exit;
	}

	if (isset($_POST['save'])) {
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

$form->add($section);

// Target section
$section = new Form_Section(gettext('Target Connection'));

$group = new Form_Group(gettext('Host'));
$group->add(new Form_Input(
	'host',
	gettext('Host'),
	'text',
	$pconfig['host'] ?? ''
))->setHelp(gettext('Hostname or IP address of the remote SSH server.'))->setWidth(8);

$group->add(new Form_Input(
	'port',
	gettext('Port'),
	'number',
	$pconfig['port'] ?? 22,
	['min' => 1, 'max' => 65535]
))->setHelp(gettext('SSH port.'))->setWidth(2);

$section->add($group);

$section->addInput(new Form_Select(
	'identity_id',
	gettext('SSH Identity'),
	$pconfig['identity_id'] ?? '',
	certdeploy_identity_options()
))->setHelp(gettext('SSH identity (key pair and login user) to use for authentication. The identity name is used as the SSH login user.'));

$section->addInput(new Form_Input(
	'host_key_fingerprint',
	gettext('Host Key Fingerprint'),
	'text',
	$pconfig['host_key_fingerprint'] ?? ''
))->setReadonly()->setHelp(gettext('SHA256 fingerprint of the remote host key. Automatically imported on first connect.'));

$section->addInput(new Form_Checkbox(
	'host_key_ignore_changes',
	gettext('Ignore Changes'),
	gettext('Accept changed host key fingerprints (disable strict host-key checking on mismatch)'),
	($pconfig['host_key_ignore_changes'] ?? '') === 'on'
));

$form->add($section);

// Files section
$section = new Form_Section(gettext('Files'));

$section->addInput(new Form_Select(
	'certificate_name',
	gettext('ACME Certificate'),
	$pconfig['certificate_name'] ?? '',
	certdeploy_acme_certificate_options()
))->setHelp(gettext('ACME certificate to deploy. Leave empty for a connection-only deployment.'));

$cert_files = ($pconfig['certificate_name'] ?? '') !== '' ? certdeploy_acme_certificate_files($pconfig['certificate_name']) : [];

// Hidden template for new artifact rows.
?>
<div id="artifact_template" class="form-group artifact-row" style="display:none">
	<label class="col-sm-2 control-label"></label>
	<div class="col-sm-2">
		<select class="form-control artifact-source" name="artifact_source[]">
			<option value=""><?=gettext('Select source')?></option>
		</select>
	</div>
	<div class="col-sm-2">
		<input type="text" class="form-control artifact-filename" name="artifact_filename[]" placeholder="<?=gettext('Remote filename')?>" />
	</div>
	<div class="col-sm-2">
		<input type="text" class="form-control artifact-owner" name="artifact_owner[]" placeholder="<?=gettext('Owner')?>" />
	</div>
	<div class="col-sm-2">
		<input type="text" class="form-control artifact-group" name="artifact_file_group[]" placeholder="<?=gettext('Group')?>" />
	</div>
	<div class="col-sm-1">
		<input type="text" class="form-control artifact-mode" name="artifact_mode[]" placeholder="<?=gettext('Mode')?>" />
	</div>
	<div class="col-sm-1">
		<button type="button" class="btn btn-warning btn-sm delete-artifact"><i class="fa-solid fa-trash-can"></i> <?=gettext('Delete')?></button>
	</div>
</div>
<?php

// Existing artifact rows.
$artifacts = $pconfig['artifacts']['row'] ?? [];
foreach ($artifacts as $c => $art) {
	$group = new Form_Group($c === 0 ? gettext('Artifacts') : '');
	$group->addClass('artifact-row');

	$source_select = new Form_Select(
		'artifact_source' . $c,
		gettext('Source'),
		$art['source'] ?? '',
		certdeploy_deployment_artifact_sources($pconfig['certificate_name'] ?? '')
	);
	$source_select->setAttribute('class', 'form-control artifact-source');
	$group->add($source_select)->setWidth(2);

	$group->add(new Form_Input(
		'artifact_filename' . $c,
		gettext('Filename'),
		'text',
		$art['filename'] ?? ''
	))->setAttribute('class', 'form-control artifact-filename')->setWidth(2);

	$group->add(new Form_Input(
		'artifact_owner' . $c,
		gettext('Owner'),
		'text',
		$art['owner'] ?? ''
	))->setAttribute('class', 'form-control artifact-owner')->setWidth(2);

	$group->add(new Form_Input(
		'artifact_file_group' . $c,
		gettext('Group'),
		'text',
		$art['file_group'] ?? ''
	))->setAttribute('class', 'form-control artifact-group')->setWidth(2);

	$group->add(new Form_Input(
		'artifact_mode' . $c,
		gettext('Mode'),
		'text',
		$art['mode'] ?? ''
	))->setAttribute('class', 'form-control artifact-mode')->setWidth(1);

	$group->add((new Form_Button(
		'delete_artifact' . $c,
		gettext('Delete'),
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning btn-sm delete-artifact')->setAttribute('type', 'button')->setWidth(1));

	$section->add($group);
}

// Add Artifact button.
$section->addInput((new Form_Button(
	'add_artifact',
	gettext('Add Artifact'),
	null,
	'fa-solid fa-plus'
))->addClass('btn-success btn-sm')->setAttribute('type', 'button')->setAttribute('id', 'add_artifact'));

// Destination (single, required).
$dest = $pconfig['destination'] ?? [];
$group = new Form_Group(gettext('Destination'));
$group->add(new Form_Input(
	'destination_directory',
	gettext('Directory'),
	'text',
	$dest['directory'] ?? ''
))->setHelp(gettext('Remote directory where files will be placed. Created if missing.'))->setWidth(3);

$group->add(new Form_Input(
	'destination_dir_owner',
	gettext('Owner'),
	'text',
	$dest['dir_owner'] ?? ''
))->setWidth(2);

$group->add(new Form_Input(
	'destination_dir_group',
	gettext('Group'),
	'text',
	$dest['dir_group'] ?? ''
))->setWidth(2);

$group->add(new Form_Input(
	'destination_dir_mode',
	gettext('Mode'),
	'text',
	$dest['dir_mode'] ?? ''
))->setWidth(1);

$section->add($group);

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
	['min' => 0]
))->setHelp(gettext('Override default SSH connect timeout. Leave blank or set to 0 to use default.'));

$section->addInput(new Form_Input(
	'cmd_timeout',
	gettext('Command Timeout'),
	'number',
	$pconfig['cmd_timeout'] ?? '',
	['min' => 0]
))->setHelp(gettext('Override default command timeout. Leave blank or set to 0 to use default.'));

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
	'disable_connection_reuse',
	gettext('Disable Connection Reuse'),
	gettext('Use a separate SSH connection for every file and command'),
	($pconfig['disable_connection_reuse'] ?? '') === 'on'
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
var certdeployCertFiles = <?=json_encode($cert_files)?>;

function certdeploy_populate_sources($select, selected) {
	$select.empty();
	$select.append($('<option value=""></option>').text('<?=gettext("Select source")?>'));
	$.each(certdeployCertFiles, function(basename, info) {
		var $opt = $('<option></option>')
			.attr('value', basename)
			.text(info.label)
			.data('filename', basename)
			.data('owner', info.owner)
			.data('group', info.group)
			.data('mode', info.mode);
		if (basename === selected) {
			$opt.prop('selected', true);
		}
		$select.append($opt);
	});
}

function certdeploy_autofill_row($row) {
	var $opt = $row.find('.artifact-source option:selected');
	if (!$opt.val()) {
		return;
	}
	var $filename = $row.find('.artifact-filename');
	if ($filename.val() === '') {
		$filename.val($opt.data('filename'));
	}
	var $owner = $row.find('.artifact-owner');
	if ($owner.val() === '') {
		$owner.val($opt.data('owner'));
	}
	var $group = $row.find('.artifact-group');
	if ($group.val() === '') {
		$group.val($opt.data('group'));
	}
	var $mode = $row.find('.artifact-mode');
	if ($mode.val() === '') {
		$mode.val($opt.data('mode'));
	}
}

function certdeploy_next_artifact_index() {
	var max = -1;
	$('.artifact-source').each(function() {
		var name = $(this).attr('name');
		var m = name.match(/artifact_source(\d+)$/);
		if (m) {
			var idx = parseInt(m[1], 10);
			if (idx > max) {
				max = idx;
			}
		}
	});
	return max + 1;
}

function certdeploy_add_artifact_row() {
	var $template = $('#artifact_template').clone();
	$template.removeAttr('id').show();
	var idx = certdeploy_next_artifact_index();
	$template.find('.artifact-source').attr('name', 'artifact_source' + idx);
	$template.find('.artifact-filename').attr('name', 'artifact_filename' + idx);
	$template.find('.artifact-owner').attr('name', 'artifact_owner' + idx);
	$template.find('.artifact-group').attr('name', 'artifact_file_group' + idx);
	$template.find('.artifact-mode').attr('name', 'artifact_mode' + idx);
	certdeploy_populate_sources($template.find('.artifact-source'), '');
	$('#add_artifact').closest('.form-group').before($template);
}

events.push(function() {
	// Ensure existing artifact source dropdowns have data attributes for autofill.
	$('.artifact-source').each(function() {
		certdeploy_populate_sources($(this), $(this).val());
	});

	$('#certificate_name').change(function() {
		var cert = $(this).val();
		if (cert === '') {
			certdeployCertFiles = {};
			$('.artifact-source').empty().append($('<option value=""></option>').text('<?=gettext("Select certificate first")?>'));
			return;
		}
		$.ajax({
			url: '/certdeploy/certdeploy_deployment_edit.php',
			type: 'POST',
			data: {
				act: 'certfiles',
				certificate_name: cert
			},
			success: function(data) {
				certdeployCertFiles = data;
				$('.artifact-source').each(function() {
					var selected = $(this).val();
					certdeploy_populate_sources($(this), selected);
				});
			}
		});
	});

	$(document).on('change', '.artifact-source', function() {
		certdeploy_autofill_row($(this).closest('.artifact-row'));
	});

	$('#add_artifact').click(function() {
		certdeploy_add_artifact_row();
	});

	$(document).on('click', '.delete-artifact', function() {
		$(this).closest('.artifact-row').remove();
	});
});
//]]>
</script>

<?php include('foot.inc'); ?>
