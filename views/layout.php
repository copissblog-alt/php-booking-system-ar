<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!empty($user) || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/b/')): ?><meta name="robots" content="noindex"><?php endif; ?>
<title><?= h($title ?? '') ?> · <?= h(setting('business_name')) ?></title>
<link rel="stylesheet" href="/assets/style.css">
<script src="/assets/app.js" defer></script>
</head>
<body>
<header class="top">
  <a href="<?= !empty($user) ? '/admin' : '/' ?>" class="brand"><?= h(setting('business_name')) ?></a>
<?php if (!empty($user)): ?>
  <nav class="nav">
    <?php foreach (['bookings' => ['/admin', 'المواعيد'], 'services' => ['/admin/services', 'الخدمات'], 'hours' => ['/admin/hours', 'الدوام'], 'settings' => ['/admin/settings', 'الإعدادات']] as $k => [$href, $label]): ?>
      <a href="<?= $href ?>"<?= ($nav ?? '') === $k ? ' class="on"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a href="/" target="_blank">صفحة الحجز ↗</a>
  </nav>
  <form method="post" action="/admin/logout" class="inline"><?= csrf_field() ?><span class="muted"><?= h($user['username']) ?></span> <button class="link">خروج</button></form>
<?php endif; ?>
</header>
<main>
<?php if (!empty($flash)): ?><p class="msg <?= h($flash[0]) ?>"><?= h($flash[1]) ?></p><?php endif; ?>
<?= $content ?>
</main>
</body>
</html>
