<?php
$day  = day_of((int) $b['start_ts']);
$link = base_url('/b/' . $b['token']);
$tel  = setting('contact_phone');
?>
<?php if ($new): ?>
  <section class="card done">
    <h1>✔ تم الحجز</h1>
    <p>احفظ هذا الرابط: منه تضيف الموعد إلى تقويمك، أو تلغيه.</p>
    <button class="btn ghost" data-copy="<?= h($link) ?>">نسخ رابط الموعد</button>
  </section>
<?php endif; ?>

<section class="card summary">
  <div class="refline">
    <span class="muted">رقم الحجز</span>
    <b class="ref" dir="ltr"><?= h($b['ref']) ?></b>
    <span class="badge <?= h($b['status']) ?>"><?= h(STATUS[$b['status']] ?? $b['status']) ?></span>
  </div>
  <dl>
    <dt>الخدمة</dt><dd><?= h($b['service_name']) ?></dd>
    <dt>اليوم</dt><dd><?= h(ar_date($day, true)) ?><?php if (hijri($day) !== ''): ?> <span class="muted">(<?= h(hijri($day)) ?>)</span><?php endif; ?></dd>
    <dt>الوقت</dt><dd><?= h(ar_time((int) $b['start_ts'])) ?> – <?= h(ar_time((int) $b['end_ts'])) ?></dd>
    <dt>الاسم</dt><dd><?= h($b['name']) ?></dd>
    <dt>الجوال</dt><dd dir="ltr" class="ltr"><?= h(show_phone($b['phone'])) ?></dd>
  </dl>

  <?php if ($b['status'] === 'confirmed' && (int) $b['end_ts'] > now()): ?>
    <div class="actions">
      <a class="btn" href="/b/<?= h($b['token']) ?>.ics">أضف إلى التقويم</a>
      <a class="btn ghost" href="<?= h(google_calendar_url($b)) ?>" target="_blank" rel="noopener">تقويم Google</a>
    </div>
    <?php if (can_cancel($b)): ?>
      <form method="post" action="/b/<?= h($b['token']) ?>/cancel" data-confirm="إلغاء هذا الموعد؟" class="cancel"><?= csrf_field() ?>
        <button class="link danger">إلغاء الموعد</button>
        <span class="muted">متاح حتى <?= setting_int('cancel_notice') ?> ساعات قبل الموعد.</span>
      </form>
    <?php else: ?>
      <p class="muted">انتهت مهلة الإلغاء من الموقع<?= $tel !== '' ? '. للإلغاء تواصل معنا' : '' ?>.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($tel !== ''): ?>
    <p class="contact">للتواصل: <a href="tel:+<?= h($tel) ?>" dir="ltr"><?= h(show_phone($tel)) ?></a> · <a href="https://wa.me/<?= h($tel) ?>" target="_blank" rel="noopener">واتساب</a></p>
  <?php endif; ?>
</section>
<p class="center"><a href="/">حجز موعد آخر</a></p>
