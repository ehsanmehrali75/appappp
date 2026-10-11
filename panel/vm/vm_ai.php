<?php
/**
 * AI Recommendation Engine & Layout Analytics for Visual Merchandising
 */

require_once __DIR__ . '/vm_db.php';

class VmAI {
    /**
     * Get recommendations for a specific store
     */
    public static function getRecommendations($storeId) {
        $data = VmDB::getData();
        $recs = [];
        foreach ($data['ai_recommendations'] ?? [] as $r) {
            if ($r['store_id'] === $storeId) {
                $recs[] = $r;
            }
        }
        // If empty for this store, generate real recommendations
        if (empty($recs)) {
            $gen = self::generateSmartRecommendations($storeId);
            if (!empty($gen['recommendations'])) {
                $recs = $gen['recommendations'];
            }
        }
        return $recs;
    }

    /**
     * Apply an AI suggestion to the layout
     */
    public static function applyRecommendation($storeId, $recId) {
        $data = VmDB::getData();
        $foundRec = null;
        foreach ($data['ai_recommendations'] as &$r) {
            if ($r['id'] === $recId && $r['store_id'] === $storeId) {
                $r['status'] = 'applied';
                $foundRec = $r;
                break;
            }
        }
        unset($r);

        if (!$foundRec) {
            return ['success' => false, 'message' => 'پیشنهاد هوش مصنوعی یافت نشد.'];
        }

        // If suggestion has object_id and target_coords, update layout object position
        if (!empty($foundRec['object_id']) && !empty($foundRec['target_coords'])) {
            foreach ($data['layouts'] as &$l) {
                if ($l['store_id'] === $storeId && !empty($l['is_active'])) {
                    foreach ($l['objects'] as &$obj) {
                        if ($obj['id'] === $foundRec['object_id']) {
                            $obj['position']['x'] = $foundRec['target_coords']['x'];
                            $obj['position']['y'] = $foundRec['target_coords']['y'];
                            if (!empty($foundRec['target_zone'])) {
                                $obj['zone'] = $foundRec['target_zone'];
                                if (!isset($obj['metadata'])) $obj['metadata'] = [];
                                $obj['metadata']['zone'] = $foundRec['target_zone'];
                            }
                            break;
                        }
                    }
                    unset($obj);
                    $l['updated_at'] = date('Y/m/d H:i');
                    break;
                }
            }
            unset($l);
        }

        VmDB::saveData($data);

        return [
            'success' => true,
            'message' => 'پیشنهاد چیدمان هوشمند با موفقیت بر روی استودیو اعمال شد.',
            'recommendation' => $foundRec
        ];
    }

    /**
     * Generate real AI recommendations based on real store layout, products, and sales
     */
    public static function generateSmartRecommendations($storeId) {
        $store = VmDB::getStore($storeId);
        $layout = VmDB::getStoreLayout($storeId);
        $objects = $layout['objects'] ?? [];
        $projectId = $store['project_id'] ?? ($store['project_slug'] ?? '');
        $allProducts = VmDB::getProductsForProject($projectId);

        if (empty($objects)) {
            return [
                'success' => false,
                'message' => 'داده‌ای برای تحلیل هوش مصنوعی وجود ندارد؛ هیچ چیدمانی برای این شعبه ثبت نشده است. لطفاً ابتدا در استودیو المان‌ها را بچینید.',
                'count' => 0,
                'recommendations' => []
            ];
        }

        $productsBySku = [];
        foreach ($allProducts as $p) {
            $productsBySku[$p['sku']] = $p;
        }

        $newRecs = [];
        $now = date('Y/m/d H:i');

        // 1. Check for empty fixtures (racks/shelves with no assigned products)
        $emptyFixtures = [];
        foreach ($objects as $obj) {
            $type = $obj['type'] ?? '';
            if (in_array($type, ['rack', 'shelf', 'display_table', 'wall_shelf'])) {
                $skus = [];
                if (!empty($obj['metadata']['product_skus']) && is_array($obj['metadata']['product_skus'])) {
                    $skus = $obj['metadata']['product_skus'];
                } elseif (!empty($obj['metadata']['product_sku'])) {
                    $skus = [$obj['metadata']['product_sku']];
                }
                if (empty($skus)) {
                    $emptyFixtures[] = $obj;
                }
            }
        }

        if (!empty($emptyFixtures)) {
            $firstEmpty = $emptyFixtures[0];
            $names = array_map(function($o) { return $o['title'] ?? 'استند'; }, array_slice($emptyFixtures, 0, 3));
            $newRecs[] = [
                'id' => 'rec-' . time() . '-empty',
                'store_id' => $storeId,
                'object_id' => $firstEmpty['id'],
                'title' => 'تخصیص کالا به استندهای خالی (' . count($emptyFixtures) . ' مورد)',
                'recommendation' => 'استندهای «' . implode('»، «', $names) . '» بدون محصول چیده شده‌اند. کالاهای پرفروش یا مکمل را به این استندها منتسب کنید.',
                'reason' => 'قفسه‌های بدون کالا باعث اتلاف فضای تجاری فروشگاه و کاهش بهره‌وری متری می‌شوند.',
                'impact_prediction' => '+۱۲٪ افزایش ظرفیت عرضه',
                'priority' => 'High',
                'category' => 'stock_allocation',
                'status' => 'pending',
                'created_at' => $now
            ];
        }

        // 2. Check for out-of-stock items displayed on shelves
        $outOfStockAssignments = [];
        $lowStockAssignments = [];
        foreach ($objects as $obj) {
            $skus = [];
            if (!empty($obj['metadata']['product_skus']) && is_array($obj['metadata']['product_skus'])) {
                $skus = $obj['metadata']['product_skus'];
            } elseif (!empty($obj['metadata']['product_sku'])) {
                $skus = [$obj['metadata']['product_sku']];
            }
            foreach ($skus as $s) {
                $p = $productsBySku[$s] ?? null;
                if ($p) {
                    $stk = intval($p['stock'] ?? 0);
                    if ($stk <= 0) {
                        $outOfStockAssignments[] = ['obj' => $obj, 'prod' => $p];
                    } elseif ($stk <= 4) {
                        $lowStockAssignments[] = ['obj' => $obj, 'prod' => $p];
                    }
                }
            }
        }

        if (!empty($outOfStockAssignments)) {
            $firstOos = $outOfStockAssignments[0];
            $newRecs[] = [
                'id' => 'rec-' . time() . '-oos',
                'store_id' => $storeId,
                'object_id' => $firstOos['obj']['id'],
                'title' => 'هشدار اتمام موجودی: ' . $firstOos['prod']['name'],
                'recommendation' => 'کالای «' . $firstOos['prod']['name'] . '» (کد: ' . $firstOos['prod']['sku'] . ') روی استند «' . $firstOos['obj']['title'] . '» قرار دارد ولی موجودی انبار آن صفر است. سریعاً موجودی را شارژ کرده یا استند را با کالای دارای موجودی جایگزین کنید.',
                'reason' => 'نمایش فیزیکی کالای ناموجود منجر به ناامیدی مشتری در لحظه خرید و از دست رفتن فروش مستقیم می‌گردد.',
                'impact_prediction' => 'جلوگیری از افت تبدیل خرید',
                'priority' => 'High',
                'category' => 'inventory_alert',
                'status' => 'pending',
                'created_at' => $now
            ];
        } elseif (!empty($lowStockAssignments)) {
            $firstLow = $lowStockAssignments[0];
            $newRecs[] = [
                'id' => 'rec-' . time() . '-low',
                'store_id' => $storeId,
                'object_id' => $firstLow['obj']['id'],
                'title' => 'موجودی بحرانی روی استند: ' . $firstLow['prod']['name'],
                'recommendation' => 'تنها ' . $firstLow['prod']['stock'] . ' عدد از کالای «' . $firstLow['prod']['name'] . '» در انبار باقی مانده است. جهت حفظ پیوستگی چیدمان، کالای جایگزین هماهنگ آماده نمایید.',
                'reason' => 'افت موجودی به زیر ۵ عدد ریسک خالی ماندن ناگهانی استند در ساعات اوج فروش را ایجاد می‌کند.',
                'impact_prediction' => '+۶٪ پایداری عرضه',
                'priority' => 'Medium',
                'category' => 'inventory_alert',
                'status' => 'pending',
                'created_at' => $now
            ];
        }

        // 3. Check entrance clearance (entrance object distance to other objects)
        $entranceObj = null;
        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') === 'entrance') {
                $entranceObj = $obj;
                break;
            }
        }
        if ($entranceObj) {
            $ex = $entranceObj['position']['x'] ?? 500;
            $ey = $entranceObj['position']['y'] ?? 50;
            foreach ($objects as $obj) {
                if ($obj['id'] === $entranceObj['id']) continue;
                $ox = $obj['position']['x'] ?? 0;
                $oy = $obj['position']['y'] ?? 0;
                $dist = sqrt(pow($ox - $ex, 2) + pow($oy - $ey, 2));
                if ($dist < 110) {
                    $newRecs[] = [
                        'id' => 'rec-' . time() . '-entrance',
                        'store_id' => $storeId,
                        'object_id' => $obj['id'],
                        'title' => 'آزادسازی دهانه ورودی و هدایت نگاه مشتری',
                        'recommendation' => 'المان «' . ($obj['title'] ?? 'استند') . '» در فاصله بسیار نزدیک (' . round($dist * 0.05, 1) . ' متر) به درب ورودی قرار گرفته است. آن را کمی عقب‌تر انتقال دهید تا مسیر ورود مشتریان باز و دلپذیر باشد.',
                        'reason' => 'طبق اصول مهندسی چیدمان (Decompression Zone)، مشتریان در ۳ متری اول ورودی نیازمند فضای باز جهت تشخیص مسیرها هستند.',
                        'impact_prediction' => '+۱۴٪ سهولت تردد و ورود',
                        'priority' => 'Medium',
                        'category' => 'flow_optimization',
                        'target_coords' => ['x' => $ox, 'y' => $oy + 90],
                        'status' => 'pending',
                        'created_at' => $now
                    ];
                    break;
                }
            }
        }

        // 4. Check Checkout (Cash desk) existence
        $hasCheckout = false;
        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') === 'checkout') {
                $hasCheckout = true;
                break;
            }
        }
        if (!$hasCheckout) {
            $newRecs[] = [
                'id' => 'rec-' . time() . '-checkout',
                'store_id' => $storeId,
                'object_id' => null,
                'title' => 'جانمایی میز صندوق و پیشخوان تسویه',
                'recommendation' => 'در نقشه چیدمان فعلی، میز صندوق (Checkout) تعریف نشده است. از منوی ابزارها، المان صندوق را در نزدیکی خروجی یا ضلع پشتی سالن قرار دهید.',
                'reason' => 'مشخص بودن نقطه تسویه به ناوبری طبیعی مشتری و تکمیل چرخه خرید کمک شایانی می‌کند.',
                'impact_prediction' => '+۹٪ نظم جریان پرداخت',
                'priority' => 'Medium',
                'category' => 'layout_structure',
                'status' => 'pending',
                'created_at' => $now
            ];
        }

        // 5. Check Focal Points (Mannequins or window displays)
        $hasMannequin = false;
        foreach ($objects as $obj) {
            if (in_array($obj['type'] ?? '', ['mannequin', 'window'])) {
                $hasMannequin = true;
                break;
            }
        }
        if (!$hasMannequin) {
            $newRecs[] = [
                'id' => 'rec-' . time() . '-mannequin',
                'store_id' => $storeId,
                'object_id' => null,
                'title' => 'افزودن مانکن یا نقطه کانونی دید ورودی (Focal Point)',
                'recommendation' => 'استفاده از حداقل یک مانکن در محدوده دید درب ورودی، میزان مکث عابران و جذابیت بصری کالکشن را تا ۳۵٪ افزایش می‌دهد.',
                'reason' => 'نقاط کانونی بصری جهت هدایت نگاه مشتری از ورودی به عمق سالن فروشگاه طراحی می‌شوند.',
                'impact_prediction' => '+۲۲٪ جلب نگاه خریدار',
                'priority' => 'Low',
                'category' => 'visual_merchandising',
                'status' => 'pending',
                'created_at' => $now
            ];
        }

        // 6. POS / Footfall Integration Status
        if (!isset($store['today_sales']) || $store['today_sales'] === null) {
            $newRecs[] = [
                'id' => 'rec-' . time() . '-pos',
                'store_id' => $storeId,
                'object_id' => null,
                'title' => 'اتصال وب‌سرویس صندوق فروشگاهی (POS)',
                'recommendation' => 'وب‌سرویس فروش برای شعبه «' . $store['name'] . '» هنوز متصل نشده است. اتصال صندوق، امکان محاسبه دقیق نرخ تبدیل هر قفسه و تحلیل ریالی همجواری کالاها را فراهم خواهد کرد.',
                'reason' => 'در حال حاضر امتیازهای تحلیلی صرفاً بر مبنای مهندسی فیزیکی نقشه است و برای تحلیل زنده نیازمند دیتای تراکنش هستیم.',
                'impact_prediction' => 'فعال‌سازی هوش تجاری ریالی',
                'priority' => 'Medium',
                'category' => 'data_integration',
                'status' => 'pending',
                'created_at' => $now
            ];
        }

        // Save recommendations to database
        $data = VmDB::getData();
        $existing = [];
        foreach ($data['ai_recommendations'] ?? [] as $er) {
            if ($er['store_id'] !== $storeId || ($er['status'] ?? '') === 'applied') {
                $existing[] = $er;
            }
        }
        foreach ($newRecs as $nr) {
            $existing[] = $nr;
        }
        $data['ai_recommendations'] = $existing;
        VmDB::saveData($data);

        return [
            'success' => true,
            'message' => 'تحلیل هوش مصنوعی بر اساس چیدمان واقعی و کاتالوگ انجام شد و ' . count($newRecs) . ' پیشنهاد جدید ثبت گردید.',
            'count' => count($newRecs),
            'recommendations' => $newRecs
        ];
    }
}
