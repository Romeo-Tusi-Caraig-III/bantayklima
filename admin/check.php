<?php
require __DIR__ . '/auth.php';
$network = isset($_GET['network']);
$checks = system_checks($network);
$groups = ['fail' => [], 'warn' => [], 'ok' => []];
foreach ($checks as $check) $groups[$check['status']][] = $check;
$labels = ['fail' => 'Needs fixing', 'warn' => 'Review', 'ok' => 'Passed'];
$badges = ['fail' => 'b-severe', 'warn' => 'b-warning', 'ok' => 'b-normal'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BantayKlima system check</title>
<?= portal_assets('../assets/') ?>
</head>
<body class="dashboard portal">
<header class="topbar"><div class="nav-inner"><a class="brand" href="index.php"><span class="logo" aria-hidden="true"></span>BantayKlima Admin</a><div class="navlinks"><?= theme_button() ?></div></div></header>
<main class="admin-report">
  <header class="portal-heading">
    <div><h1>System check</h1><p><?= $network ? 'Setup, internet access, and private file checks.' : 'Application setup and data health.' ?></p></div>
    <div class="row"><a class="btn ghost" href="index.php">Back to admin</a>
      <a class="btn" href="<?= $network ? 'check.php' : 'check.php?network=1' ?>"><?= $network ? 'Run local checks' : 'Run extended checks' ?></a></div>
  </header>
  <section class="check-summary" aria-label="Check results">
    <?php foreach ($groups as $status => $items): ?>
      <div class="card"><strong><?= count($items) ?></strong><span class="badge <?= $badges[$status] ?>"><?= e($labels[$status]) ?></span></div>
    <?php endforeach; ?>
  </section>
  <?php if (!$groups['fail'] && !$groups['warn']): ?><p class="ok">All checks passed.</p><?php endif; ?>
  <?php foreach ($groups as $status => $items): if (!$items) continue; ?>
    <section class="card check-group">
      <?php if ($status === 'ok'): ?><details><summary>Passed checks (<?= count($items) ?>)</summary>
      <?php else: ?><h2><?= e($labels[$status]) ?></h2><?php endif; ?>
      <dl class="check-list">
        <?php foreach ($items as $check): ?>
          <div><dt><?= e($check['name']) ?></dt><dd><?= e($check['detail']) ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <?php if ($status === 'ok'): ?></details><?php endif; ?>
    </section>
  <?php endforeach; ?>
</main>
</body>
</html>
