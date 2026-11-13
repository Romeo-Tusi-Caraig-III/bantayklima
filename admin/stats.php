<?php
require __DIR__ . '/auth.php';
$pdo = db();

function bar(int $value, int $max): string {
    return '<div class="bar"><span style="width:' . ($max > 0 ? round($value / $max * 100) : 0) . '%"></span></div>';
}

$t = $pdo->query("SELECT COUNT(*) total, SUM(status = 'active') active, SUM(status = 'pending') pending,
    SUM(status = 'active' AND phone IS NOT NULL AND phone <> '') with_phone, SUM(status = 'active' AND language = 'fil') filipino FROM subscribers")->fetch();
$total = (int) $t['total']; $active = (int) $t['active']; $pending = (int) $t['pending'];

$byTown = $pdo->query("SELECT m.name, COUNT(s.id) c FROM municipalities m LEFT JOIN subscribers s ON s.municipality_id = m.id AND s.status = 'active'
    GROUP BY m.id ORDER BY c DESC, m.name")->fetchAll();
$covered = count(array_filter($byTown, fn ($r) => $r['c'] > 0));
$maxTown = max(array_column($byTown, 'c') ?: [0]);

$msgs = $pdo->query("SELECT channel, status, COUNT(*) c FROM messages WHERE status <> 'simulated' GROUP BY channel, status ORDER BY channel, c DESC")->fetchAll();
$delivered = "status IN ('sent', 'queued', 'pending')";
$w7 = (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE $delivered AND created_at >= datetime('now', '-7 days')")->fetchColumn();
$w30 = (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE $delivered AND created_at >= datetime('now', '-30 days')")->fetchColumn();
$failed = (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE status = 'failed'")->fetchColumn();
$credits = (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE channel = 'sms' AND $delivered")->fetchColumn();

$levels = $pdo->query("SELECT level, COUNT(*) c FROM (
    SELECT a.level FROM municipalities m JOIN risk_assessments a ON a.id = (SELECT MAX(id) FROM risk_assessments WHERE municipality_id = m.id AND source = 'live'))
    GROUP BY level")->fetchAll(PDO::FETCH_KEY_PAIR);

$changes = $pdo->query("WITH x AS (SELECT a.municipality_id, a.hazard, a.level, a.created_at,
        LAG(a.level) OVER (PARTITION BY a.municipality_id ORDER BY a.id) AS prev_level,
        LAG(a.hazard) OVER (PARTITION BY a.municipality_id ORDER BY a.id) AS prev_hazard
        FROM risk_assessments a WHERE a.source = 'live')
    SELECT m.name, x.created_at, x.prev_level, x.prev_hazard, x.level, x.hazard FROM x JOIN municipalities m ON m.id = x.municipality_id
    WHERE x.prev_level IS NOT NULL AND (x.level <> x.prev_level OR x.hazard <> x.prev_hazard) ORDER BY x.created_at DESC LIMIT 15")->fetchAll();
$nChanges = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT a.level, a.hazard, LAG(a.level) OVER (PARTITION BY a.municipality_id ORDER BY a.id) pl,
    LAG(a.hazard) OVER (PARTITION BY a.municipality_id ORDER BY a.id) ph FROM risk_assessments a WHERE a.source = 'live') WHERE pl IS NOT NULL AND (level <> pl OR hazard <> ph)")->fetchColumn();

$firstWarn = $pdo->query("SELECT m.name, MIN(a.created_at) first_at, a.hazard FROM risk_assessments a JOIN municipalities m ON m.id = a.municipality_id
    WHERE a.source = 'live' AND a.level IN ('warning', 'severe') GROUP BY m.id ORDER BY first_at LIMIT 30")->fetchAll();
$st = $pdo->prepare('SELECT month, issued, created_at FROM advisories WHERE province = ? ORDER BY id DESC LIMIT 1');
$st->execute([cfg('province')]);
$adv = $st->fetch();
$liveRuns = (int) $pdo->query("SELECT COUNT(DISTINCT date(created_at, '+8 hours')) FROM risk_assessments WHERE source = 'live'")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BantayKlima statistics</title>
<?= portal_assets('../assets/') ?>
</head>
<body class="dashboard portal">
<header class="topbar"><div class="nav-inner"><a class="brand" href="index.php"><span class="logo" aria-hidden="true"></span>BantayKlima Admin</a><div class="navlinks"><?= theme_button() ?></div></div></header>
<main class="admin-report">
  <header class="portal-heading">
    <div><h1>Statistics and exports</h1><p>Subscriber reach, message activity, and live risk assessments.</p></div>
    <div class="row"><a class="btn ghost" href="index.php">Back to admin</a><a class="btn" href="#csv-exports">CSV exports</a></div>
  </header>

  <div class="report-columns">
  <div class="admin-stack">
  <section class="card">
    <h2>Reach</h2>
    <div class="stats">
      <div><strong><?= $active ?></strong><span>confirmed subscribers</span></div>
      <div><strong><?= $covered ?> of <?= count($byTown) ?></strong><span>municipalities with a subscriber</span></div>
      <div><strong><?= $active ? round(((int) $t['with_phone']) / $active * 100) : 0 ?>%</strong><span>reachable by SMS</span></div>
      <div><strong><?= $active ? round(((int) $t['filipino']) / $active * 100) : 0 ?>%</strong><span>chose Filipino</span></div>
      <div><strong><?= $total ? round($active / $total * 100) : 0 ?>%</strong><span>of sign-ups confirmed (<?= $pending ?> waiting)</span></div>
    </div>
    <h3>Subscribers by municipality</h3>
    <?php foreach ($byTown as $r): if (!$r['c']) continue; ?>
      <div class="barrow"><span><?= e($r['name']) ?></span><?= bar((int) $r['c'], $maxTown) ?><span><?= (int) $r['c'] ?></span></div>
    <?php endforeach; if (!$maxTown): ?><p class="report-empty">No confirmed subscribers yet. Towns appear here as residents subscribe.</p><?php endif; ?>
  </section>

  <section class="card">
    <h2>Live risk levels</h2>
    <?php if ($levels): ?>
    <div class="report-levels">
      <?php foreach (['normal', 'watch', 'warning', 'severe'] as $lv): ?>
        <div><span class="badge b-<?= e($lv) ?>"><?= e(ucfirst($lv)) ?></span><strong><?= (int) ($levels[$lv] ?? 0) ?></strong></div>
      <?php endforeach; ?>
    </div>
    <p class="small"><?= $nChanges ?> changes across <?= $liveRuns ?> day(s) of live assessments.</p>
    <?php else: ?><p class="report-empty">No live assessments yet. <a href="index.php#settings/refresh-risk">Update risk levels</a>.</p><?php endif; ?>
    <?php if ($changes): ?><h3>Latest changes</h3><div class="scroll"><table>
      <thead><tr><th>When</th><th>Place</th><th>From</th><th>To</th></tr></thead>
      <tbody><?php foreach ($changes as $c): ?><tr><td><?= e(local_time($c['created_at'])) ?></td><td><?= e($c['name']) ?></td>
        <td><?= e($c['prev_level'] . ($c['prev_hazard'] !== 'none' ? ' ' . $c['prev_hazard'] : '')) ?></td>
        <td><?= e($c['level'] . ($c['hazard'] !== 'none' ? ' ' . $c['hazard'] : '')) ?></td></tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>
  </div>

  <div class="admin-stack">
  <section class="card">
    <h2>Message activity</h2>
    <div class="stats">
      <div><strong><?= $w7 ?></strong><span>sent or queued, last 7 days</span></div>
      <div><strong><?= $w30 ?></strong><span>sent or queued, last 30 days</span></div>
      <div><strong><?= $failed ?></strong><span>failed messages</span></div>
      <div><strong><?= $credits ?></strong><span>estimated SMS credits</span></div>
    </div>
    <?php if ($msgs): ?><div class="scroll"><table>
      <thead><tr><th scope="col">Channel</th><th scope="col">Result</th><th scope="col">Messages</th></tr></thead>
      <tbody><?php foreach ($msgs as $m): ?><tr><td><?= e($m['channel']) ?></td><td><?= e($m['status']) ?></td><td><?= (int) $m['c'] ?></td></tr><?php endforeach; ?></tbody>
    </table></div><?php else: ?><p class="report-empty">No message activity has been recorded yet.</p><?php endif; ?>
  </section>
  <section class="card">
    <h2>First Warning or Severe</h2>
    <p class="small">Compare the first live warning for each town with when PAGASA or the LGU warned about the same conditions.</p>
    <?php if ($adv): ?><p class="small">Latest PAGASA table: <?= e(date('F Y', strtotime($adv['month']))) ?><?= $adv['issued'] ? ' · Issued ' . e($adv['issued']) : '' ?></p><?php endif; ?>
    <?php if ($firstWarn): ?><div class="scroll"><table>
      <thead><tr><th>Place</th><th>First Warning or higher</th><th>Hazard</th></tr></thead>
      <tbody><?php foreach ($firstWarn as $f): ?><tr><td><?= e($f['name']) ?></td><td><?= e(local_time($f['first_at'])) ?></td><td><?= e($f['hazard']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div><?php else: ?><p class="report-empty">No live Warning or Severe assessment has been recorded.</p><?php endif; ?>
  </section>
  </div>
  </div>

  <section class="card report-exports" id="csv-exports">
    <div><h2>CSV exports</h2><p class="small">Open in Excel. Personal details are masked.</p></div>
    <div class="row"><a class="btn ghost" href="export.php?what=subscribers">Subscribers</a> <a class="btn ghost" href="export.php?what=messages">Messages</a>
       <a class="btn ghost" href="export.php?what=history">Risk history</a> <a class="btn ghost" href="export.php?what=changes">Level changes</a></div>
  </section>
</main>
</body>
</html>
