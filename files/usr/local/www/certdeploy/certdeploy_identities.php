<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

if ($_POST && isset($_POST['act'], $_POST['id'])) {
	$id = $_POST['id'];
	foreach (certdeploy_identities() as $idx => $ident) {
		if (($ident['id'] ?? '') === $id) {
			if ($_POST['act'] === 'delete') {
				$path = $ident['private_key_path'] ?? '';
				if ($path !== '' && is_file($path)) {
					unlink($path);
					if (is_file($path . '.pub')) {
						unlink($path . '.pub');
					}
				}
				config_del_path("installedpackages/certdeploy/identities/item/{$idx}");
				write_config('CertDeploy identity deleted');
			} elseif ($_POST['act'] === 'toggle') {
				$ident['enabled'] = (($ident['enabled'] ?? '') === 'on') ? 'off' : 'on';
				config_set_path("installedpackages/certdeploy/identities/item/{$idx}", $ident);
				write_config('CertDeploy identity toggled');
			}
			certdeploy_sync();
			break;
		}
	}
	header('Location: /certdeploy/certdeploy_identities.php');
	exit;
}

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Identities')];
include('head.inc');

$tab_array = [
	[gettext('General'), false, '/certdeploy/certdeploy_general.php'],
	[gettext('Identities'), true, '/certdeploy/certdeploy_identities.php'],
	[gettext('Deployments'), false, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Log'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('SSH Identities')?></h2>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext('Enabled')?></th>
					<th><?=gettext('Name')?></th>
					<th><?=gettext('Type')?></th>
					<th><?=gettext('Fingerprint')?></th>
					<th><?=gettext('Actions')?></th>
				</tr>
			</thead>
			<tbody>
<?php
$identities = certdeploy_identities();
if (count($identities) > 0):
	foreach ($identities as $idx => $ident):
		$id = htmlspecialchars($ident['id'] ?? '');
		$enabled = ($ident['enabled'] ?? '') === 'on';
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
					<td><?=htmlspecialchars($ident['name'] ?? '')?></td>
					<td><?=htmlspecialchars($ident['key_type'] ?? '')?></td>
					<td><?=htmlspecialchars($ident['fingerprint'] ?? '')?></td>
					<td>
						<a class="fa-solid fa-pencil" title="<?=gettext('Edit')?>" href="/certdeploy/certdeploy_identity_edit.php?id=<?=$id?>"></a>
						<form method="post" style="display:inline" onsubmit="return confirm('<?=gettext('Delete this identity?')?>')">
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
					<td colspan="5">
						<?php print_info_box(gettext('No identities configured. Add an identity to use for SSH authentication.'), 'warning'); ?>
					</td>
				</tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<nav class="action-buttons">
	<a href="/certdeploy/certdeploy_identity_edit.php" class="btn btn-success btn-sm">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext('Add Identity')?>
	</a>
</nav>

<?php include('foot.inc'); ?>
