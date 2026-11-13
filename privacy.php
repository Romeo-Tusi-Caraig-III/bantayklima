<?php require __DIR__ . '/lib/bootstrap.php'; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy notice | BantayKlima</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header><h1>BantayKlima</h1><p>Privacy notice</p></header>
<main>
  <section class="card prose">
    <h2>Who we are</h2>
    <p>BantayKlima is a student project that gives El Niño and La Niña early warnings for <?= e(cfg('province')) ?>.
       It is not an official service of PAGASA, DOST or any local government unit, and it is not affiliated with them.</p>

    <h2>What we collect</h2>
    <p>When you sign up for alerts: your name, your mobile number and/or email, the municipality you chose, and your preferred language.
       We also keep the time you agreed to this notice and a record of the messages we sent you.</p>

    <h2>Why we collect it</h2>
    <p>Only to send you climate alerts and announcements for your municipality, and to confirm that the number or email is yours.
       We do not use it for advertising and we do not sell it.</p>

    <h2>Who sees it</h2>
    <p>The project administrators. To send text messages, your mobile number and the message go to our SMS provider, Semaphore.
       Your email goes through our email service if email alerts are switched on.</p>

    <h2>How long we keep it</h2>
    <p>Until you stop the alerts. When you stop, your details and the records of messages sent to you are deleted.
       Message records are in any case deleted after <?= (int) cfg('message_keep_days') ?> days. Sign-ups that are not confirmed with the code are deleted after one day.
       Our database backups are kept for a limited time (<?= (int) cfg('backup_keep') ?> backups) and then replaced.</p>

    <h2>Your rights</h2>
    <p>Under the Data Privacy Act of 2012 (Republic Act No. 10173) you have the right to be informed, to access and correct your information,
       to object, to withdraw your consent at any time, and to ask us to delete your information. You can also complain to the National Privacy Commission.</p>
    <ul>
      <li>Stop alerts and remove your details: <a href="unsubscribe.php">Stop alerts page</a>, or use the link at the bottom of any email.</li>
      <li>Any other request: write to <?= e(cfg('contact_email')) ?>.</li>
    </ul>

    <p><a href="index.html">Back to the dashboard</a></p>
  </section>
</main>
</body>
</html>
