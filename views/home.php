<section class="hero">
  <h1><?= h(setting('business_name')) ?></h1>
  <?php if (setting('intro') !== ''): ?><p><?= h(setting('intro')) ?></p><?php endif; ?>
</section>
<?php if (!$services): ?>
  <section class="card center"><p class="muted">لا توجد خدمات متاحة للحجز حالياً.</p></section>
<?php else: ?>
  <h2 class="step"><span>1</span> اختر الخدمة</h2>
  <div class="services">
    <?php foreach ($services as $s): ?>
      <a class="card service" href="/?s=<?= (int) $s['id'] ?>">
        <b><?= h($s['name']) ?></b>
        <?php if ($s['description'] !== ''): ?><span class="muted"><?= h($s['description']) ?></span><?php endif; ?>
        <span class="meta"><span>⏱ <?= h(ar_minutes((int) $s['duration'])) ?></span><?php if ($s['price'] !== ''): ?><span><?= h($s['price']) ?> ريال</span><?php endif; ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
