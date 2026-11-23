<?php
$fields = function (array $s = []) {
    ob_start(); ?>
    <label>اسم الخدمة<input name="name" required maxlength="80" value="<?= h($s['name'] ?? '') ?>"></label>
    <label>المدة<select name="duration">
      <?php foreach (DURATIONS as $m): ?><option value="<?= $m ?>"<?= (int) ($s['duration'] ?? 30) === $m ? ' selected' : '' ?>><?= h(ar_minutes($m)) ?></option><?php endforeach; ?>
    </select></label>
    <label>السعر بالريال (اختياري)<input name="price" inputmode="decimal" dir="ltr" value="<?= h($s['price'] ?? '') ?>"></label>
    <label>الترتيب<input name="sort" type="number" dir="ltr" value="<?= (int) ($s['sort'] ?? 0) ?>"></label>
    <label class="wide">وصف قصير (اختياري)<input name="description" maxlength="300" value="<?= h($s['description'] ?? '') ?>"></label>
<?php return ob_get_clean(); };
?>
<section class="card">
  <h1>الخدمات</h1>
  <?php if (!$services): ?><p class="muted">أضف خدمتك الأولى: كشف، قص شعر، جلسة استشارة...</p><?php endif; ?>
  <?php foreach ($services as $s): ?>
    <details class="svc<?= $s['active'] ? '' : ' off' ?>">
      <summary><b><?= h($s['name']) ?></b> <span class="muted"><?= h(ar_minutes((int) $s['duration'])) ?><?= $s['price'] !== '' ? ' · ' . h($s['price']) . ' ريال' : '' ?><?= $s['active'] ? '' : ' · موقوفة' ?></span></summary>
      <form method="post" action="/admin/services" class="grid"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <?= $fields($s) ?>
        <div class="wide"><button class="btn">حفظ</button></div>
      </form>
      <div class="svc-ops">
        <form method="post" action="/admin/services/toggle" class="inline"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="on" value="<?= $s['active'] ? '0' : '1' ?>">
          <button class="link"><?= $s['active'] ? 'إيقاف الخدمة' : 'تشغيل الخدمة' ?></button>
        </form>
        <form method="post" action="/admin/services/delete" class="inline" data-confirm="حذف «<?= h($s['name']) ?>»؟"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="link danger">حذف</button>
        </form>
      </div>
    </details>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>خدمة جديدة</h2>
  <form method="post" action="/admin/services" class="grid"><?= csrf_field() ?>
    <?= $fields() ?>
    <div class="wide"><button class="btn">إضافة</button></div>
  </form>
</section>
