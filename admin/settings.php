<?php require_once __DIR__ . '/../includes/auth.php'; require_admin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';
    if ($action === 'gen_token') {
        set_setting('selcom_webhook_token', bin2hex(random_bytes(16)));
        $msg = ['ok','Webhook token mpya imetengenezwa.'];
    } elseif ($action === 'change_pwd') {
        $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $rep = $_POST['repeat'] ?? '';
        if (strlen($new) < 8) $msg = ['bad','Password mpya iwe na char 8 au zaidi.'];
        elseif ($new !== $rep) $msg = ['bad','Confirm password hailingani.'];
        else {
            $s = db()->prepare('SELECT password_hash FROM admins WHERE id=?');
            $s->execute([$_SESSION['admin_id']]); $r = $s->fetch();
            if (!$r || !password_verify($cur, $r['password_hash'])) $msg = ['bad','Password ya sasa si sahihi.'];
            else {
                db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
                $msg = ['ok','Password imebadilishwa.'];
            }
        }
    } else {
      // Only save keys that were actually posted to avoid wiping other settings
      $keys = ['selcom_base_url','selcom_api_key','selcom_api_secret','selcom_vendor','selcom_webhook_token','payment_provider','grebo_api_key','grebo_webhook_secret','grebo_base_url'];
      foreach ($keys as $k) {
        if (array_key_exists($k, $_POST)) {
          set_setting($k, trim($_POST[$k]));
        }
      }
      $msg = ['ok','Imehifadhiwa.'];
    }
}

$cfg = [
  'selcom_base_url'=>setting('selcom_base_url', SELCOM_DEFAULT_BASE),
  'selcom_api_key'=>setting('selcom_api_key'),
  'selcom_api_secret'=>setting('selcom_api_secret'),
  'selcom_vendor'=>setting('selcom_vendor'),
  'selcom_webhook_token'=>setting('selcom_webhook_token'),
  'payment_provider'=>setting('payment_provider', 'selcom'),
  'grebo_api_key'=>setting('grebo_api_key'),
  'grebo_webhook_secret'=>setting('grebo_webhook_secret'),
  'grebo_base_url'=>setting('grebo_base_url', 'https://grebo.tesloty.com'),
];
$webhookUrl = SITE_URL . '/api/selcom-webhook.php' . ($cfg['selcom_webhook_token'] ? '?token=' . $cfg['selcom_webhook_token'] : '');
$greboWebhookUrl = SITE_URL . '/api/grebo-webhook.php';

require __DIR__ . '/_layout.php';
?>
<h1 style="margin-top:0;color:var(--gold)">Settings — Payments</h1>
<?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

<div class="card">
  <h3 style="margin-top:0">Selcom API</h3>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div>
      <label>Active Gateway — Deposits</label>
      <div class="provider-toggle" id="provider-toggle">
        <input type="hidden" name="payment_provider" id="payment_provider_input" value="<?= e($cfg['payment_provider']) ?>">
        <button type="button" class="btn-provider <?= $cfg['payment_provider']==='grebo' ? 'active grebo' : '' ?>" data-provider="grebo" id="prov-grebo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/></svg>
          <div>
            <div class="label">GREBO</div>
            <div class="sub">Grebo</div>
          </div>
        </button>
        <button type="button" class="btn-provider <?= $cfg['payment_provider']==='selcom' ? 'active selcom' : '' ?>" data-provider="selcom" id="prov-selcom">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2v20"/></svg>
          <div>
            <div class="label">SELCOM</div>
            <div class="sub">Selcom Mobile</div>
          </div>
        </button>
      </div>
    </div>
    <div><label>Base URL</label><input name="selcom_base_url" value="<?= e($cfg['selcom_base_url']) ?>"></div>
    <div><label>API Key</label><input name="selcom_api_key" value="<?= e($cfg['selcom_api_key']) ?>"></div>
    <div><label>API Secret</label><input name="selcom_api_secret" type="password" value="<?= e($cfg['selcom_api_secret']) ?>"></div>
    <div><label>Vendor ID</label><input name="selcom_vendor" value="<?= e($cfg['selcom_vendor']) ?>"></div>
    <div><label>Webhook Token</label><input name="selcom_webhook_token" value="<?= e($cfg['selcom_webhook_token']) ?>"></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-gold" type="submit">Hifadhi</button>
      <button class="btn btn-outline" name="action" value="gen_token" type="submit">Tengeneza token mpya</button>
    </div>
  </form>
</div>

<script>
  (function(){
    const wrap = document.getElementById('provider-toggle');
    if (!wrap) return;
    const input = document.getElementById('payment_provider_input');
    wrap.addEventListener('click', e => {
      const btn = e.target.closest('.btn-provider');
      if (!btn) return;
      // toggle active
      wrap.querySelectorAll('.btn-provider').forEach(b => b.classList.remove('active','grebo','selcom'));
      const p = btn.dataset.provider;
      btn.classList.add('active');
      if (p === 'grebo') btn.classList.add('grebo'); else btn.classList.add('selcom');
      input.value = p;
      // auto-submit the surrounding form so change is saved immediately
      const f = wrap.closest('form');
      if (f) f.submit();
    });
  })();
</script>

<div class="card">
  <h3 style="margin-top:0">Grebo API</h3>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div><label>Base URL</label><input name="grebo_base_url" value="<?= e($cfg['grebo_base_url']) ?>"></div>
    <div><label>API Key</label><input name="grebo_api_key" value="<?= e($cfg['grebo_api_key']) ?>" placeholder="grb_live_..."></div>
    <div><label>Webhook secret (OPTIONAL)</label><input name="grebo_webhook_secret" type="password" value="<?= e($cfg['grebo_webhook_secret']) ?>" placeholder="Leave empty — API Key itakuwa secret"></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-gold" type="submit">Hifadhi</button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0">Grebo Webhook Secret — Explained</h3>
  <p class="muted">Grebo signs webhook requests na HMAC-SHA256 over `timestamp.rawBody`. Secret ni:</p>
  <?php if ($cfg['grebo_webhook_secret']): ?>
    <p style="color:var(--gold);font-weight:bold">✓ Custom secret imesanidiwa</p>
  <?php else: ?>
    <p style="color:var(--gold);font-weight:bold">✓ Litakuwa API Key automatic (safe default)</p>
  <?php endif; ?>
  <ol style="font-size:14px;line-height:1.6">
    <li><strong>Default (RECOMMENDED):</strong> Leave "Webhook secret" field empty. System itakuwa use your API Key as secret. ✓</li>
    <li><strong>OPTIONAL:</strong> Ikiwa Grebo dashboard itakuwa show SIGNING SECRET field baadaye, paste hapa</li>
    <li>Verification itakuwa automatic - signature check itakuwa work na secret yeyote (API Key or custom)</li>
  </ol>
</div>

<div class="card">
  <h3 style="margin-top:0">Webhook URL ya Selcom Portal</h3>
  <p class="muted">Nakili URL hii uweke kwenye Selcom Merchant Portal kama callback URL:</p>
  <code style="display:block;padding:10px;background:var(--surface-2);border-radius:8px;word-break:break-all"><?= e($webhookUrl) ?></code>
</div>

<div class="card">
  <h3 style="margin-top:0">Webhook URL ya Grebo</h3>
  <p class="muted">Set this in the Grebo dashboard as your deposit callback (Grebo signs requests with `X-Webhook-Signature` and `X-Webhook-Timestamp`).</p>
  <code style="display:block;padding:10px;background:var(--surface-2);border-radius:8px;word-break:break-all"><?= e($greboWebhookUrl) ?></code>
</div>

<div class="card">

  <h3 style="margin-top:0">Badili Password</h3>
  <form method="post" class="form-grid" style="max-width:420px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="change_pwd">
    <div><label>Password ya sasa</label><input type="password" name="current" required></div>
    <div><label>Password mpya</label><input type="password" name="new" required minlength="8"></div>
    <div><label>Thibitisha</label><input type="password" name="repeat" required minlength="8"></div>
    <button class="btn btn-gold" type="submit">Badili</button>
  </form>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>