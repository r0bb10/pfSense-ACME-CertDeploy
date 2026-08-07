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
	[gettext('Deployments'), false, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Log'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

$runtime_state = certdeploy_global_state();
$deployments = certdeploy_deployments();
$acme_certs = certdeploy_acme_certificates();

$last_scan = $runtime_state['last_scan_time'] ?? 0;
$last_reconcile = $runtime_state['last_reconcile_time'] ?? 0;

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
$service_section->addInput(new Form_StaticText(
	gettext('Daemon State'),
	is_service_running('certdeploy') ? gettext('Running') : gettext('Stopped')
));
$service_section->addInput(new Form_StaticText(
	gettext('Last ACME Scan'),
	$last_scan > 0 ? date('Y-m-d H:i:s', $last_scan) : gettext('Never')
));
$service_section->addInput(new Form_StaticText(
	gettext('Last Reconcile'),
	$last_reconcile > 0 ? date('Y-m-d H:i:s', $last_reconcile) : gettext('Never')
));
print($service_section);
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('ACME Certificates')?></h2>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext('Name')?></th>
					<th><?=gettext('Status')?></th>
					<th><?=gettext('Valid From')?></th>
					<th><?=gettext('Valid To')?></th>
					<th><?=gettext('Last Renewed')?></th>
				</tr>
			</thead>
			<tbody>
<?php if (count($acme_certs) > 0): ?>
<?php foreach ($acme_certs as $name => $cert): ?>
				<tr>
					<td><?=htmlspecialchars($name)?></td>
					<td><?=htmlspecialchars($cert['status'])?></td>
					<td><?=htmlspecialchars($cert['valid_from'])?></td>
					<td><?=htmlspecialchars($cert['valid_to'])?></td>
					<td><?=$cert['lastrenewal'] > 0 ? date('Y-m-d H:i:s', $cert['lastrenewal']) : gettext('Never')?></td>
				</tr>
<?php endforeach; ?>
<?php else: ?>
				<tr>
					<td colspan="5"><?=gettext('No ACME certificates found.')?></td>
				</tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Deployments Sync Status')?></h2>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext('Deployment')?></th>
					<th><?=gettext('Target')?></th>
					<th><?=gettext('Certificate')?></th>
					<th><?=gettext('Last Deploy')?></th>
					<th><?=gettext('Status')?></th>
				</tr>
			</thead>
			<tbody>
<?php if (count($deployments) > 0): ?>
<?php foreach ($deployments as $dep):
	$status_text = certdeploy_deployment_status_text($dep);
	$sync = certdeploy_deployment_sync_status($dep);
	$last_deploy = $status_text['time'] > 0 ? date('Y-m-d H:i:s', $status_text['time']) : gettext('Never');
?>
				<tr>
					<td><?=htmlspecialchars($dep['name'] ?? '')?></td>
					<td><?=htmlspecialchars(($dep['host'] ?? '') . ':' . ($dep['port'] ?? 22))?></td>
					<td><?=htmlspecialchars($dep['certificate_name'] ?? gettext('None'))?></td>
					<td><?=htmlspecialchars($last_deploy)?></td>
					<td><?=htmlspecialchars($sync['message'])?></td>
				</tr>
<?php endforeach; ?>
<?php else: ?>
				<tr>
					<td colspan="5"><?=gettext('No deployments configured.')?></td>
				</tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

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
