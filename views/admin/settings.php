<?php
$num = function (string $k, string $label, string $unit, string $hint = '') {
    return '<label>' . h($label) . '<span class="unit"><input name="' . $k . '" type="number" min="0" dir="ltr" value="' . h(setting($k)) . '"> ' . h($unit) . '</span>'
        . ($hint !== '' ? '<small class="muted">' . h($hint) . '</small>' : '') . '</label>';
};
?>
<section class="card">
  <h1>الإعدادات</h1>
  <form method="post" action="/admin/settings" class="grid"><?= csrf_field() ?>
    <label class="wide">اسم النشاط<input name="business_name" required maxlength="80" value="<?= h(setting('business_name')) ?>"></label>
    <label class="wide">نبذة تظهر في صفحة الحجز<input name="intro" maxlength="300" value="<?= h(setting('intro')) ?>"></label>
    <label>رقم التواصل (يظهر للعميل)<input name="contact_phone" inputmode="tel" dir="ltr" value="<?= h(setting('contact_phone') !== '' ? show_phone(setting('contact_phone')) : '') ?>"></label>
    <span></span>
    <?= $num('slot_step', 'يبدأ موعد كل', 'دقيقة', '30 تعني أن الأوقات تظهر 4:00 ثم 4:30 ثم 5:00') ?>
    <?= $num('buffer', 'راحة بين موعدين', 'دقيقة', 'للتنظيف أو التجهيز. 0 = بلا راحة') ?>
    <?= $num('min_notice', 'أقل مدة قبل الموعد', 'ساعة', 'لا يُحجز موعد يبدأ بعد أقل من هذه المدة') ?>
    <?= $num('max_days', 'أبعد يوم للحجز', 'يوماً') ?>
    <?= $num('cancel_notice', 'يلغي العميل بنفسه حتى', 'ساعة قبل الموعد') ?>
    <?= $num('max_per_phone', 'مواعيد قادمة لكل رقم', 'موعد') ?>
    <?= $num('ip_limit', 'حجوزات من الجهاز نفسه', 'في الساعة', 'يحمي من ملء الجدول بحجوزات وهمية') ?>
    <div class="wide"><button class="btn">حفظ الإعدادات</button></div>
  </form>
</section>
