<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('service-utils.inc');
require_once('pfsense-utils.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

if (isset($_GET['download'])) {
	certdeploy_backup_download();
}

$settings = certdeploy_settings();
$defaults = certdeploy_general_defaults();
foreach ($defaults as $k => $v) {
	if (!isset($settings[$k])) {
		$settings[$k] = $v;
	}
}

if ($_POST) {
	$input_errors = [];

	if (isset($_POST['restore'])) {
		if (is_uploaded_file($_FILES['restore_file']['tmp_name'] ?? '')) {
			$json = file_get_contents($_FILES['restore_file']['tmp_name']);
			$parsed = certdeploy_backup_parse($json);
			if (isset($parsed['error'])) {
				$input_errors[] = $parsed['error'];
			} else {
				$errors = certdeploy_backup_restore($parsed['data']);
				if (!empty($errors)) {
					$input_errors = array_merge($input_errors, $errors);
				} else {
					header('Location: /certdeploy/certdeploy_status.php');
					exit;
				}
			}
		} else {
			$input_errors[] = gettext('Please choose a CertDeploy backup file to restore.');
		}
		if (!empty($input_errors)) {
			$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Settings')];
			include('head.inc');
			print_input_errors($input_errors);
			include('foot.inc');
			exit;
		}
	}

	certdeploy_general_validate($_POST, $input_errors);
	if (empty($input_errors)) {
		config_set_path('installedpackages/certdeploy/config/0', certdeploy_general_build($_POST));
		write_config('CertDeploy general settings updated');
		certdeploy_sync();
		header('Location: /certdeploy/certdeploy_settings.php');
		exit;
	}
	$settings = certdeploy_general_build($_POST);
}

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Settings')];
include('head.inc');

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

$tab_array = [
	[gettext('Settings'), true, '/certdeploy/certdeploy_settings.php'],
	[gettext('Deployments'), false, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Status'), false, '/certdeploy/certdeploy_status.php'],
	[gettext('Logs'), false, '/certdeploy/certdeploy_log.php'],
];

display_top_tabs($tab_array);

?>

<form method="post" class="form-horizontal">

<?php
$service_section = new Form_Section(gettext('Service'));
$service_section->addInput(new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Enable CertDeploy daemon'),
	($settings['enable'] ?? '') === 'on'
));
print($service_section);
?>

<?php
$advanced_section = new Form_Section(gettext('Advanced'), 'advanced-panel', COLLAPSIBLE|SEC_CLOSED);
$advanced_section->addInput(new Form_Checkbox(
	'acme_monitor',
	gettext('ACME Monitor'),
	gettext('Automatically detect ACME certificate changes'),
	($settings['acme_monitor'] ?? '') === 'on'
));
$advanced_section->addInput(new Form_Input(
	'scan_interval',
	gettext('ACME Scan Interval'),
	'number',
	$settings['scan_interval'],
	['min' => 10, 'max' => 86400]
))->setHelp(gettext('Seconds between ACME state scans.'));
$advanced_section->addInput(new Form_Checkbox(
	'reconcile',
	gettext('Reconcile'),
	gettext('Periodically deploy outdated certificates'),
	($settings['reconcile'] ?? '') === 'on'
));
$advanced_section->addInput(new Form_Input(
	'reconcile_interval',
	gettext('Reconcile Interval'),
	'number',
	$settings['reconcile_interval'],
	['min' => 60, 'max' => 86400]
))->setHelp(gettext('Seconds between reconciliation runs.'));
$advanced_section->addInput(new Form_Input(
	'max_concurrent_targets',
	gettext('Max Concurrent Targets'),
	'number',
	$settings['max_concurrent_targets'],
	['min' => 1, 'max' => 50]
));
$advanced_section->addInput(new Form_Input(
	'default_ssh_timeout',
	gettext('SSH Timeout'),
	'number',
	$settings['default_ssh_timeout'],
	['min' => 1]
))->setHelp(gettext('Seconds to wait for SSH connection.'));
$advanced_section->addInput(new Form_Input(
	'default_cmd_timeout',
	gettext('Command Timeout'),
	'number',
	$settings['default_cmd_timeout'],
	['min' => 1]
))->setHelp(gettext('Seconds to wait for remote commands.'));
$advanced_section->addInput(new Form_Input(
	'default_retry_count',
	gettext('Retry Count'),
	'number',
	$settings['default_retry_count'],
	['min' => 0]
));
$advanced_section->addInput(new Form_Input(
	'default_retry_delay',
	gettext('Retry Delay'),
	'number',
	$settings['default_retry_delay'],
	['min' => 1]
))->setHelp(gettext('Seconds between retries.'));
$advanced_section->addInput(new Form_Select(
	'log_level',
	gettext('Log Level'),
	$settings['log_level'],
	[
		'debug' => gettext('Debug'),
		'info' => gettext('Info'),
		'warn' => gettext('Warning'),
		'error' => gettext('Error'),
	]
));
print($advanced_section);
?>

<div class="col-sm-10 col-sm-offset-2">
	<button type="submit" name="save" class="btn btn-primary btn-sm">
		<i class="fa-solid fa-save icon-embed-btn"></i>
		<?=gettext('Save')?>
	</button>
</div>

</form>

<?php
$backup_section = new Form_Section(gettext('Backup / Restore'), 'backup-panel', COLLAPSIBLE|SEC_CLOSED);
$backup_section->addInput(new Form_StaticText(
	'',
	gettext('Download the full CertDeploy configuration, including identities, targets, and private keys. Upload a backup to restore after a reinstall.')
));
print($backup_section);
?>

<form method="get" action="/certdeploy/certdeploy_settings.php" class="form-horizontal">
	<div class="form-group">
		<label class="col-sm-2 control-label"><?=gettext('Backup')?></label>
		<div class="col-sm-10">
			<button type="submit" name="download" value="1" class="btn btn-info btn-sm">
				<i class="fa-solid fa-download icon-embed-btn"></i>
				<?=gettext('Download Config')?>
			</button>
		</div>
	</div>
</form>

<form method="post" action="/certdeploy/certdeploy_settings.php" enctype="multipart/form-data" class="form-horizontal">
	<div class="form-group">
		<label class="col-sm-2 control-label"><?=gettext('Restore')?></label>
		<div class="col-sm-10">
			<input type="file" name="restore_file" accept=".json,application/json" class="form-control" />
			<button type="submit" name="restore" value="1" class="btn btn-primary btn-sm" style="margin-top: 8px;">
				<i class="fa-solid fa-upload icon-embed-btn"></i>
				<?=gettext('Restore Config')?>
			</button>
		</div>
	</div>
</form>

<?php include('foot.inc');
