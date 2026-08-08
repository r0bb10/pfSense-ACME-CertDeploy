<?php

require_once('functions.inc');
require_once('guiconfig.inc');
require_once('certdeploy.inc');

$shortcut_section = 'certdeploy';

$id = $_GET['id'] ?? $_POST['id'] ?? '';
$dup = $_GET['dup'] ?? '';
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
} elseif ($dup !== '') {
	foreach (certdeploy_identities() as $idx => $ident) {
		if (($ident['id'] ?? '') === $dup) {
			$new_id = certdeploy_uuid();
			$old_path = $ident['private_key_path'] ?? '';
			$new_path = '';
			if ($old_path !== '' && is_file($old_path)) {
				$new_path = dirname($old_path) . '/' . $new_id;
				copy($old_path, $new_path);
				chmod($new_path, 0600);
				if (is_file($old_path . '.pub')) {
					copy($old_path . '.pub', $new_path . '.pub');
					chmod($new_path . '.pub', 0644);
				}
			}
			$pconfig = $ident;
			$pconfig['id'] = $new_id;
			$pconfig['private_key_path'] = $new_path;
			$pconfig['name'] = ($ident['name'] ?? '') . ' (copy)';
			break;
		}
	}
}

if ($_POST) {
	if (isset($_POST['act']) && $_POST['act'] === 'genkey') {
		$type = $_POST['genkey_type'] ?? 'ed25519';
		$name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['genkey_name'] ?? 'new');
		$generated_identity = certdeploy_identity_generate($name, $type, certdeploy_uuid());
		header('Content-Type: application/json');
		echo json_encode($generated_identity);
		exit;
	}

	if (isset($_POST['act']) && $_POST['act'] === 'regenkey') {
		$type = $_POST['key_type'] ?? 'ed25519';
		$name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['genkey_name'] ?? 'new');
		$uuid = $_POST['identity_id'] ?? '';
		if (!certdeploy_identity_id_is_valid($uuid) || $uuid !== $id) {
			header('Content-Type: application/json');
			echo json_encode(['error' => gettext('Identity ID missing.')]);
			exit;
		}
		$generated_identity = certdeploy_identity_generate($name, $type, $uuid);
		header('Content-Type: application/json');
		echo json_encode($generated_identity);
		exit;
	}

	if (isset($_POST['save'])) {
		$name = trim($_POST['name'] ?? '');
		if ($name === '') {
			$input_errors[] = gettext('Username is required.');
		} elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
			$input_errors[] = gettext('Username may contain only letters, numbers, underscores, and hyphens.');
		}

		$key_type = $_POST['key_type'] ?? 'ed25519';
		$private_key_path = $is_new ? '' : ($pconfig['private_key_path'] ?? '');
		$key_pem = $_POST['key_pem'] ?? '';
		$new_key_pem = $_POST['new_key_pem'] ?? '';
		$identity_id = $_POST['identity_id'] ?? '';
		if ($is_new && $identity_id !== '' && !certdeploy_identity_id_is_valid($identity_id)) {
			$input_errors[] = gettext('Invalid identity ID.');
		}

		if ($private_key_path === '' && $key_pem === '' && $new_key_pem === '') {
			$input_errors[] = gettext('Either generate or import a private key.');
		}

		if (empty($input_errors)) {
			$ident_id = $is_new ? ($identity_id !== '' ? $identity_id : certdeploy_uuid()) : $id;
			$public_key = $_POST['public_key'] ?? '';
			$fingerprint = $_POST['fingerprint'] ?? '';
			if ($new_key_pem !== '') {
				$private_key_path = certdeploy_identity_save_import($name, $new_key_pem, $ident_id);
				if ($private_key_path === false) {
					$input_errors[] = gettext('Unable to save private key.');
				}
			}
			if (empty($input_errors) && $new_key_pem !== '') {
				@unlink($private_key_path . '.pub');
				if ($public_key !== '') {
					file_put_contents($private_key_path . '.pub', $public_key . "\n");
					chmod($private_key_path . '.pub', 0644);
				}
			} elseif (empty($input_errors) && $key_pem !== '') {
				$private_key_path = certdeploy_identity_save_import($name, $key_pem, $ident_id);
				if ($private_key_path === false) {
					$input_errors[] = gettext('Unable to save private key.');
				}
			}
			if (empty($input_errors) && $key_pem !== '') {
				@unlink($private_key_path . '.pub');
				$public_key = certdeploy_identity_public_key($private_key_path);
				$fingerprint = certdeploy_identity_fingerprint($private_key_path);
			}

			if (empty($input_errors)) {
				$ident = [
					'id' => $ident_id,
					'name' => $name,
					'description' => $_POST['description'] ?? '',
					'key_type' => $key_type,
					'private_key_path' => $private_key_path,
					'public_key' => $public_key,
					'fingerprint' => $fingerprint,
				];

				if ($is_new) {
					$idx = certdeploy_next_config_item_index('installedpackages/certdeploy/identities');
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
				header('Location: /certdeploy/certdeploy_deployments.php');
				exit;
			}
		}
	}
}

$pgtitle = [gettext('Services'), gettext('CertDeploy'), gettext('Deployments'), $is_new ? gettext('Add Identity') : gettext('Edit Identity')];
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
	[gettext('Settings'), false, '/certdeploy/certdeploy_settings.php'],
	[gettext('Deployments'), true, '/certdeploy/certdeploy_deployments.php'],
	[gettext('Status'), false, '/certdeploy/certdeploy_status.php'],
	[gettext('Logs'), false, '/certdeploy/certdeploy_log.php'],
];
display_top_tabs($tab_array);

$form = new Form(false);

$section = new Form_Section(gettext('Identity'));

$section->addInput(new Form_Input(
	'name',
	gettext('Username'),
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

$form->addGlobal(new Form_Input('identity_id', '', 'hidden', $pconfig['id'] ?? ($is_new ? certdeploy_uuid() : $id)));
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
				identity_id: '<?=htmlspecialchars($pconfig['id'] ?? $id)?>',
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
