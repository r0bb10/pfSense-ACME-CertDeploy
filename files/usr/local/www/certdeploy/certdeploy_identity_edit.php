<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

$id = $_GET['id'] ?? $_POST['id'] ?? '';
$is_new = true;
$pconfig = [];

if ($id !== '') {
	foreach (certdeploy_identities() as $idx => $ident) {
		if (($ident['id'] ?? '') === $id) {
			$pconfig = $ident;
			$is_new = false;
			break;
		}
	}
}

if ($_POST) {
	if (isset($_POST['act']) && $_POST['act'] === 'genkey') {
		$type = $_POST['genkey_type'] ?? 'ed25519';
		$name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['genkey_name'] ?? 'new');
		$result = certdeploy_identity_generate($name, $type, certdeploy_uuid());
		header('Content-Type: application/json');
		echo json_encode($result);
		exit;
	}

	if (isset($_POST['act']) && $_POST['act'] === 'regenkey') {
		$type = $_POST['key_type'] ?? 'ed25519';
		$name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['genkey_name'] ?? 'new');
		$uuid = $_POST['identity_id'] ?? '';
		if ($uuid === '') {
			header('Content-Type: application/json');
			echo json_encode(['error' => gettext('Identity ID missing.')]);
			exit;
		}
		$result = certdeploy_identity_generate($name, $type, $uuid);
		header('Content-Type: application/json');
		echo json_encode($result);
		exit;
	}

	if (isset($_POST['save'])) {
		$name = trim($_POST['name'] ?? '');
		if ($name === '') {
			$input_errors[] = gettext('Name is required.');
		} elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
			$input_errors[] = gettext('Name may contain only letters, numbers, underscores, and hyphens.');
		}

		$key_type = $_POST['key_type'] ?? 'ed25519';
		$private_key_path = $_POST['private_key_path'] ?? '';
		$key_pem = $_POST['key_pem'] ?? '';
		$new_key_pem = $_POST['new_key_pem'] ?? '';
		$identity_id = $_POST['identity_id'] ?? '';

		if ($private_key_path === '' && $key_pem === '' && $new_key_pem === '') {
			$input_errors[] = gettext('Either generate or import a private key.');
		}

		if (empty($input_errors)) {
			$ident_id = $is_new ? ($identity_id !== '' ? $identity_id : certdeploy_uuid()) : $id;
			$public_key = $_POST['public_key'] ?? '';
			$fingerprint = $_POST['fingerprint'] ?? '';
			if ($new_key_pem !== '') {
				$private_key_path = certdeploy_identity_save_import($name, $new_key_pem, $ident_id);
				@unlink($private_key_path . '.pub');
				if ($public_key !== '') {
					file_put_contents($private_key_path . '.pub', $public_key . "\n");
					chmod($private_key_path . '.pub', 0644);
				}
			} elseif ($key_pem !== '') {
				$private_key_path = certdeploy_identity_save_import($name, $key_pem, $ident_id);
				@unlink($private_key_path . '.pub');
				$public_key = certdeploy_identity_public_key($private_key_path);
				$fingerprint = certdeploy_identity_fingerprint($private_key_path);
			}

			$ident = [
				'id' => $ident_id,
				'name' => $name,
				'enabled' => empty($_POST['enabled']) ? 'off' : 'on',
				'description' => $_POST['description'] ?? '',
				'key_type' => $key_type,
				'private_key_path' => $private_key_path,
				'public_key' => $public_key,
				'fingerprint' => $fingerprint,
			];

			if ($is_new) {
				$idx = certdeploy_array_next_index('installedpackages/certdeploy/identities');
			} else {
				foreach (certdeploy_identities() as $i => $existing) {
					if (($existing['id'] ?? '') === $id) {
						$idx = $i;
						break;
					}
				}
			}
			config_set_path("installedpackages/certdeploy/identities/item/{$idx}", $ident);
			write_config('CertDeploy identity saved');
			certdeploy_sync();
			header('Location: /certdeploy/certdeploy_identities.php');
			exit;
		}
	}
}

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Identities'), $is_new ? gettext('Add') : gettext('Edit')];
include('head.inc');

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

?>
<div id="certdeploy_errors" class="alert alert-danger" style="display:none">
	<p><?=gettext('The following input errors were detected:')?></p>
	<ul></ul>
</div>
<?php

$tab_array = [
	[gettext('General'), false, '/certdeploy/certdeploy_general.php'],
	[gettext('Identities'), true, '/certdeploy/certdeploy_identities.php'],
	[gettext('Deployments'), false, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Log'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

$form = new Form(false);

$section = new Form_Section(gettext('Identity'));

$section->addInput(new Form_Checkbox(
	'enabled',
	gettext('Enable'),
	gettext('Enable this identity'),
	($pconfig['enabled'] ?? '') === 'on'
));

$section->addInput(new Form_Input(
	'name',
	gettext('Name'),
	'text',
	$pconfig['name'] ?? ''
));

$section->addInput(new Form_Input(
	'description',
	gettext('Description'),
	'text',
	$pconfig['description'] ?? ''
));

$section->addInput(new Form_Select(
	'key_type',
	gettext('Key Type'),
	$pconfig['key_type'] ?? 'ed25519',
	['ed25519' => 'Ed25519', 'rsa4096' => 'RSA 4096', 'rsa3072' => 'RSA 3072', 'rsa2048' => 'RSA 2048']
));

$section->addInput(new Form_Textarea(
	'key_pem',
	gettext('Import Private Key'),
	$pconfig['key_pem'] ?? ''
))->setHelp(gettext('Paste an existing private key in PEM format. Leave blank to generate or keep existing key.'));

$section->addInput(new Form_Input(
	'private_key_path',
	gettext('Private Key Path'),
	'text',
	$pconfig['private_key_path'] ?? ''
))->setReadonly()->setHelp(gettext('Path where the private key is stored on pfSense.'));

$section->addInput(new Form_Input(
	'public_key',
	gettext('Public Key'),
	'text',
	$pconfig['public_key'] ?? ''
))->setReadonly()->setHelp(gettext('Copy this public key to the remote host authorized_keys. (<a id="copypubkey" style="cursor: pointer;" data-success-text="' . gettext('Copied') . '" data-timeout="3000">' . gettext('Copy') . '</a>)'));

$section->addInput(new Form_Input(
	'fingerprint',
	gettext('Fingerprint'),
	'text',
	$pconfig['fingerprint'] ?? ''
))->setReadonly();

$form->add($section);

$form->addGlobal(new Form_Input('identity_id', '', 'hidden', $id));
$form->addGlobal(new Form_Input('new_key_pem', '', 'hidden', ''));

if ($is_new || ($pconfig['private_key_path'] ?? '') === '') {
	$form->addGlobal((new Form_Button(
		'genkey',
		gettext('Generate Key'),
		null,
		'fa-solid fa-key'
	))->addClass('btn-info btn-sm')->setAttribute('type', 'button')->setAttribute('id', 'genkey'));
}

if (!$is_new) {
	$form->addGlobal((new Form_Button(
		'regenkey',
		gettext('Regenerate Key'),
		null,
		'fa-solid fa-rotate'
	))->addClass('btn-warning btn-sm')->setAttribute('type', 'button')->setAttribute('id', 'regenkey'));
}

$form->addGlobal(new Form_Button(
	'save',
	gettext('Save'),
	null,
	'fa-solid fa-save'
))->addClass('btn-primary btn-sm');

print($form);

?>

<script type="text/javascript">
//<![CDATA[
function certdeploy_show_error(message) {
	var $box = $('#certdeploy_errors');
	$box.find('ul').empty().append($('<li></li>').text(message));
	$box.show();
}

function certdeploy_hide_error() {
	$('#certdeploy_errors').hide().find('ul').empty();
}

events.push(function() {
	$('#key_pem').on('input', function() {
		if ($(this).val() !== '') {
			$('#new_key_pem').val('');
		}
	});

	$('#genkey').click(function() {
		certdeploy_hide_error();
		var name = $('#name').val();
		if (name === '') {
			certdeploy_show_error('<?=gettext("Enter a name before generating a key.")?>');
			return;
		}
		$.ajax({
			url: '/certdeploy/certdeploy_identity_edit.php',
			type: 'POST',
			data: {
				act: 'genkey',
				genkey_name: name,
				genkey_type: $('#key_type').val()
			},
			success: function(data) {
				if (data.error) {
					certdeploy_show_error(data.error);
				} else {
					$('#identity_id').val(data.identity_id);
					$('#private_key_path').val(data.private_key_path);
					$('#public_key').val(data.public_key);
					$('#fingerprint').val(data.fingerprint);
					$('#new_key_pem').val(data.private_key_pem);
					$('#key_pem').val('');
				}
			},
			error: function() {
				certdeploy_show_error('<?=gettext("Key generation failed.")?>');
			}
		});
	});

	$('#copypubkey').click(function() {
		var $this = $(this);
		var originalText = $this.text();
		var key = $('#public_key').val();
		if (key === '') {
			certdeploy_show_error('<?=gettext("No public key to copy. Generate or import a key first.")?>');
			return;
		}
		var copied = false;
		var $tmp = $('<textarea></textarea>').css({position: 'fixed', left: '-9999px'}).val(key).appendTo('body');
		$tmp.select();
		try {
			copied = document.execCommand('copy');
		} catch (e) {
			copied = false;
		}
		$tmp.remove();
		if (!copied && navigator.clipboard) {
			navigator.clipboard.writeText(key).then(function() {
				$this.text($this.attr('data-success-text'));
				setTimeout(function() {
					$this.text(originalText);
				}, $this.attr('data-timeout'));
			}).catch(function() {
				certdeploy_show_error('<?=gettext("Copy failed.")?>');
			});
			return;
		}
		if (copied) {
			$this.text($this.attr('data-success-text'));
			setTimeout(function() {
				$this.text(originalText);
			}, $this.attr('data-timeout'));
		} else {
			certdeploy_show_error('<?=gettext("Copy failed.")?>');
		}
	});

	$('#regenkey').click(function() {
		certdeploy_hide_error();
		$.ajax({
			url: '/certdeploy/certdeploy_identity_edit.php',
			type: 'POST',
			data: {
				act: 'regenkey',
				identity_id: '<?=htmlspecialchars($id)?>',
				genkey_name: $('#name').val(),
				key_type: $('#key_type').val()
			},
			success: function(data) {
				if (data.error) {
					certdeploy_show_error(data.error);
				} else {
					$('#private_key_path').val(data.private_key_path);
					$('#public_key').val(data.public_key);
					$('#fingerprint').val(data.fingerprint);
					$('#new_key_pem').val(data.private_key_pem);
					$('#key_pem').val('');
				}
			},
			error: function() {
				certdeploy_show_error('<?=gettext("Key regeneration failed.")?>');
			}
		});
	});
});
//]]>
</script>

<?php include('foot.inc'); ?>
