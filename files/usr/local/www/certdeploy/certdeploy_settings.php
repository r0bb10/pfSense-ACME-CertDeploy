<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('service-utils.inc');
require_once('pfsense-utils.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

$settings = certdeploy_settings();
$defaults = certdeploy_general_defaults();
foreach ($defaults as $k => $v) {
	if (!isset($settings[$k])) {
		$settings[$k] = $v;
	}
}

if ($_POST) {
	$input_errors = [];

	if (isset($_POST['backup'])) {
		$passphrase = $_POST['backup_passphrase'] ?? '';
		if ($passphrase !== ($_POST['backup_passphrase_confirm'] ?? '')) {
			$input_errors[] = gettext('Backup passphrase confirmation does not match.');
		} elseif (($error = certdeploy_backup_download($passphrase)) !== null) {
			$input_errors[] = $error;
		}
	} elseif (isset($_POST['restore'])) {
		if (is_uploaded_file($_FILES['restore_file']['tmp_name'] ?? '')) {
			if (($_FILES['restore_file']['size'] ?? 0) > 5 * 1024 * 1024) {
				$input_errors[] = gettext('Backup files must not exceed 5 MB.');
			} else {
			$json = file_get_contents($_FILES['restore_file']['tmp_name']);
			$parsed = certdeploy_backup_parse($json, $_POST['restore_passphrase'] ?? '');
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
	} else {
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

<?php
$form = new Form(false);
$form->setMultipartEncoding();

$config_section = new Form_Section(gettext('Configuration'));
$config_section->addInput(new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Enable CertDeploy daemon'),
	($settings['enable'] ?? '') === 'on'
));
$config_section->addInput(new Form_Checkbox(
	'acme_monitor',
	gettext('ACME Monitor'),
	gettext('Automatically detect ACME certificate changes'),
	($settings['acme_monitor'] ?? '') === 'on'
));
$config_section->addInput(new Form_Input(
	'scan_interval',
	gettext('ACME Scan Interval'),
	'number',
	$settings['scan_interval'],
	['min' => 10, 'max' => 86400]
))->setHelp(gettext('Seconds between ACME state scans.'));
$config_section->addInput(new Form_Checkbox(
	'reconcile',
	gettext('Reconcile'),
	gettext('Periodically deploy outdated certificates'),
	($settings['reconcile'] ?? '') === 'on'
));
$config_section->addInput(new Form_Input(
	'reconcile_interval',
	gettext('Reconcile Interval'),
	'number',
	$settings['reconcile_interval'],
	['min' => 60, 'max' => 86400]
))->setHelp(gettext('Seconds between reconciliation runs.'));
$config_section->addInput(new Form_Input(
	'max_concurrent_targets',
	gettext('Max Concurrent Targets'),
	'number',
	$settings['max_concurrent_targets'],
	['min' => 1, 'max' => 50]
));
$config_section->addInput(new Form_Input(
	'default_ssh_timeout',
	gettext('SSH Timeout'),
	'number',
	$settings['default_ssh_timeout'],
	['min' => 1]
))->setHelp(gettext('Seconds to wait for SSH connection.'));
$config_section->addInput(new Form_Input(
	'default_cmd_timeout',
	gettext('Command Timeout'),
	'number',
	$settings['default_cmd_timeout'],
	['min' => 1]
))->setHelp(gettext('Seconds to wait for remote commands.'));
$config_section->addInput(new Form_Input(
	'default_retry_count',
	gettext('Retry Count'),
	'number',
	$settings['default_retry_count'],
	['min' => 0]
));
$config_section->addInput(new Form_Input(
	'default_retry_delay',
	gettext('Retry Delay'),
	'number',
	$settings['default_retry_delay'],
	['min' => 1]
))->setHelp(gettext('Seconds between retries.'));
$config_section->addInput(new Form_Select(
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
$form->add($config_section);

$backup_section = new Form_Section(gettext('Backup and Restore'));
$backup_section->addInput(new Form_StaticText(
	'',
	gettext('Backups include encrypted private keys. Keep the passphrase separately; it cannot be recovered.')
));
$backup_group = new Form_Group(gettext('Create Encrypted Backup'));
$backup_group->add(new Form_Input(
	'backup_passphrase',
	gettext('Passphrase'),
	'password',
	'',
	['autocomplete' => 'new-password']
))->setHelp(gettext('At least 12 characters.'));
$backup_group->add(new Form_Input(
	'backup_passphrase_confirm',
	gettext('Confirm Passphrase'),
	'password',
	'',
	['autocomplete' => 'new-password']
));
$backup_group->add((new Form_Button(
	'backup',
	gettext('Download Backup'),
	null,
	'fa-solid fa-download'
))->addClass('btn-info btn-sm'));
$backup_section->add($backup_group);

$restore_group = new Form_Group(gettext('Restore Encrypted Backup'));
$restore_group->add(new Form_Input(
	'restore_file',
	gettext('Backup File'),
	'file',
	'',
	['accept' => '.json,application/json']
));
$restore_group->add(new Form_Input(
	'restore_passphrase',
	gettext('Passphrase'),
	'password',
	'',
	['autocomplete' => 'current-password']
));
$restore_group->add((new Form_Button(
	'restore',
	gettext('Restore Backup'),
	null,
	'fa-solid fa-upload'
))->addClass('btn-warning btn-sm'));
$backup_section->add($restore_group);
$form->add($backup_section);

$form->addGlobal((new Form_Button(
	'save',
	gettext('Save Configuration'),
	null,
	'fa-solid fa-save'
))->addClass('btn-primary btn-sm'));
print($form);

include('foot.inc');
