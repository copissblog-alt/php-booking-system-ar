<?php
$tabs = ['today' => 'اليوم (' . $counts['today'] . ')', 'upcoming' => 'القادمة (' . $counts['upcoming'] . ')', 'past' => 'السابقة', 'cancelled' => 'الملغاة'];
$back = '?' . http_build_query(array_filter(['tab' => $tab, 'q' => $q]));
$lastDay = null;
?>
<div class="bar-top">
  <nav class="tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="/admin?tab=<?= $k ?>"<?= $tab === $k ? ' class="on"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form method="get" class="search">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input name="q" value="<?= h($q) ?>" placeholder="اسم أو جوال أو رقم حجز">
    <button class="btn ghost">بحث</button>
  </form>
</div>

<?php if (!$rows): ?>
  <section class="card center muted">لا توجد مواعيد هنا<?= $q !== '' ? ' تطابق البحث' : '' ?>.</section>
<?php endif; ?>

<?php foreach ($rows as $r):
    $d = day_of((int) $r['start_ts']);
    if ($d !== $lastDay): $lastDay = $d; ?>
  <h3 class="dayhead"><?= h(ar_date($d, true)) ?></h3>
<?php endif; ?>
  <article class="card row <?= h($r['status']) ?>">
    <div class="when"><b><?= h(ar_time((int) $r['start_ts'])) ?></b><span class="muted"><?= h(ar_minutes(intdiv((int) $r['end_ts'] - (int) $r['start_ts'], 60))) ?></span></div>
    <div class="who">
      <b><?= h($r['name']) ?></b> <span class="badge <?= h($r['status']) ?>"><?= h(STATUS[$r['status']]) ?></span>
      <span><?= h($r['service_name']) ?> · <span dir="ltr"><?= h(show_phone($r['phone'])) ?></span> · <span class="muted" dir="ltr"><?= h($r['ref']) ?></span></span>
      <?php if ($r['note'] !== ''): ?><span class="note"><?= h($r['note']) ?></span><?php endif; ?>
    </div>
    <div class="ops">
      <a href="<?= h(whatsapp_url($r)) ?>" target="_blank" rel="noopener" class="wa">واتساب</a>
      <?php foreach (['done' => 'حضر', 'no_show' => 'لم يحضر', 'cancelled' => 'إلغاء', 'confirmed' => 'إرجاع'] as $st => $label):
          if ($st === $r['status'] || ($st === 'confirmed' && $r['status'] === 'confirmed')) continue;
          if ($st !== 'confirmed' && $r['status'] !== 'confirmed') continue; ?>
        <form method="post" action="/admin/status" class="inline"<?= $st === 'cancelled' ? ' data-confirm="إلغاء موعد ' . h($r['name']) . '؟"' : '' ?>><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <button class="link<?= $st === 'cancelled' ? ' danger' : '' ?>"><?= $label ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </article>
<?php endforeach; ?>

<p class="muted center"><a href="/admin/export.csv">تنزيل كل المواعيد CSV</a></p>
