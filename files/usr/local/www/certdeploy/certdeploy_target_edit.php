<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

$id = $_GET['id'] ?? $_POST['id'] ?? '';
$dup = $_GET['dup'] ?? '';
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
} elseif ($dup !== '') {
	foreach (certdeploy_deployments() as $idx => $dep) {
		if (($dep['id'] ?? '') === $dup) {
			$pconfig = $dep;
			break;
		}
	}
	// Clear identity and runtime state so the copy is saved as a new deployment.
	unset($pconfig['id'], $pconfig['host_key_fingerprint'], $pconfig['last_deployed_fingerprint'], $pconfig['last_deployed_time'], $pconfig['last_run_status']);
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
				$idx = certdeploy_next_config_item_index('installedpackages/certdeploy/deployments');
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

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Deployments'), $is_new ? gettext('Add Target') : gettext('Edit Target')];
include('head.inc');

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

$tab_array = [
	[gettext('Settings'), false, '/certdeploy/certdeploy_settings.php'],
	[gettext('Deployments'), true, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Status'), false, '/certdeploy/certdeploy_status.php'],
	[gettext('Logs'), false, '/certdeploy/certdeploy_log.php'],
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
	certdeploy_deployment_host_key_fingerprint($pconfig)
))->setReadonly()->setHelp(gettext('Recorded on first SSH connection.'));

$section->addInput(new Form_Checkbox(
	'host_key_ignore_changes',
	gettext('Accept Host Key Changes'),
	gettext('Accept and record a changed SSH host key on every deployment while enabled'),
	($pconfig['host_key_ignore_changes'] ?? '') === 'on'
))->setHelp(gettext('Use only for targets that intentionally regenerate host keys. Disable after accepting a known change to pin the newly recorded key.'));

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
	<div class="col-sm-3">
		<select class="form-control artifact-source" name="artifact_source[]">
			<option value=""><?=gettext('Select source')?></option>
		</select>
	</div>
	<div class="col-sm-5">
		<input type="text" class="form-control artifact-filename" name="artifact_filename[]" placeholder="<?=gettext('Remote filename')?>" />
	</div>
	<div class="col-sm-2">
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
	$group->add($source_select)->setWidth(3);

	$group->add(new Form_Input(
		'artifact_filename' . $c,
		gettext('Remote filename'),
		'text',
		$art['filename'] ?? ''
	))->setAttribute('class', 'form-control artifact-filename')->setWidth(5);

	$group->add((new Form_Button(
		'delete_artifact' . $c,
		gettext('Delete'),
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning btn-sm delete-artifact')->setAttribute('type', 'button')->setWidth(2));

	$section->add($group);
}

// Add Artifact button.
$group = new Form_Group('');
$group->add((new Form_Button(
	'add_artifact',
	gettext('Add Artifact'),
	null,
	'fa-solid fa-plus'
))->addClass('btn-success btn-sm')->setAttribute('type', 'button')->setAttribute('id', 'add_artifact'));
$section->add($group);

// Hidden template for new destination rows.
?>
<div id="destination_template" class="form-group destination-row" style="display:none">
	<label class="col-sm-2 control-label"></label>
	<div class="col-sm-3">
		<input type="text" class="form-control destination-directory" name="destination_directory[]" placeholder="<?=gettext('Remote directory')?>" />
	</div>
	<div class="col-sm-2">
		<input type="text" class="form-control destination-owner" name="destination_owner[]" placeholder="<?=gettext('Owner')?>" />
	</div>
	<div class="col-sm-2">
		<input type="text" class="form-control destination-group" name="destination_group[]" placeholder="<?=gettext('Group')?>" />
	</div>
	<div class="col-sm-1">
		<input type="text" class="form-control destination-mode" name="destination_mode[]" placeholder="<?=gettext('Mode')?>" />
	</div>
	<div class="col-sm-2">
		<button type="button" class="btn btn-warning btn-sm delete-destination"><i class="fa-solid fa-trash-can"></i> <?=gettext('Delete')?></button>
	</div>
</div>
<?php

// Existing destination rows.
$dest_rows = [];
$dep_dest = $pconfig['destination'] ?? [];
if (!empty($dep_dest['directory'])) {
	$dest_rows[] = $dep_dest;
}
$dest_rows = array_merge($dest_rows, $pconfig['extra_destinations']['item'] ?? []);

foreach ($dest_rows as $c => $drow) {
	// Keep the label on the first row, blank on the rest.
	$group = new Form_Group($c === 0 ? gettext('Destinations') : '');
	$group->addClass('destination-row');

	$group->add(new Form_Input(
		'destination_directory' . $c,
		gettext('Remote directory'),
		'text',
		$drow['directory'] ?? ''
	))->setAttribute('class', 'form-control destination-directory')->setWidth(3);

	$group->add(new Form_Input(
		'destination_owner' . $c,
		gettext('Owner'),
		'text',
		$drow['owner'] ?? ''
	))->setAttribute('class', 'form-control destination-owner')->setWidth(2);

	$group->add(new Form_Input(
		'destination_group' . $c,
		gettext('Group'),
		'text',
		$drow['group'] ?? ''
	))->setAttribute('class', 'form-control destination-group')->setWidth(2);

	$group->add(new Form_Input(
		'destination_mode' . $c,
		gettext('Mode'),
		'text',
		$drow['mode'] ?? ''
	))->setAttribute('class', 'form-control destination-mode')->setWidth(1);

	$group->add((new Form_Button(
		'delete_destination' . $c,
		gettext('Delete'),
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning btn-sm delete-destination')->setAttribute('type', 'button')->setWidth(2));

	$section->add($group);
}

// Add Destination button.
$group = new Form_Group('');
$group->add((new Form_Button(
	'add_destination',
	gettext('Add Destination'),
	null,
	'fa-solid fa-plus'
))->addClass('btn-success btn-sm')->setAttribute('type', 'button')->setAttribute('id', 'add_destination')
)->setHelp(gettext('The first destination is uploaded via SCP; additional destinations are copied from it on the target.'));
$section->add($group);

$section->addInput(new Form_Checkbox(
	'auto_deploy',
	gettext('Deploy on Renewal'),
	gettext('Deploy when the ACME certificate changes'),
	($pconfig['auto_deploy'] ?? '') === 'on'
));

$section->addInput(new Form_Checkbox(
	'verify_files',
	gettext('Verify Files'),
	gettext('Compare remote files with local ACME files'),
	($pconfig['verify_files'] ?? 'on') !== 'off'
));

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

$section = new Form_Section(gettext('Advanced'), 'advanced-panel', COLLAPSIBLE|SEC_CLOSED);

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
			.data('filename', basename);
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
}

function certdeploy_add_artifact_row() {
	var $template = $('#artifact_template').clone();
	$template.removeAttr('id').show();
	$template.find('.artifact-source').attr('name', 'artifact_source[]');
	$template.find('.artifact-filename').attr('name', 'artifact_filename[]');
	certdeploy_populate_sources($template.find('.artifact-source'), '');
	var $last = $('.artifact-row').not('#artifact_template').filter(':visible').last();
	if ($last.length) {
		$last.after($template);
	} else {
		$('#add_artifact').closest('.form-group').before($template);
	}
	certdeploy_update_artifact_indexes();
}

// Re-number artifact row inputs sequentially (0, 1, 2, ...) and keep the
// label on the first row only, matching the first/last row markers.
function certdeploy_update_artifact_indexes() {
	var idx = 0;
	$('.artifact-row').not('#artifact_template').each(function() {
		var $row = $(this);
		$row.find('.artifact-source').attr('name', 'artifact_source' + idx);
		$row.find('.artifact-filename').attr('name', 'artifact_filename' + idx);
		$row.find('label').text(idx === 0 ? '<?=gettext("Artifacts")?>' : '');
		idx++;
	});
}

function certdeploy_add_destination_row() {
	var $template = $('#destination_template').clone();
	$template.removeAttr('id').show();
	$template.find('.destination-directory').attr('name', 'destination_directory[]');
	$template.find('.destination-owner').attr('name', 'destination_owner[]');
	$template.find('.destination-group').attr('name', 'destination_group[]');
	$template.find('.destination-mode').attr('name', 'destination_mode[]');
	var $last = $('.destination-row').not('#destination_template').filter(':visible').last();
	if ($last.length) {
		$last.after($template);
	} else {
		$('#add_destination').closest('.form-group').before($template);
	}
	certdeploy_update_destination_indexes();
}

// Re-number destination row inputs sequentially and keep the label on the first row only.
function certdeploy_update_destination_indexes() {
	var idx = 0;
	$('.destination-row').not('#destination_template').each(function() {
		var $row = $(this);
		$row.find('.destination-directory').attr('name', 'destination_directory' + idx);
		$row.find('.destination-owner').attr('name', 'destination_owner' + idx);
		$row.find('.destination-group').attr('name', 'destination_group' + idx);
		$row.find('.destination-mode').attr('name', 'destination_mode' + idx);
		$row.find('label').text(idx === 0 ? '<?=gettext("Destinations")?>' : '');
		idx++;
	});
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
			url: '/certdeploy/certdeploy_target_edit.php',
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

	$('#add_destination').click(function() {
		certdeploy_add_destination_row();
	});

	$(document).on('click', '.delete-artifact', function() {
		if ($('.artifact-row').length <= 1) {
			return;
		}
		$(this).closest('.artifact-row').remove();
		certdeploy_update_artifact_indexes();
	});

	$(document).on('click', '.delete-destination', function() {
		if ($('.destination-row').length <= 1) {
			return;
		}
		$(this).closest('.destination-row').remove();
		certdeploy_update_destination_indexes();
	});
});
//]]>
</script>

<?php include('foot.inc'); ?>
