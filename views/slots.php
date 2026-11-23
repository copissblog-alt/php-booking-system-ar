<section class="card picked">
  <div>
    <b><?= h($service['name']) ?></b>
    <span class="muted">⏱ <?= h(ar_minutes((int) $service['duration'])) ?><?= $service['price'] !== '' ? ' · ' . h($service['price']) . ' ريال' : '' ?></span>
  </div>
  <?php if (!$single): ?><a href="/">تغيير الخدمة</a><?php endif; ?>
</section>

<?php if ($error): ?><p class="msg bad"><?= h($error) ?></p><?php endif; ?>

<h2 class="step"><span>2</span> اختر اليوم</h2>
<nav class="days">
  <?php foreach ($days as $d): ?>
    <a href="/?s=<?= (int) $service['id'] ?>&amp;d=<?= h($d['day']) ?>" class="day<?= $d['day'] === $day ? ' on' : '' ?><?= $d['n'] === 0 ? ' full' : '' ?>">
      <span><?= h(AR_DAYS[(int) date('w', strtotime($d['day']))]) ?></span>
      <b><?= (int) substr($d['day'], 8, 2) ?></b>
      <small><?= h(AR_MONTHS[(int) substr($d['day'], 5, 2)]) ?></small>
      <em><?= $d['closed'] !== null ? 'مغلق' : ($d['n'] > 0 ? $d['n'] . ' متاح' : 'لا يوجد') ?></em>
    </a>
  <?php endforeach; ?>
</nav>

<h2 class="step"><span>3</span> اختر الوقت <small class="muted"><?= h(ar_date($day)) ?><?= hijri($day) !== '' ? ' · ' . h(hijri($day)) : '' ?></small></h2>
<?php if ($slots): ?>
  <div class="slots">
    <?php foreach ($slots as $ts): ?>
      <a class="slot" href="/?s=<?= (int) $service['id'] ?>&amp;t=<?= $ts ?>"><?= h(ar_time($ts)) ?></a>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <p class="card center muted">
    <?php $reason = closed_reason($day); ?>
    <?= $reason !== null ? 'مغلق هذا اليوم' . ($reason !== '' ? ': ' . h($reason) : '') . '.' : 'لا توجد أوقات متاحة في هذا اليوم. جرّب يوماً آخر.' ?>
  </p>
<?php endif; ?>
