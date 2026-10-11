<?php
// Auto Admin (Intelligent Content Manager) Engine
// Integrated with Media Library, Social Accounts, Content Calendar, and Gemini API.

/**
 * Executes the Auto Admin planning and content generation for the current project.
 *
 * @param array $project Reference to current project array
 * @param bool $manual Set to true if executed manually by user
 * @return array Multi-dimensional array with status and logs/message
 */
function runAutoAdmin(&$project, $manual = false) {
    if (!$project) {
        return ['status' => 'error', 'message' => 'پروژه معتبری جهت پردازش یافت نشد.'];
    }

    $settings = $project['settings']['auto_admin'] ?? [];
    $is_active = (int)($settings['active'] ?? 0);
    
    if (!$is_active && !$manual) {
        return ['status' => 'ignored', 'message' => 'ادمین خودکار برای این پروژه غیرفعال است.'];
    }

    $horizon = (int)($settings['planning_horizon'] ?? 7);
    if ($horizon < 1) $horizon = 7;
    if ($horizon > 30) $horizon = 30; // limit to maximum 30 days foresight

    // Read target platforms/channels
    $channels = $settings['channels'] ?? [];
    if (empty($channels)) {
        // Fallback: Detect all active/configured channels in project's Social Accounts
        $channels = [];
        $conns = $project['settings']['connections'] ?? [];
        foreach (['telegram', 'bale', 'rubika', 'instagram'] as $platform) {
            $is_configured = false;
            if ($platform === 'telegram' && !empty($conns['telegram']['token'])) $is_configured = true;
            if ($platform === 'bale' && !empty($conns['bale']['token'])) $is_configured = true;
            if ($platform === 'rubika' && !empty($conns['rubika']['token'])) $is_configured = true;
            if ($platform === 'instagram' && !empty($conns['instagram']['instagram_account_id'])) $is_configured = true;
            if ($is_configured) {
                $channels[] = $platform;
            }
        }
        if (empty($channels)) {
            $channels = ['telegram']; // last-resort default
        }
    }

    $tone = $settings['tone'] ?? 'sales';
    $use_emoji = (int)($settings['use_emoji'] ?? 1);
    $caption_length = $settings['caption_length'] ?? 'medium';
    $hashtag_style = $settings['hashtag_style'] ?? 'smart';
    $custom_hashtags = $settings['custom_hashtags'] ?? '';
    $discount_pct = (int)($settings['discount_pct'] ?? 0);
    $forbidden_words = array_filter(array_map('trim', explode(',', $settings['forbidden_words'] ?? '')));
    $signature = $settings['signature'] ?? '';
    $require_approval = (int)($settings['require_approval'] ?? 1);

    // If split multi-stage approval is active in portal settings, force manual approval status
    if (!empty($project['settings']['cal_approval_split_enabled'])) {
        $require_approval = 1;
    }

    $products = $project['products'] ?? [];
    if (empty($products)) {
        logAutoAdminEvent($project, 'خطا: هیچ محصولی جهت برنامه‌ریزی در کتابخانه یافت نشد.', 'error');
        return ['status' => 'error', 'message' => 'کتابخانه محصولات خالی است.'];
    }

    $schedules = $project['schedules'] ?? [];
    $plannedInSession = [];
    $scheduledCount = 0;
    
    $toneLabels = [
        'sales' => 'فروش‌محور و ترغیب‌کننده',
        'friendly' => 'صمیمی، دوستانه و خودمانی',
        'formal' => 'رسمی، حرفه‌ای و اداری',
        'humorous' => 'سرگرم‌کننده همراه با مزاح و طنز ملایم',
        'expert' => 'تخصصی، تکنیکال و علمی محصول'
    ];
    $lengthLabels = [
        'short' => 'کوتاه و مینیمال (زیر ۱۵۰ کاراکتر)',
        'medium' => 'متوسط همراه با لیست ویژگی‌ها (بین ۱۵۰ تا ۵۰۰ کاراکتر)',
        'long' => 'طولانی، داستانی و اقناعی کامل (بالای ۵۰۰ کاراکتر)'
    ];

    $logMsg = $manual ? "اجرای دستی ادمین خودکار آغاز شد." : "اجرای زمان‌بندی روزانه ادمین خودکار آغاز شد.";
    logAutoAdminEvent($project, $logMsg, 'info');

    // Planning loop: Day by Day, from Today (Day 0) to Day (Horizon - 1)
    for ($i = 0; $i < $horizon; $i++) {
        $targetTime = strtotime("+$i days");
        $dayNameEn = date('l', $targetTime);
        $dateStr = date('Y-m-d', $targetTime);

        // Fetch slots for this day of week
        $slotsStr = $project['settings']['posting_slots'][$dayNameEn] ?? '';
        $slots = array_filter(array_map('trim', explode(',', $slotsStr)));

        if (empty($slots)) {
            continue; // Skip day if no active slot configured
        }

        foreach ($slots as $time) {
            $scheduledAtStr = $dateStr . ' ' . $time;
            $scheduledAt = strtotime($scheduledAtStr);

            // Skip past slots
            if ($scheduledAt < time()) {
                continue;
            }

            // Check if any schedule already exists at this exact time (allow 10 min window)
            $alreadyScheduled = false;
            foreach ($schedules as $s) {
                $sTime = 0;
                if (!empty($s['scheduledAt'])) {
                    $sTime = is_numeric($s['scheduledAt']) ? (int)$s['scheduledAt'] : strtotime($s['scheduledAt']);
                }
                if ($sTime > 0 && abs($sTime - $scheduledAt) < 600) {
                    $alreadyScheduled = true;
                    break;
                }
            }

            if ($alreadyScheduled) {
                // This slot is already busy, skip to respect custom/manual items
                continue;
            }

            // Empty slot detected! Let's score all products and select the absolute best match
            $scoredProducts = [];
            foreach ($products as $p) {
                // Criteria A: Active status only
                $p_status = $p['status'] ?? 'active';
                if ($p_status !== 'active') {
                    continue;
                }

                // Criteria B: "محصولات فاقد تصویر مناسب بهصورت خودکار رد شوند"
                $p_image = !empty($p['social_image']) ? $p['social_image'] : ($p['image'] ?? '');
                if (empty($p_image) || strpos($p_image, 'placeholder') !== false || strpos($p_image, 'no_image') !== false) {
                    continue; // Auto reject no-image products
                }

                // Criteria C: scoring mechanism
                $score = 100;

                // Priority weights
                $priority = (int)($p['priority'] ?? 0);
                $score += $priority * 50;

                // Portal engagement
                $likes = (int)($p['likes'] ?? 0);
                $dislikes = (int)($p['dislikes'] ?? 0);
                $score += ($likes * 10) - ($dislikes * 10);

                // Tracking recency and strict day-of-week repetition checks
                $lastScheduledAt = 0;
                foreach ($schedules as $s) {
                    if (($s['productId'] ?? '') === $p['id']) {
                        $sTime = 0;
                        if (!empty($s['scheduledAt'])) {
                            $sTime = is_numeric($s['scheduledAt']) ? (int)$s['scheduledAt'] : strtotime($s['scheduledAt']);
                        }
                        if ($sTime > 0) {
                            if ($sTime > $lastScheduledAt) {
                                $lastScheduledAt = $sTime;
                            }

                            // Strict repetition check for same day of the week (e.g., matching Monday)
                            $sDayOfWeek = date('l', $sTime);
                            if ($sDayOfWeek === $dayNameEn) {
                                $timeDiff = abs($scheduledAt - $sTime);
                                if ($timeDiff < 15 * 86400) { // Same day within 2 weeks - HUGE restriction
                                    $score -= 12000;
                                }
                            }

                            // Closeness in calendar (less than 5 days gap between any consecutive post)
                            $timeDiffGeneral = abs($scheduledAt - $sTime);
                            if ($timeDiffGeneral < 5 * 86400) {
                                $score -= 6000;
                            }
                        }
                    }
                }

                // Recency benefits
                if ($lastScheduledAt === 0) {
                    $score += 600; // Never scheduled product gets highest priority bonus
                } else {
                    $daysSinceLast = (time() - $lastScheduledAt) / 86400;
                    if ($daysSinceLast > 0) {
                        $score += min($daysSinceLast * 3, 250); // Older posts get progressively higher scores
                    }
                }

                // Diversity checking for this session: avoid repetitive planning of same item in this run
                if (in_array($p['id'], $plannedInSession)) {
                    $score -= 20000; // Almost entirely disqualify for variety
                }

                $scoredProducts[] = [
                    'product' => $p,
                    'score' => $score
                ];
            }

            // Sort products by score descending
            usort($scoredProducts, function($a, $b) {
                return $b['score'] <=> $a['score'];
            });

            // If we have a qualified product
            if (!empty($scoredProducts)) {
                $bestCand = $scoredProducts[0];
                // Ensure we only schedule positive-scoring candidates or have a safe margin
                if ($bestCand['score'] < -1000) {
                    // All products are too repetitive, skip to avoid filling bad items
                    continue;
                }

                $bestProd = $bestCand['product'];
                $productImage = !empty($bestProd['social_image']) ? $bestProd['social_image'] : ($bestProd['image'] ?? '');

                // Assemble AI core prompt
                $prodDesc = $bestProd['description'] ?? $bestProd['content'] ?? '';
                if (empty($prodDesc)) {
                    $prodDesc = "محصول ارزشمند تولید شده با برترین کیفیت فنی و متریال درجه اول.";
                }

                $activeTone = $toneLabels[$tone] ?? $toneLabels['sales'];
                $activeLength = $lengthLabels[$caption_length] ?? $lengthLabels['medium'];

                $hashtagReq = "";
                if ($hashtag_style === 'smart') {
                    $hashtagReq = "استفاده از هشتگ‌های کاملا مرتبط و تخصصی بر اساس نام و ویژگی‌های محصول.";
                } elseif ($hashtag_style === 'minimal') {
                    $hashtagReq = "فقط ۱ الی ۳ هشتگ کلی و کاملا ضروری در انتهای متن.";
                } elseif ($hashtag_style === 'dense') {
                    $hashtagReq = "هشتگ‌گذاری غنی و بهینه برای اکسپلور شبکه اجتماعی متناسب با حوزه محصول.";
                } elseif ($hashtag_style === 'custom') {
                    $hashtagReq = "استفاده از هشتگ‌های ثابت زیر:\n" . $custom_hashtags;
                }

                $discountReq = "";
                if ($discount_pct > 0) {
                    $discountReq = "توجه: برای تشویق مخاطبان به خرید، یک کد یا آفر تخفیف ویژه به میزان %{$discount_pct} بر روی متن کپشن اضافه کنید.";
                }

                $signatureReq = !empty($signature) ? "امضا و متن تشکر انتهای پست:\n" . $signature : "";

                $forbiddenReq = "";
                if (!empty($forbidden_words)) {
                    $forbiddenReq = "⛔ کلماتی که به هیچ عنوان نباید در خروجی کپشن استفاده شوند:\n" . implode(', ', $forbidden_words);
                }

                $systemInstruction = "You are an elite, world-class Persian Social Media Copywriter and content marketer for online businesses. You craft high-converting, deeply engaging, and grammatically flawless social media posts. Do NOT include any intro (like 'سلام دوست من' unless appropriate for tone), no double hashtags on the same word, and strictly return to me the direct final ready-to-publish social media message ONLY. No thoughts, no chatty preambles, and no code blocks.";

                $prompt = "لطفاً کپشن بسیار جذاب و حرفه‌ای برای محصول زیر جهت انتشار در شبکه‌های اجتماعی متصل تهیه کن:

مشخصات محصول:
- نام محصول: {$bestProd['name']}
- توضیحات کامل: {$prodDesc}
- قیمت محصول: " . (!empty($bestProd['price']) ? number_format((float)$bestProd['price']) . " تومان" : "ثبت نشده") . "

پارامترها و قوانین نگارش:
- لحن کپشن: {$activeTone}
- طول کپشن: {$activeLength}
- استفاده از اموجی: " . ($use_emoji ? "بله، آزادانه و مرتبط جهت زیبایی بصری متن استفاده شود." : "خیر، کپشن بدون اموجی و رسمی نوشته شود.") . "
- قوانین هشتگ‌گذاری: {$hashtagReq}
{$discountReq}
{$forbiddenReq}
{$signatureReq}

متن کپشن نهایی را بدون هیچ جمله توضیحی، مقدمه یا پساوند اضافه به زبان فارسی بنویس تا بلافاصله قابل کپی کردن در شبکه‌ها باشد.";

                // Generate using system API wrapper
                $aiResponse = geminiGenerateResponse($prompt, $systemInstruction);
                
                $generatedCaption = "";
                if (isset($aiResponse['error'])) {
                    // Fallback to offline standard caption rather than crashing
                    $generatedCaption = "💎 " . $bestProd['name'] . "\n\n" . mb_substr(strip_tags($prodDesc), 0, 250) . "...\n\n";
                    if (!empty($bestProd['price'])) {
                        $generatedCaption .= "💰 قیمت: " . number_format((float)$bestProd['price']) . " تومان\n";
                    }
                    if (!empty($signature)) {
                        $generatedCaption .= "\n" . $signature;
                    }
                    $generatedCaption = trim($generatedCaption);
                    logAutoAdminEvent($project, "هشدار در تولید هوشمند برای محصول '{$bestProd['name']}': " . $aiResponse['error'] . " (از قالب کپشن جایگزین استفاده شد)", 'warning');
                } else {
                    $generatedCaption = trim($aiResponse['text']);
                }

                // Register one schedule event for each allowed target channel (platform)
                $firstPlatformInBatch = true;
                foreach ($channels as $plat) {
                    $newSch = [
                        'id' => uniqid(),
                        'productId' => $bestProd['id'],
                        'productName' => $bestProd['name'],
                        'platform' => $plat,
                        'caption' => $generatedCaption,
                        'mediaUrl' => $productImage,
                        'scheduledAt' => $scheduledAtStr,
                        'status' => 'pending',
                        'approvalStatus' => $require_approval ? 'pending' : 'approved',
                        'comments' => [],
                        'reactions' => [],
                        'sentCount' => 0,
                        'created_by' => 'auto_admin',
                    ];
                    
                    $project['schedules'][] = $newSch;
                    $schedules[] = $newSch; // Keep local track updated for subsequent loop date clashes
                    $scheduledCount++;

                    if ($firstPlatformInBatch) {
                        $logDetail = "محصول '{$bestProd['name']}' با موفقیت در تاریخ " . jalaliFormat($scheduledAt) . " ساعت {$time} برای انتشار روی ({$plat}) برنامه‌ریزی شد.";
                        logAutoAdminEvent($project, $logDetail, 'success');
                        $firstPlatformInBatch = false;
                    }
                }

                $plannedInSession[] = $bestProd['id'];
            }
        }
    }

    if ($scheduledCount > 0) {
        $project['settings']['auto_admin']['last_auto_admin_run_date'] = date('Y-m-d');
        saveProject($project);
        logAutoAdminEvent($project, "پایان موفقیت‌آمیز برنامه‌ریزی. تعداد {$scheduledCount} پست جدید ثبت و به تقویم افزوده شد.", 'success');
        return ['status' => 'success', 'message' => "برنامه‌ریزی با موفقیت انجام شد و تعداد {$scheduledCount} ردیف پست به تقویم سیستم افزوده گردید."];
    } else {
        $project['settings']['auto_admin']['last_auto_admin_run_date'] = date('Y-m-d');
        saveProject($project);
        logAutoAdminEvent($project, "پایان پردازش. تغییری نیاز نبود یا تمام بازه‌های زمانی تقویم پر بودند.", 'info');
        return ['status' => 'ignored', 'message' => 'تمام بازه‌های مجاز در تقویم محتوایی از قبل پر بودند یا کاندیدای معتبری یافت نشد.'];
    }
}

/**
 * Saves systematic activity logs specifically for the Auto Admin actions.
 */
function logAutoAdminEvent(&$project, $message, $status = 'info') {
    if (!isset($project['settings']['auto_admin_logs'])) {
        $project['settings']['auto_admin_logs'] = [];
    }
    
    $project['settings']['auto_admin_logs'][] = [
        'id' => uniqid(),
        'timestamp' => time(),
        'message' => $message,
        'status' => $status
    ];
    
    // Maintain a max list of 100 entries to prevent structural bloat
    if (count($project['settings']['auto_admin_logs']) > 100) {
        $project['settings']['auto_admin_logs'] = array_slice($project['settings']['auto_admin_logs'], -100);
    }
}

/**
 * Helper to display Persian/Jalali reading dates nicely
 */
function jalaliFormat($timestamp) {
    if (function_exists('gregorian_to_jalali')) {
        $gy = date('Y', $timestamp);
        $gm = date('m', $timestamp);
        $gd = date('d', $timestamp);
        $jDate = gregorian_to_jalali($gy, $gm, $gd);
        return $jDate[0] . '/' . sprintf('%02d', $jDate[1]) . '/' . sprintf('%02d', $jDate[2]);
    }
    return date('Y-m-d', $timestamp);
}
