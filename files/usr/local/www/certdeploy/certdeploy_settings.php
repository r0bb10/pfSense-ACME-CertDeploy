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

<?php include('foot.inc');
