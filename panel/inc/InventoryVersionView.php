<?php if(canAccessFeature('products','use')): ?>
<link rel="stylesheet" href="assets/inventory-versions.css?v=1">
<section class="inventory-versions" data-inventory-versions data-project="<?=htmlspecialchars((string)$project['id'],ENT_QUOTES,'UTF-8')?>" data-source="<?=htmlspecialchars($inventoryVersionSource??'',ENT_QUOTES,'UTF-8')?>">
<h3>تاریخچهٔ واردسازی و بازگشت به نسخهٔ قبل</h3>
<p>پیش‌نمایش قبل از ثبت، فهرست همهٔ تغییرات و بازگردانی هر واردسازی. اگر فیلد تغییرکرده بعداً ویرایش شده باشد، بازگردانی برای حفظ آن ویرایش متوقف می‌شود. نسخه‌ها در پایگاه داده ذخیره می‌شوند.</p>
<div class="iv-tools"><select aria-label="انتخاب اجرای موجودی"></select><button type="button" class="iv-refresh">تازه‌سازی تاریخچه</button></div><div class="iv-detail" aria-live="polite"></div>
</section>
<script src="assets/inventory-versions.js?v=1"></script>
<?php endif; ?>
