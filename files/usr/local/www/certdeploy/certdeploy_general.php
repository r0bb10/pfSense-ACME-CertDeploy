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

$runtime_state = certdeploy_global_state();
$deployments = certdeploy_deployments();
$acme_certs = certdeploy_acme_certificates();

$sync_counts = ['synced' => 0, 'outdated' => 0, 'missing' => 0, 'disabled' => 0, 'error' => 0, 'nocert' => 0];
foreach ($deployments as $dep) {
	$sync = certdeploy_deployment_sync_status($dep);
	$sync_counts[$sync['state']] = ($sync_counts[$sync['state']] ?? 0) + 1;
}

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

$section = new Form_Section(gettext('Status'));

$section->addInput(new Form_StaticText(
	gettext('Daemon State'),
	is_service_running('certdeploy') ? gettext('Running') : gettext('Stopped')
));

$last_scan = $runtime_state['last_scan_time'] ?? 0;
$section->addInput(new Form_StaticText(
	gettext('Last ACME Scan'),
	$last_scan > 0 ? date('Y-m-d H:i:s', $last_scan) : gettext('Never')
));

$last_reconcile = $runtime_state['last_reconcile_time'] ?? 0;
$section->addInput(new Form_StaticText(
	gettext('Last Reconcile'),
	$last_reconcile > 0 ? date('Y-m-d H:i:s', $last_reconcile) : gettext('Never')
));

$form->add($section);

$section = new Form_Section(gettext('Advanced'));
$section->addClass('collapsed');

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

$form->addGlobal(new Form_Button(
	'save',
	gettext('Save'),
	null,
	'fa-solid fa-save'
))->addClass('btn-primary btn-sm');

print($form);

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

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Summary')?></h2>
	</div>
	<div class="panel-body">
		<p>
			<?=gettext('Total')?>: <strong><?=count($deployments)?></strong> |
			<?=gettext('In sync')?>: <strong><?=$sync_counts['synced']?></strong> |
			<?=gettext('Outdated')?>: <strong><?=$sync_counts['outdated']?></strong> |
			<?=gettext('Missing cert')?>: <strong><?=$sync_counts['missing']?></strong> |
			<?=gettext('Disabled cert')?>: <strong><?=$sync_counts['disabled']?></strong> |
			<?=gettext('Error')?>: <strong><?=$sync_counts['error']?></strong> |
			<?=gettext('No cert')?>: <strong><?=$sync_counts['nocert']?></strong>
		</p>
	</div>
</div>

<?php include('foot.inc');
