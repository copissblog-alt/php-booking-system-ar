<section class="card">
  <h1>الدوام الأسبوعي</h1>
  <p class="muted">لكل يوم فترتان: صباحية ومسائية. اترك الثانية فارغة إن كان الدوام فترة واحدة. فترة تنتهي بعد منتصف الليل (مثل 16:00 إلى 01:00) تُكتب كما هي.</p>
  <form method="post" action="/admin/hours"><?= csrf_field() ?>
    <div class="scroll">
    <table class="hours">
      <thead><tr><th>اليوم</th><th>الفترة الأولى</th><th>الفترة الثانية</th></tr></thead>
      <tbody>
      <?php for ($w = 0; $w < 7; $w++): $r = $week[$w]; ?>
        <tr>
          <td><label class="check"><input type="checkbox" name="open[<?= $w ?>]" value="1" data-day<?= $r ? ' checked' : '' ?>> <?= AR_DAYS[$w] ?></label></td>
          <?php for ($i = 0; $i < 2; $i++): ?>
            <td class="pair">
              <input type="time" name="start[<?= $w ?>][<?= $i ?>]" value="<?= h($r[$i][0] ?? '') ?>" dir="ltr">
              <span>إلى</span>
              <input type="time" name="end[<?= $w ?>][<?= $i ?>]" value="<?= h($r[$i][1] ?? '') ?>" dir="ltr">
            </td>
          <?php endfor; ?>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    </div>
    <button class="btn">حفظ الدوام</button>
  </form>
</section>

<section class="card">
  <h2>أيام الإغلاق</h2>
  <p class="muted">إجازة أو عيد أو سفر: لا تظهر أوقات هذا اليوم للحجز.</p>
  <form method="post" action="/admin/closed/add" class="grid"><?= csrf_field() ?>
    <label>التاريخ<input type="date" name="day" required dir="ltr" min="<?= h(day_of(now())) ?>"></label>
    <label>السبب (اختياري)<input name="reason" maxlength="80" placeholder="إجازة عيد الفطر"></label>
    <div class="wide"><button class="btn ghost">إضافة يوم إغلاق</button></div>
  </form>
  <?php if ($closed): ?>
    <ul class="closed">
      <?php foreach ($closed as $c): ?>
        <li><?= h(ar_date($c['day'], true)) ?><?= $c['reason'] !== '' ? ' · ' . h($c['reason']) : '' ?>
          <form method="post" action="/admin/closed/delete" class="inline"><?= csrf_field() ?><input type="hidden" name="day" value="<?= h($c['day']) ?>"><button class="link danger">حذف</button></form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
