<?php $day = day_of($t); ?>
<section class="card summary">
  <h1>تأكيد الحجز</h1>
  <dl>
    <dt>الخدمة</dt><dd><?= h($service['name']) ?></dd>
    <dt>اليوم</dt><dd><?= h(ar_date($day, true)) ?><?php if (hijri($day) !== ''): ?> <span class="muted">(<?= h(hijri($day)) ?>)</span><?php endif; ?></dd>
    <dt>الوقت</dt><dd><?= h(ar_time($t)) ?> <span class="muted">· <?= h(ar_minutes((int) $service['duration'])) ?></span></dd>
    <?php if ($service['price'] !== ''): ?><dt>السعر</dt><dd><?= h($service['price']) ?> ريال</dd><?php endif; ?>
  </dl>
  <a href="/?s=<?= (int) $service['id'] ?>&amp;d=<?= h($day) ?>">تغيير الوقت</a>
</section>

<section class="card">
  <?php if ($error): ?><p class="msg bad"><?= h($error) ?></p><?php endif; ?>
  <form method="post" action="/book" data-once><?= csrf_field() ?>
    <input type="hidden" name="s" value="<?= (int) $service['id'] ?>">
    <input type="hidden" name="t" value="<?= (int) $t ?>">
    <label>الاسم<input name="name" required maxlength="60" autocomplete="name" value="<?= h($old['name'] ?? '') ?>"></label>
    <label>رقم الجوال<input name="phone" required inputmode="tel" dir="ltr" autocomplete="tel" placeholder="05XXXXXXXX" value="<?= h($old['phone'] ?? '') ?>"></label>
    <label>ملاحظة (اختياري)<textarea name="note" maxlength="300" rows="2"><?= h($old['note'] ?? '') ?></textarea></label>
    <label class="hp" aria-hidden="true">اتركه فارغاً<input name="website" tabindex="-1" autocomplete="off"></label>
    <button class="btn wide">تأكيد الحجز</button>
  </form>
</section>
