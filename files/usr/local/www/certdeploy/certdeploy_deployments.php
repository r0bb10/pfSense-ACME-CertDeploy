<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

$ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_POST && isset($_POST['act'], $_POST['id'])) {
	$id = $_POST['id'];

	// Identity actions
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
				certdeploy_sync();
			}
			break;
		}
	}

	// Target actions
	foreach (certdeploy_deployments() as $idx => $dep) {
		if (($dep['id'] ?? '') === $id) {
			if ($_POST['act'] === 'delete') {
				config_del_path("installedpackages/certdeploy/deployments/item/{$idx}");
				write_config('CertDeploy target deleted');
				certdeploy_sync();
			} elseif ($_POST['act'] === 'toggle') {
				$dep['enabled'] = (($dep['enabled'] ?? '') === 'on') ? 'off' : 'on';
				config_set_path("installedpackages/certdeploy/deployments/item/{$idx}", $dep);
				write_config('CertDeploy target toggled');
				certdeploy_sync();
			} elseif ($_POST['act'] === 'deploy') {
				$output = shell_exec('/usr/local/sbin/certdeployctl deploy ' . escapeshellarg($id) . ' 2>&1');
				if ($ajax) {
					echo trim($output);
					exit;
				}
				$savemsg = gettext('Deploy triggered.') . ' ' . htmlspecialchars(trim($output));
			}
			break;
		}
	}

	if (!$ajax) {
		header('Location: /certdeploy/certdeploy_deployments.php');
		exit;
	}
}

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Deployments')];
include('head.inc');

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}
if (!empty($savemsg)) {
	print_info_box($savemsg, 'success');
}

$tab_array = [
	[gettext('General'), false, '/certdeploy/certdeploy_general.php'],
	[gettext('Deployments'), true, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Log'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

?>

<div id="deployoutputbox" class="alert alert-success clearfix hidden" role="alert">
	<button type="button" class="close" data-dismiss="alert" aria-label="Close">
		<span aria-hidden="true">&times;</span>
	</button>
	<div id="deployoutput" class="pull-left" style="white-space: pre-wrap"></div>
</div>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Identities')?></h2>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext('Username')?></th>
					<th><?=gettext('Description')?></th>
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
?>
				<tr>
					<td><?=htmlspecialchars($ident['name'] ?? '')?></td>
					<td><?=htmlspecialchars($ident['description'] ?? '')?></td>
					<td><?=htmlspecialchars($ident['key_type'] ?? '')?></td>
					<td><?=htmlspecialchars($ident['fingerprint'] ?? '')?></td>
					<td class="action-icons">
						<a class="fa-solid fa-pencil" title="<?=gettext('Edit')?>" href="/certdeploy/certdeploy_identity_edit.php?id=<?=$id?>"></a>
						<a class="fa-solid fa-trash-can" title="<?=gettext('Delete')?>" href="?act=delete&amp;id=<?=$id?>" usepost></a>
						<a class="fa-solid fa-copy" title="<?=gettext('Copy')?>" href="/certdeploy/certdeploy_identity_edit.php?dup=<?=$id?>"></a>
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

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Targets')?></h2>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-striped table-condensed">
			<thead>
				<tr>
					<th><?=gettext('Enabled')?></th>
					<th><?=gettext('Name')?></th>
					<th><?=gettext('Certificate')?></th>
					<th><?=gettext('Host')?></th>
					<th><?=gettext('State')?></th>
					<th><?=gettext('Deploy')?></th>
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
				<tr <?=$enabled ? '' : 'class="disabled"'?>>
					<td>
						<a href="?act=toggle&amp;id=<?=$id?>" usepost>
							<i class="fa-solid fa-check" title="<?=gettext('click to toggle enabled/disabled status')?>"></i>
						</a>
					</td>
					<td><?=htmlspecialchars($dep['name'] ?? '')?></td>
					<td><?=htmlspecialchars($dep['certificate_name'] ?? '')?></td>
					<td><?=htmlspecialchars($dep['host'] ?? '')?></td>
					<td>
<?php
						$status_info = certdeploy_deployment_status_text($dep);
						$time_str = $status_info['time'] > 0 ? date('Y-m-d H:i', $status_info['time']) : gettext('never');
?>
						<div><?=gettext('Last deploy:')?> <?=htmlspecialchars($time_str)?></div>
						<div><?=gettext('Result:')?> <?=htmlspecialchars($status_info['status'])?></div>
					</td>
					<td>
						<a href="javascript:certdeploy_deploy('<?=$id?>');" class="btn btn-sm btn-primary">
							<i id="btndeployicon_<?=$id?>" class="fa-solid fa-check"></i> <?=gettext('Upload')?>
						</a>
					</td>
					<td class="action-icons">
					<a class="fa-solid fa-pencil" title="<?=gettext('Edit')?>" href="/certdeploy/certdeploy_target_edit.php?id=<?=$id?>"></a>
					<a class="fa-solid fa-trash-can" title="<?=gettext('Delete')?>" href="?act=delete&amp;id=<?=$id?>" usepost></a>
					<a class="fa-solid fa-copy" title="<?=gettext('Copy')?>" href="/certdeploy/certdeploy_target_edit.php?dup=<?=$id?>"></a>
					</td>
				</tr>
<?php
	endforeach;
else:
?>
				<tr>
					<td colspan="7">
						<?php print_info_box(gettext('No targets configured. Add a target to push certificates to a remote host.'), 'warning'); ?>
					</td>
				</tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<nav class="action-buttons">
	<a href="/certdeploy/certdeploy_target_edit.php" class="btn btn-success btn-sm">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext('Add Target')?>
	</a>
</nav>

<script type="text/javascript">
//<![CDATA[
function certdeploy_deploy(id) {
	$("i[id='btndeployicon_" + id + "']").removeClass("fa-check").addClass("fa-cog fa-spin");
	$.ajax({
		url: "/certdeploy/certdeploy_deployments.php",
		type: "post",
		data: { act: "deploy", id: id },
		success: function(data) {
			$("#deployoutputbox").removeClass("hidden");
			$("#deployoutput").text(data);
			$("i[id='btndeployicon_" + id + "']").removeClass("fa-cog fa-spin").addClass("fa-check");
		},
		error: function() {
			$("#deployoutputbox").removeClass("hidden").addClass("alert-danger");
			$("#deployoutput").text("<?=gettext('Deploy request failed.')?>");
			$("i[id='btndeployicon_" + id + "']").removeClass("fa-cog fa-spin").addClass("fa-link-slash");
		}
	});
}
//]]>
</script>

<?php include('foot.inc');
