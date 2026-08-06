<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

if ($_POST && isset($_POST['act'], $_POST['id'])) {
	$id = $_POST['id'];
	foreach (certdeploy_deployments() as $idx => $dep) {
		if (($dep['id'] ?? '') === $id) {
			if ($_POST['act'] === 'delete') {
				config_del_path("installedpackages/certdeploy/deployments/item/{$idx}");
				write_config('CertDeploy deployment deleted');
			} elseif ($_POST['act'] === 'toggle') {
				$dep['enabled'] = (($dep['enabled'] ?? '') === 'on') ? 'off' : 'on';
				config_set_path("installedpackages/certdeploy/deployments/item/{$idx}", $dep);
				write_config('CertDeploy deployment toggled');
			} elseif ($_POST['act'] === 'deploy') {
				// Trigger deployment via daemon control.
				mwexec('/usr/local/sbin/certdeployctl deploy ' . escapeshellarg($id) . ' >/dev/null 2>&1 &');
			}
			certdeploy_sync();
			break;
		}
	}
	header('Location: /certdeploy/certdeploy_deployments.php');
	exit;
}

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Deployments')];
include('head.inc');

$tab_array = [
	[gettext('General'), false, '/certdeploy/certdeploy_general.php'],
	[gettext('Identities'), false, '/certdeploy/certdeploy_identities.php'],
	[gettext('Deployments'), true, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Log'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Deployments')?></h2>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext('Enabled')?></th>
					<th><?=gettext('Name')?></th>
					<th><?=gettext('Certificate')?></th>
					<th><?=gettext('Host')?></th>
					<th><?=gettext('User')?></th>
					<th><?=gettext('State')?></th>
					<th><?=gettext('Actions')?></th>
				</tr>
			</thead>
			<tbody>
<?php
$deployments = certdeploy_deployments();
if (count($deployments) > 0):
	foreach ($deployments as $idx => $dep):
		$id = htmlspecialchars($dep['id'] ?? '');
		$enabled = ($dep['enabled'] ?? '') === 'on';
		$state = certdeploy_deployment_state($dep);
?>
				<tr>
					<td>
						<form method="post" style="display:inline">
							<input type="hidden" name="id" value="<?=$id?>" />
							<button type="submit" name="act" value="toggle" class="btn btn-xs <?= $enabled ? 'btn-success' : 'btn-default' ?>">
								<i class="fa-solid <?= $enabled ? 'fa-check' : 'fa-ban' ?>"></i>
							</button>
						</form>
					</td>
					<td><?=htmlspecialchars($dep['name'] ?? '')?></td>
					<td><?=htmlspecialchars($dep['certificate_name'] ?? '')?></td>
					<td><?=htmlspecialchars($dep['host'] ?? '')?></td>
					<td><?=htmlspecialchars($dep['user'] ?? '')?></td>
					<td><?=htmlspecialchars($state)?></td>
					<td>
						<form method="post" style="display:inline">
							<input type="hidden" name="id" value="<?=$id?>" />
							<button type="submit" name="act" value="deploy" class="btn btn-xs btn-primary" title="<?=gettext('Deploy now')?>">
								<i class="fa-solid fa-play"></i>
							</button>
						</form>
						<a class="fa-solid fa-pencil" title="<?=gettext('Edit')?>" href="/certdeploy/certdeploy_deployment_edit.php?id=<?=$id?>"></a>
						<form method="post" style="display:inline" onsubmit="return confirm('<?=gettext('Delete this deployment?')?>')">
							<input type="hidden" name="id" value="<?=$id?>" />
							<button type="submit" name="act" value="delete" class="btn btn-xs btn-link">
								<i class="fa-solid fa-trash-can text-danger"></i>
							</button>
						</form>
					</td>
				</tr>
<?php
	endforeach;
else:
?>
				<tr>
					<td colspan="7">
						<?php print_info_box(gettext('No deployments configured. Add a deployment to push certificates to a remote host.'), 'warning'); ?>
					</td>
				</tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<nav class="action-buttons">
	<a href="/certdeploy/certdeploy_deployment_edit.php" class="btn btn-success btn-sm">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext('Add Deployment')?>
	</a>
</nav>

<?php include('foot.inc'); ?>
