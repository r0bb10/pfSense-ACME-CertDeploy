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
		header('Location: /certdeploy/certdeploy_general.php');
		exit;
	}
	$settings = certdeploy_general_build($_POST);
}

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('General')];
include('head.inc');

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

$tab_array = [
	[gettext('General'), true, '/certdeploy/certdeploy_general.php'],
	[gettext('Identities'), false, '/certdeploy/certdeploy_identities.php'],
	[gettext('Deployments'), false, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Log'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

$form = new Form(false);

$section = new Form_Section(gettext('Service'));

$section->addInput(new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Enable CertDeploy daemon'),
	($settings['enable'] ?? '') === 'on'
));

$section->addInput(new Form_Checkbox(
	'acme_monitor',
	gettext('ACME Monitor'),
	gettext('Automatically detect ACME certificate changes'),
	($settings['acme_monitor'] ?? '') === 'on'
));

$section->addInput(new Form_Input(
	'scan_interval',
	gettext('ACME Scan Interval'),
	'number',
	$settings['scan_interval'],
	['min' => 10, 'max' => 86400]
))->setHelp(gettext('Seconds between ACME state scans.'));

$section->addInput(new Form_Checkbox(
	'reconcile',
	gettext('Reconcile'),
	gettext('Periodically deploy outdated certificates'),
	($settings['reconcile'] ?? '') === 'on'
));

$section->addInput(new Form_Input(
	'reconcile_interval',
	gettext('Reconcile Interval'),
	'number',
	$settings['reconcile_interval'],
	['min' => 60, 'max' => 86400]
))->setHelp(gettext('Seconds between reconciliation runs.'));

$form->add($section);

$section = new Form_Section(gettext('Defaults'));

$section->addInput(new Form_Input(
	'max_concurrent_targets',
	gettext('Max Concurrent Targets'),
	'number',
	$settings['max_concurrent_targets'],
	['min' => 1, 'max' => 50]
));

$section->addInput(new Form_Input(
	'default_ssh_timeout',
	gettext('SSH Timeout'),
	'number',
	$settings['default_ssh_timeout'],
	['min' => 1]
))->setHelp(gettext('Seconds to wait for SSH connection.'));

$section->addInput(new Form_Input(
	'default_cmd_timeout',
	gettext('Command Timeout'),
	'number',
	$settings['default_cmd_timeout'],
	['min' => 1]
))->setHelp(gettext('Seconds to wait for remote commands.'));

$section->addInput(new Form_Input(
	'default_retry_count',
	gettext('Retry Count'),
	'number',
	$settings['default_retry_count'],
	['min' => 0]
));

$section->addInput(new Form_Input(
	'default_retry_delay',
	gettext('Retry Delay'),
	'number',
	$settings['default_retry_delay'],
	['min' => 1]
))->setHelp(gettext('Seconds between retries.'));

$section->addInput(new Form_Select(
	'log_level',
	gettext('Log Level'),
	$settings['log_level'],
	['debug' => 'Debug', 'info' => 'Info', 'warn' => 'Warning', 'error' => 'Error']
));

$form->add($section);

$section = new Form_Section(gettext('Status'));

$section->addInput(new Form_StaticText(
	gettext('Daemon State'),
	is_service_running('certdeploy') ? gettext('Running') : gettext('Stopped')
));

$form->add($section);

print($form);

?>
<nav class="action-buttons">
	<button type="submit" class="btn btn-primary btn-sm" name="save" value="save">
		<i class="fa-solid fa-save icon-embed-btn"></i>
		<?=gettext('Save')?>
	</button>
</nav>

<?php include('foot.inc'); ?>
