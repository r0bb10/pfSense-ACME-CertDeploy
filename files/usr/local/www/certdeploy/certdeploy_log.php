<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('service-utils.inc');
require_once('certdeploy.inc');

const CERTDEPLOY_DISPLAY_LOG_LINES = 100;

function certdeploy_log_escape($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (isset($_GET['ajax'])) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode([
		'lines' => certdeploy_log_lines(CERTDEPLOY_DISPLAY_LOG_LINES),
	]);
	exit;
}

$shortcut_section = 'certdeploy';

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Log')];
include('head.inc');

$tab_array = [
	[gettext('General'), false, '/certdeploy/certdeploy_general.php'],
	[gettext('Deployments'), false, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Log'), true, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

?>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Daemon Log')?></h2></div>
	<div class="panel-body">
		<pre id="certdeploy-log" style="max-height: 50em; overflow: auto;"><?=gettext('Loading...')?></pre>
	</div>
</div>

<script>
async function refreshCertDeployLog() {
	const response = await fetch('/certdeploy/certdeploy_log.php?ajax=1', {cache: 'no-store'});
	const data = await response.json();
	const log = (data.lines || []).join('\n');
	document.getElementById('certdeploy-log').textContent = log || <?=json_encode(gettext('No log output.'))?>;
}
refreshCertDeployLog();
setInterval(refreshCertDeployLog, 5000);
</script>

<?php include('foot.inc'); ?>