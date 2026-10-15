<?php if (empty($neoPilot)) return; ?>
<div class="neo-intro">
    <div><div class="neo-eyebrow">پنل مارک‌شو / نمای کلی پروژه</div><h2><?php echo htmlspecialchars($project['name']??'', ENT_QUOTES, 'UTF-8'); ?></h2><p>خوش آمدید؛ وضعیت محتوا و برنامه‌های پروژه را اینجا دنبال کنید.</p></div>
    <div class="neo-actions">
        <?php if(hasPermission('products') && isModuleActive('products')): ?><a class="neo-primary" href="?tab=products">کتابخانه محتوا</a><?php endif; ?>
        <?php if(hasPermission('calendar') && isModuleActive('calendar')): ?><a href="?tab=calendar">تقویم پروژه</a><?php endif; ?>
        <?php if(isModuleActive('support') && checkModuleSettingAccess('support', $project['settings']??[])): ?><a href="/panel/support.php?project=<?php echo rawurlencode($project['id']); ?>">درخواست پشتیبانی</a><?php endif; ?>
    </div>
</div>
