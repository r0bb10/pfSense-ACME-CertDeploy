<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('service-utils.inc');
require_once('certdeploy.inc');

const CERTDEPLOY_DAEMON_BIN = '/usr/local/sbin/certdeployd';

function certdeploy_status_command_output($command) {
	$output = [];
	$result = 0;
	exec($command . ' 2>&1', $output, $result);
	return trim(implode("\n", $output));
}

function certdeploy_status_version() {
	if (!is_executable(CERTDEPLOY_DAEMON_BIN)) {
		return 'certdeployd binary is missing';
	}
	return trim(certdeploy_status_command_output(CERTDEPLOY_DAEMON_BIN . ' --version'));
}

if (isset($_GET['ajax'])) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	$settings = certdeploy_settings();
	$runtime_state = certdeploy_global_state();
	echo json_encode([
		'enabled' => ($settings['enable'] ?? '') === 'on',
		'running' => is_service_running('certdeploy'),
		'version' => certdeploy_status_version(),
		'last_scan_time' => (int)($runtime_state['last_scan_time'] ?? 0),
		'last_reconcile_time' => (int)($runtime_state['last_reconcile_time'] ?? 0),
	]);
	exit;
}

$shortcut_section = 'certdeploy';

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Status')];
include('head.inc');

$tab_array = [
	[gettext('Settings'), false, '/certdeploy/certdeploy_settings.php'],
	[gettext('Deployments'), false, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Status'), true, '/certdeploy/certdeploy_status.php'],
	[gettext('Logs'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

$deployments = certdeploy_deployments();
$acme_certs = certdeploy_acme_certificates();

?>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Service')?></h2></div>
	<div class="panel-body">
		<table class="table table-striped table-condensed">
			<tbody>
				<tr><th><?=gettext('Enabled')?></th><td id="certdeploy-enabled">-</td></tr>
				<tr><th><?=gettext('State')?></th><td id="certdeploy-state">-</td></tr>
				<tr><th><?=gettext('Version')?></th><td id="certdeploy-version">-</td></tr>
				<tr><th><?=gettext('Last ACME Scan')?></th><td id="certdeploy-last-scan">-</td></tr>
				<tr><th><?=gettext('Last Reconcile')?></th><td id="certdeploy-last-reconcile">-</td></tr>
			</tbody>
		</table>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('ACME Certificates')?></h2></div>
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
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Deployments Sync Status')?></h2></div>
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

<script>
async function refreshCertDeployStatus() {
	const response = await fetch('/certdeploy/certdeploy_status.php?ajax=1', {cache: 'no-store'});
	const data = await response.json();
	document.getElementById('certdeploy-enabled').textContent = data.enabled ? 'Yes' : 'No';
	document.getElementById('certdeploy-state').textContent = data.running ? 'Running' : 'Stopped';
	document.getElementById('certdeploy-version').textContent = data.version || '-';
	document.getElementById('certdeploy-last-scan').textContent = data.last_scan_time ? new Date(data.last_scan_time * 1000).toLocaleString() : 'Never';
	document.getElementById('certdeploy-last-reconcile').textContent = data.last_reconcile_time ? new Date(data.last_reconcile_time * 1000).toLocaleString() : 'Never';
}
refreshCertDeployStatus();
setInterval(refreshCertDeployStatus, 5000);
</script>

<?php include('foot.inc'); ?>