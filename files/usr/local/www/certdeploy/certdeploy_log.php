<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

$limit = (int)($_GET['limit'] ?? 250);
if ($limit < 10 || $limit > 5000) {
	$limit = 250;
}
$component = $_GET['component'] ?? '';
$level = $_GET['level'] ?? '';

$lines = certdeploy_log_lines($limit);
$lines = certdeploy_log_filter_component($lines, $component);
$lines = certdeploy_log_filter_level($lines, $level);

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
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Daemon Log')?></h2>
	</div>
	<div class="panel-body">
		<form method="get" class="form-inline">
			<div class="form-group">
				<label for="limit"><?=gettext('Lines')?></label>
				<input type="number" class="form-control" id="limit" name="limit" value="<?=$limit?>" min="10" max="5000" />
			</div>
			<div class="form-group">
				<label for="level"><?=gettext('Level')?></label>
				<select class="form-control" id="level" name="level">
					<option value="" <?= $level === '' ? 'selected' : '' ?>><?=gettext('All')?></option>
					<option value="error" <?= $level === 'error' ? 'selected' : '' ?>><?=gettext('Error')?></option>
					<option value="warn" <?= $level === 'warn' ? 'selected' : '' ?>><?=gettext('Warning')?></option>
					<option value="info" <?= $level === 'info' ? 'selected' : '' ?>><?=gettext('Info')?></option>
					<option value="debug" <?= $level === 'debug' ? 'selected' : '' ?>><?=gettext('Debug')?></option>
				</select>
			</div>
			<div class="form-group">
				<label for="component"><?=gettext('Component')?></label>
				<input type="text" class="form-control" id="component" name="component" value="<?=htmlspecialchars($component)?>" placeholder="e.g. deploy" />
			</div>
			<button type="submit" class="btn btn-primary btn-sm"><?=gettext('Filter')?></button>
			<a href="/certdeploy/certdeploy_log.php" class="btn btn-secondary btn-sm"><?=gettext('Clear')?></a>
		</form>
		<hr/>
		<pre style="max-height: 50em; overflow: auto;"><?=htmlspecialchars(implode("\n", $lines))?></pre>
	</div>
</div>

<?php include('foot.inc'); ?>
