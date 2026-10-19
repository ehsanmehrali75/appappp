<?php
/**
 * Standalone Sales Analytics & Branch Performance Dashboard
 * Route: /sale
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ]);
    }
    session_start();
}

$functionsPath = __DIR__ . '/inc/functions.php';
if (!file_exists($functionsPath)) {
    die('خطای سیستم: فایل توابع یافت نشد.');
}
require_once $functionsPath;

// Enforce authentication
if (!isUser()) {
    redirect('index.php');
}

// Check and locked project association
if (!isSuperAdmin()) {
    $projectId = $_SESSION['project_id'] ?? null;
} else {
    $projectId = $_GET['project'] ?? ($_SESSION['project_id'] ?? null);
}

if (!$projectId) {
    $db = getDB();
    if (!empty($db['projects'])) {
        $projectId = $db['projects'][0]['id'];
    } else {
        die('پروژه‌ای برای این کاربر یافت نشد. با پشتیبانی هماهنگ کنید.');
    }
}

$project = getCurrentProject($projectId);
if (!$project) {
    die('پروژه مورد نظر یافت نشد یا شما دسترسی لازم را ندارید.');
}

if (!isModuleActive('sale')) {
    redirect("admin.php?msg=" . urlencode("بخش تحلیل مبالغ و سود شعب در دسته‌بندی و پکیج فعلی پروژه فعال نیست."));
    exit;
}

// Handle administrative mutation actions if user holds proper permissions
$isAdmin = isSuperAdmin() || isProjectAdmin();
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

if (!function_exists('normalize_jalali_date')) {
    function normalize_jalali_date($d) {
        $d = trim($d);
        if (empty($d)) return '';
        $d = str_replace('/', '-', $d);
        $parts = explode('-', $d);
        if (count($parts) === 3) {
            $y = intval($parts[0]);
            $m = intval($parts[1]);
            $day = intval($parts[2]);
            if ($y < 100) { $y += 1400; }
            return sprintf('%04d-%02d-%02d', $y, $m, $day);
        }
        return $d;
    }
}

if (!function_exists('get_jalali_day_of_week')) {
    function get_jalali_day_of_week($jalali_str) {
        $norm = normalize_jalali_date($jalali_str);
        $parts = explode('-', $norm);
        if (count($parts) === 3) {
            $g = jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]);
            $g_str = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
            $w = date('w', strtotime($g_str));
            $jalali_days = [
                0 => 'یکشنبه',
                1 => 'دوشنبه',
                2 => 'سه‌شنبه',
                3 => 'چهارشنبه',
                4 => 'پنج‌شنبه',
                5 => 'جمعه',
                6 => 'شنبه'
            ];
            return $jalali_days[$w] ?? '';
        }
        return '';
    }
}

if (!function_exists('get_jalali_date_diff_in_days')) {
    function get_jalali_date_diff_in_days($dateStart, $dateEnd) {
        $tsStart = jalaliToTimestamp($dateStart);
        $tsEnd = jalaliToTimestamp($dateEnd);
        $diffSecs = abs($tsEnd - $tsStart);
        return round($diffSecs / 86400) + 1;
    }
}

if (!function_exists('get_jalali_overlap_days')) {
    function get_jalali_overlap_days($sNew, $eNew, $sExist, $eExist) {
        $sOverlap = (strcmp($sNew, $sExist) >= 0) ? $sNew : $sExist;
        $eOverlap = (strcmp($eNew, $eExist) <= 0) ? $eNew : $eExist;
        
        if (strcmp($sOverlap, $eOverlap) <= 0) {
            $tsStart = jalaliToTimestamp($sOverlap);
            $tsEnd = jalaliToTimestamp($eOverlap);
            $diffSecs = $tsEnd - $tsStart;
            if ($diffSecs < 0) return 0;
            return round($diffSecs / 86400) + 1;
        }
        return 0;
    }
}

if (!function_exists('checkAndAdjustOverlapSales')) {
    function checkAndAdjustOverlapSales($existing_sales, &$pRow, &$errorMsg) {
        $sNew = normalize_jalali_date($pRow['date']);
        $eNew = !empty($pRow['date_end']) ? normalize_jalali_date($pRow['date_end']) : $sNew;
        
        // 1. Exact Duplicate check
        foreach ($existing_sales as $existRow) {
            $sExist = normalize_jalali_date($existRow['date']);
            $eExist = !empty($existRow['date_end']) ? normalize_jalali_date($existRow['date_end']) : $sExist;
            
            if ($sNew === $sExist && $eNew === $eExist) {
                $errorMsg = 'تکراری! این بازه زمانی (' . $sNew . (!empty($pRow['date_end']) ? ' تا ' . $eNew : '') . ') دقیقا قبلا ثبت شده است. برای اصلاح یا تغییر، لطفاً ردیف قبلی را حذف نمایید یا دیتای قبلی را در جای جدید ویرایش کنید.';
                return false;
            }
        }
        
        // 2. Overlap Adjustment
        $daysNew = get_jalali_date_diff_in_days($sNew, $eNew);
        if ($daysNew <= 0) $daysNew = 1;
        
        // Define all metrics that need adjustment if there is an overlap
        $numeric_metrics = ['sales_amount', 'sales_count', 'invoice_count', 'visits'];
        // Also adjust any other custom metrics that are added
        foreach ($pRow as $k => $v) {
            if ($k !== 'date' && $k !== 'date_end' && strpos($k, 'custom_') === 0) {
                $numeric_metrics[] = $k;
            }
        }
        $numeric_metrics = array_unique($numeric_metrics);
        
        foreach ($existing_sales as $existRow) {
            $sExist = normalize_jalali_date($existRow['date']);
            $eExist = !empty($existRow['date_end']) ? normalize_jalali_date($existRow['date_end']) : $sExist;
            
            $daysOverlap = get_jalali_overlap_days($sNew, $eNew, $sExist, $eExist);
            if ($daysOverlap > 0) {
                $daysExist = get_jalali_date_diff_in_days($sExist, $eExist);
                if ($daysExist <= 0) $daysExist = 1;
                
                foreach ($numeric_metrics as $col) {
                    if (isset($pRow[$col]) && is_numeric($pRow[$col]) && isset($existRow[$col]) && is_numeric($existRow[$col])) {
                        $existValue = floatval($existRow[$col]);
                        $existDailyAvg = $existValue / $daysExist;
                        
                        // Deduct overlap portion from new row value to get differential
                        $pRow[$col] = $pRow[$col] - ($daysOverlap * $existDailyAvg);
                    }
                }
            }
        }
        return true;
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Add Store Brand/Branch
    if (isset($_POST['add_store_action'])) {
        $sname = trim($_POST['store_name'] ?? '');
        $stype = $_POST['store_type'] ?? 'online';
        if ($sname) {
            $newStore = [
                'id' => 'store_' . uniqid(),
                'name' => $sname,
                'type' => $stype,
                'custom_metrics' => [],
                'targets' => [],
                'sales_data' => []
            ];
            if ($stype === 'physical') {
                $newStore['custom_metrics'][] = ['name' => 'تعداد مراجعه حضوری روزانه', 'key' => 'daily_visitors'];
            } else {
                $newStore['custom_metrics'][] = ['name' => 'تعداد بازدید سایت', 'key' => 'website_visits'];
            }
            if (!isset($project['settings']['stores']) || !is_array($project['settings']['stores'])) {
                $project['settings']['stores'] = [];
            }
            $project['settings']['stores'][] = $newStore;
            saveProject($project);
            redirect('sale.php?msg=' . urlencode('فروشگاه جدید با موفقیت ایجاد شد.') . '&store_id=' . $newStore['id'] . (isSuperAdmin() ? '&project=' . $projectId : ''));
        }
    }

    // 2. Delete Store Brand/Branch
    if (isset($_POST['delete_store_action'])) {
        $sid = $_POST['store_id'] ?? '';
        $filtered = [];
        if (isset($project['settings']['stores']) && is_array($project['settings']['stores'])) {
            foreach ($project['settings']['stores'] as $st) {
                if ($st['id'] !== $sid) {
                    $filtered[] = $st;
                }
            }
        }
        $project['settings']['stores'] = $filtered;
        saveProject($project);
        redirect('sale.php?msg=' . urlencode('فروشگاه با موفقیت حذف شد.') . (isSuperAdmin() ? '&project=' . $projectId : ''));
    }

    // 3. Save Store Config, Targets & Metrics
    if (isset($_POST['save_store_config_action']) || isset($_POST['delete_target_id'])) {
        $sid = $_POST['store_id'] ?? '';
        if (isset($project['settings']['stores']) && is_array($project['settings']['stores'])) {
            foreach ($project['settings']['stores'] as &$st) {
                if ($st['id'] === $sid) {
                    $st['name'] = trim($_POST['store_name'] ?? $st['name']);
                    $st['type'] = $_POST['store_type'] ?? $st['type'];
                    
                    // Parse personnel
                    $pRaw = trim($_POST['personnel_list'] ?? '');
                    $st['personnel'] = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $pRaw))));

                    // Update custom metrics
                    $st['custom_metrics'] = [];
                    if (isset($_POST['metric_names']) && is_array($_POST['metric_names'])) {
                        foreach ($_POST['metric_names'] as $mIdx => $mName) {
                            $mName = trim($mName);
                            if ($mName) {
                                $st['custom_metrics'][] = [
                                    'name' => $mName,
                                    'key' => trim($_POST['metric_keys'][$mIdx] ?? 'custom_' . $mIdx)
                                ];
                            }
                        }
                    }

                    // Add a new target
                    if (!empty($_POST['new_period_month']) && !empty($_POST['new_period_year'])) {
                        $periodNameCombined = trim($_POST['new_period_month'] . ' ' . $_POST['new_period_year']);
                        $st['targets'][] = [
                            'id' => 'target_' . uniqid(),
                            'period_name' => $periodNameCombined,
                            'target_money' => floatval($_POST['new_target_money'] ?? 0),
                            'target_count' => intval($_POST['new_target_count'] ?? 0)
                        ];
                    } else if (!empty($_POST['new_period_name'])) {
                        $st['targets'][] = [
                            'id' => 'target_' . uniqid(),
                            'period_name' => trim($_POST['new_period_name']),
                            'target_money' => floatval($_POST['new_target_money'] ?? 0),
                            'target_count' => intval($_POST['new_target_count'] ?? 0)
                        ];
                    }

                    // Update existing targets
                    if (isset($_POST['edit_target_ids']) && is_array($_POST['edit_target_ids'])) {
                        foreach ($st['targets'] as &$tItem) {
                            $tIdx = array_search($tItem['id'], $_POST['edit_target_ids']);
                            if ($tIdx !== false) {
                                $tItem['period_name'] = trim($_POST['edit_period_name'][$tIdx] ?? $tItem['period_name']);
                                $tItem['target_money'] = floatval($_POST['edit_target_money'][$tIdx] ?? 0);
                                $tItem['target_count'] = intval($_POST['edit_target_count'][$tIdx] ?? 0);
                            }
                        }
                        unset($tItem);
                    }

                    // Delete requested targets
                    if (isset($_POST['delete_target_id']) && $_POST['delete_target_id'] !== '') {
                        $dtId = $_POST['delete_target_id'];
                        $st['targets'] = array_filter($st['targets'], function($item) use ($dtId) {
                            return $item['id'] !== $dtId;
                        });
                    }
                    break;
                }
            }
            unset($st);
        }
        saveProject($project);
        redirect('sale.php?msg=' . urlencode('تنظیمات فروشگاه با موفقیت بروز شد.') . '&store_id=' . $sid . (isSuperAdmin() ? '&project=' . $projectId : ''));
    }

    // 4. Import TSV (Excel) / JSON Sales Data
    if (isset($_POST['import_sales_data_action'])) {
        $sid = $_POST['store_id'] ?? '';
        $importType = $_POST['import_type'] ?? 'excel';
        $rawContent = trim($_POST['import_content'] ?? '');
        $parsedRows = [];
        
        if ($rawContent) {
            if ($importType === 'json') {
                $jsonDecoded = json_decode($rawContent, true);
                if (is_array($jsonDecoded)) {
                    $parsedRows = $jsonDecoded;
                }
            } else {
                $lines = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $rawContent))));
                if (!empty($lines)) {
                    $headers = [];
                    $headerDetected = false;
                    
                    $firstRowCells = explode("\t", $lines[0]);
                    foreach ($firstRowCells as $cell) {
                        $cell = trim($cell);
                        if (mb_strlen($cell) > 0) {
                            if (preg_match('/(تاریخ|روز|فروش|تعداد|مبلغ|فاکتور|پرسنل|تراکنش|date|sales|invoice|personnel)/ui', $cell)) {
                                $headerDetected = true;
                                break;
                            }
                        }
                    }
                    
                    $startIdx = 0;
                    if ($headerDetected) {
                        $headers = array_map('trim', $firstRowCells);
                        $startIdx = 1;
                    }
                    
                    for ($i = $startIdx; $i < count($lines); $i++) {
                        $cells = explode("\t", $lines[$i]);
                        $cellCount = count($cells);
                        if ($cellCount == 0 || ($cellCount == 1 && empty($cells[0]))) continue;
                        
                        $row = [];
                        if ($headerDetected) {
                            foreach ($cells as $cIdx => $val) {
                                $hName = $headers[$cIdx] ?? '';
                                if (!$hName) continue;
                                
                                if (preg_match('/(تاریخ|date)/ui', $hName)) {
                                    $row['date'] = trim($val);
                                } elseif (preg_match('/(روز|day)/ui', $hName)) {
                                    $row['day_of_week'] = trim($val);
                                } elseif (preg_match('/(فروش|مبلغ|sales|amount)/ui', $hName)) {
                                    $row['sales_amount'] = floatval(preg_replace('/[^0-9.]/', '', clean_persian_number($val)));
                                } elseif (preg_match('/(تعداد فروش|تعداد|units|count|qty)/ui', $hName)) {
                                    $row['sales_count'] = intval(preg_replace('/[^0-9]/', '', clean_persian_number($val)));
                                } elseif (preg_match('/(فاکتور|تراکنش|invoices|invoice_count)/ui', $hName)) {
                                    $row['invoice_count'] = intval(preg_replace('/[^0-9]/', '', clean_persian_number($val)));
                                } elseif (preg_match('/(پرسنل|فروشنده|personnel)/ui', $hName)) {
                                    $row['personnel_name'] = trim($val);
                                } elseif (preg_match('/(برتر|نفر برتر|best seller|top seller)/ui', $hName)) {
                                    $row['top_seller_personnel'] = trim($val);
                                } elseif (preg_match('/(ست|ست برتر|best set|top set)/ui', $hName)) {
                                    $row['best_selling_set'] = trim($val);
                                } elseif (preg_match('/(محصول برتر|بست|best product)/ui', $hName)) {
                                    $row['best_product_of_week'] = trim($val);
                                } elseif (preg_match('/(پرفروش|پر فروش ترین|top product list)/ui', $hName)) {
                                    $row['top_selling_products'] = explode(",", trim($val));
                                } elseif (preg_match('/(سایز|سایز ও رنگ|size|color)/ui', $hName)) {
                                    $row['sizes_and_colors'] = trim($val);
                                } elseif (preg_match('/(بازدید|مراجعه|ترافیک|visits|visitors)/ui', $hName)) {
                                    $row['visits'] = intval(preg_replace('/[^0-9]/', '', clean_persian_number($val)));
                                }
                            }
                        } else {
                            $row['date'] = trim($cells[0] ?? '');
                            $row['day_of_week'] = trim($cells[1] ?? '');
                            $row['sales_amount'] = floatval(preg_replace('/[^0-9.]/', '', clean_persian_number($cells[2] ?? 0)));
                            $row['sales_count'] = intval(preg_replace('/[^0-9]/', '', clean_persian_number($cells[3] ?? 0)));
                            $row['invoice_count'] = intval(preg_replace('/[^0-9]/', '', clean_persian_number($cells[4] ?? 0)));
                            $row['personnel_name'] = trim($cells[5] ?? '');
                            $row['top_seller_personnel'] = trim($cells[6] ?? '');
                            $row['best_selling_set'] = trim($cells[7] ?? '');
                            $row['best_product_of_week'] = trim($cells[8] ?? '');
                            $row['top_selling_products'] = isset($cells[9]) ? array_map('trim', explode(',', $cells[9])) : [];
                            $row['sizes_and_colors'] = trim($cells[10] ?? '');
                            $row['visits'] = intval(preg_replace('/[^0-9]/', '', clean_persian_number($cells[11] ?? 0)));
                        }
                        
                        if (!empty($row['date'])) {
                            $parsedRows[] = $row;
                        }
                    }
                }
            }
        }
        
        if (!empty($parsedRows)) {
            foreach ($project['settings']['stores'] as &$st) {
                if ($st['id'] === $sid) {
                    if (!isset($st['sales_data']) || !is_array($st['sales_data'])) {
                        $st['sales_data'] = [];
                    }
                    
                    foreach ($parsedRows as $pRow) {
                        $found = false;
                        foreach ($st['sales_data'] as &$existRow) {
                            if ($existRow['date'] === $pRow['date']) {
                                $existRow = array_merge($existRow, $pRow);
                                $found = true;
                                break;
                            }
                        }
                        unset($existRow);
                        if (!$found) {
                            $st['sales_data'][] = $pRow;
                        }
                    }
                    
                    usort($st['sales_data'], function($a, $b) {
                        return strcmp($a['date'], $b['date']);
                    });
                    break;
                }
            }
            unset($st);
            saveProject($project);
            redirect('sale.php?msg=' . urlencode('آمار با موفقیت تجمیع و وارد گردید.') . '&store_id=' . $sid . (isSuperAdmin() ? '&project=' . $projectId : ''));
        }
    }

    // 4.5 Add Single Day Sale Row (Manual Input)
    if (isset($_POST['add_single_day_sale_action'])) {
        $sid = $_POST['store_id'] ?? '';
        $date_start_input = trim($_POST['date'] ?? '');
        $date_end_input = trim($_POST['date_end'] ?? '');

        if (empty($date_start_input)) {
            redirect('sale.php?error=' . urlencode('پر کردن تاریخ شروع الزامی است.') . '&store_id=' . $sid . (isSuperAdmin() ? '&project=' . $projectId : ''));
            exit;
        }

        $norm_start = normalize_jalali_date($date_start_input);
        $norm_end = !empty($date_end_input) ? normalize_jalali_date($date_end_input) : '';

        // Validate range order if end date is set
        if (!empty($norm_end) && strcmp($norm_start, $norm_end) > 0) {
            redirect('sale.php?error=' . urlencode('تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.') . '&store_id=' . $sid . (isSuperAdmin() ? '&project=' . $projectId : ''));
            exit;
        }

        $sNew = $norm_start;
        $eNew = !empty($norm_end) ? $norm_end : $sNew;

        // Prepare list of existing sales for duplicate & overlap checks
        $existing_sales = [];
        if (isset($project['settings']['stores']) && is_array($project['settings']['stores'])) {
            foreach ($project['settings']['stores'] as $st) {
                if ($st['id'] === $sid) {
                    $existing_sales = $st['sales_data'] ?? [];
                    break;
                }
            }
        }

        // Determine Day of Week dynamically if not supplied
        $day_of_week = trim($_POST['day_of_week'] ?? '');
        if (!empty($norm_end)) {
            $day_of_week = ''; // No single day of week for range
        } else if (empty($day_of_week) && !empty($norm_start)) {
            $day_of_week = get_jalali_day_of_week($norm_start);
        }

        // Parse fields while allowing them to be left empty/blank
        $raw_amount = clean_persian_number(trim($_POST['sales_amount'] ?? ''));
        $sales_amount = ($raw_amount !== '') ? floatval(preg_replace('/[^0-9.]/', '', $raw_amount)) : '';

        $raw_count = clean_persian_number(trim($_POST['sales_count'] ?? ''));
        $sales_count = ($raw_count !== '') ? intval($raw_count) : '';

        $raw_invoice = clean_persian_number(trim($_POST['invoice_count'] ?? ''));
        $invoice_count = ($raw_invoice !== '') ? intval($raw_invoice) : '';

        $raw_visits = clean_persian_number(trim($_POST['visits'] ?? ''));
        $visits = ($raw_visits !== '') ? intval($raw_visits) : '';

        $pRow = [
            'date' => $norm_start,
            'date_end' => $norm_end,
            'day_of_week' => $day_of_week,
            'sales_amount' => $sales_amount,
            'sales_count' => $sales_count,
            'invoice_count' => $invoice_count,
            'personnel_name' => trim($_POST['personnel_name'] ?? ''),
            'best_selling_set' => trim($_POST['best_selling_set'] ?? ''),
            'best_product_of_week' => trim($_POST['best_product_of_week'] ?? ''),
            'sizes_and_colors' => trim($_POST['sizes_and_colors'] ?? ''),
            'visits' => $visits,
            'linked_campaign_promo' => trim($_POST['linked_campaign_promo'] ?? ''),
        ];
        
        // Handle custom metric inputs dynamic parsing
        if (isset($project['settings']['stores']) && is_array($project['settings']['stores'])) {
            foreach ($project['settings']['stores'] as $st) {
                if ($st['id'] === $sid) {
                    $custom_metrics = $st['custom_metrics'] ?? [];
                    break;
                }
            }
            foreach ($custom_metrics as $cm) {
                $cmKey = $cm['key'];
                $raw_cm = trim($_POST[$cmKey] ?? '');
                $pRow[$cmKey] = ($raw_cm !== '') ? floatval($raw_cm) : '';
            }
        }
        
        $errorMsg = '';
        if (!checkAndAdjustOverlapSales($existing_sales, $pRow, $errorMsg)) {
            redirect('sale.php?error=' . urlencode($errorMsg) . '&store_id=' . $sid . (isSuperAdmin() ? '&project=' . $projectId : ''));
            exit;
        }
        
        if ($pRow['date'] && isset($project['settings']['stores']) && is_array($project['settings']['stores'])) {
            foreach ($project['settings']['stores'] as &$st) {
                if ($st['id'] === $sid) {
                    if (!isset($st['sales_data']) || !is_array($st['sales_data'])) {
                        $st['sales_data'] = [];
                    }

                    $found = false;
                    foreach ($st['sales_data'] as &$existRow) {
                        $sExist = normalize_jalali_date($existRow['date']);
                        $eExist = !empty($existRow['date_end']) ? normalize_jalali_date($existRow['date_end']) : $sExist;
                        if ($sNew === $sExist && $eNew === $eExist) {
                            $existRow = array_merge($existRow, $pRow);
                            $found = true;
                            break;
                        }
                    }
                    unset($existRow);

                    if (!$found) {
                        $st['sales_data'][] = $pRow;
                    }
                    
                    usort($st['sales_data'], function($a, $b) {
                        return strcmp($a['date'], $b['date']);
                    });
                    break;
                }
            }
            unset($st);

            // Closed-loop attribution for linked promo campaigns on manual entry
            $campaign_promo = trim($_POST['linked_campaign_promo'] ?? '');
            if (!empty($campaign_promo) && isset($project['settings']['campaigns']) && is_array($project['settings']['campaigns'])) {
                foreach ($project['settings']['campaigns'] as &$camp) {
                    $camp_promo = strtoupper(trim($camp['promo_code'] ?? ''));
                    if ($camp_promo !== '' && $camp_promo === strtoupper($campaign_promo)) {
                        if (!isset($camp['perf_revenue'])) $camp['perf_revenue'] = 0;
                        if (!isset($camp['perf_orders'])) $camp['perf_orders'] = 0;
                        if (!isset($camp['perf_clicks'])) $camp['perf_clicks'] = 0;

                        $camp['perf_revenue'] += floatval($sales_amount);
                        $camp['perf_orders'] += intval($sales_count);
                        $camp['perf_clicks'] += intval($visits);
                        break;
                    }
                }
                unset($camp);
            }

            saveProject($project);
            $msgStr = 'آمار بازه ' . $pRow['date'] . (!empty($pRow['date_end']) ? ' تا ' . $pRow['date_end'] : '') . ' با موفقیت ثبت/ویرایش شد.';
            redirect('sale.php?msg=' . urlencode($msgStr) . '&store_id=' . $sid . (isSuperAdmin() ? '&project=' . $projectId : ''));
        }
    }

    // 5. Delete specific day/date records
    if (isset($_POST['delete_day_action'])) {
        $sid = $_POST['store_id'] ?? '';
        $dayDate = $_POST['day_date'] ?? '';
        if (isset($project['settings']['stores']) && is_array($project['settings']['stores'])) {
            foreach ($project['settings']['stores'] as &$st) {
                if ($st['id'] === $sid) {
                    $st['sales_data'] = array_filter($st['sales_data'], function($item) use ($dayDate) {
                        return $item['date'] !== $dayDate;
                    });
                    break;
                }
            }
            unset($st);
        }
        saveProject($project);
        redirect('sale.php?msg=' . urlencode('آمار روز ' . $dayDate . ' حذف شد.') . '&store_id=' . $sid . (isSuperAdmin() ? '&project=' . $projectId : ''));
    }
}

// Prepare store dataset references
$stores = $project['settings']['stores'] ?? [];
$selected_store_id = $_GET['store_id'] ?? $_SESSION['selected_store_id'] ?? ($stores[0]['id'] ?? '');

$current_store = null;
foreach ($stores as $st) {
    if ($st['id'] === $selected_store_id) {
        $current_store = $st;
        break;
    }
}
if (!$current_store && !empty($stores)) {
    $current_store = $stores[0];
    $selected_store_id = $current_store['id'];
}
$_SESSION['selected_store_id'] = $selected_store_id;

$sales_data = $current_store['sales_data'] ?? [];

// Handle AJAX Request for Sales AI Analysis using Gemini
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_sales_ai_analysis') {
    header('Content-Type: application/json');
    
    // Calculate stats to pass to AI
    $total_amt = 0; $total_qty = 0; $total_inv = 0; $total_v = 0; $rows_count = count($sales_data);
    foreach ($sales_data as $row) {
        $total_amt += floatval($row['sales_amount'] ?? 0);
        $total_qty += intval($row['sales_count'] ?? 0);
        $total_inv += intval($row['invoice_count'] ?? 0);
        $total_v += intval($row['visits'] ?? 0);
    }
    
    $avg_basket = ($total_inv > 0) ? round($total_amt / $total_inv) : 0;
    $conv_rate = ($total_v > 0) ? round(($total_inv / $total_v) * 100, 1) : 0;
    
    $storeName = $current_store['name'] ?? 'نامشخص';
    $storeType = ($current_store['type'] ?? 'online') === 'physical' ? 'حضوری / شعبه فیزیکی' : 'آنلاین شاپ / وب‌سایت';
    
    // Active targets
    $target_str = "تعریف نشده";
    if (!empty($current_store['targets'])) {
        $last_target = end($current_store['targets']);
        $target_str = "هدف مبلّغ تومانی: " . number_format($last_target['amount'] ?? 0) . " - هدف تعداد فاکتور: " . number_format($last_target['invoice_count'] ?? 0);
    }
    
    $prompt = "به عنوان یک مشاور ارشد توسعه کسب‌وکار و تحلیل‌گر ارشد مالی در حوزه پوشاک و خرده‌فروشی خلاقانه، اطلاعات عملکرد شعبه زیر را تحلیل عمقی کنید:
    به طور کلی و خلاصه ارقام زیر بررسی شود:
    - نام شعبه: {$storeName}
    - نوع کانال: {$storeType}
    - مجموع کل مبلغ فروش ثبت شده: " . number_format($total_amt) . " تومان
    - مجموع تعداد اقلام به فروش رفته: " . number_format($total_qty) . " عدد
    - تعداد کل فاکتورها (تراکنش‌ها): " . number_format($total_inv) . " عدد
    - مجموع دفعات بازدید / مراجعات حضوری: " . number_format($total_v) . " بار
    - تعداد بازه‌های روزانه فروش ثبت شده: {$rows_count} روز
    - میانگین سبد خرید فاکتور: " . number_format($avg_basket) . " تومان
    - نرخ تبدیل بازدیدکننده به خریدار: {$conv_rate}%
    - هدف فعال ثبت شده: {$target_str}
    
    لطفاً یک گزارش تخصصی، انگیزشی و استراتژیک به زبان فارسی فاخر و روان بنویسید شامل:
    ۱. **ارزیابی عملکرد کلی**: بررسی وضعیت فروش و سبد خرید نسبت به تعداد فاکتورها.
    ۲. **تحلیل ترافیک و نرخ تبدیل**: تحلیل مناسب بودن نرخ تبدیل بهبودیافته با ترافیک ورودی شعبه.
    ۳. **پیشنهادات عملیاتی و بازاریابی برای رشد فروش**: ارائه حداقل ۳ راهکار خلاقانه، عملی و کاربردی (مثلاً تکنیک‌های چیدمان، تبلیغات پیامکی هدفمند، کمپین‌های مناسبتی، هم‌افزایی با وب‌سایت یا افزایش میانگین سبد با باندلینگ).
    طوری پاسخ دهید که برای مدیران برند پوشاک بسیار جذاب و کارآمد باشد و با کلمات حرفه‌ای نظیر 'هم‌افزایی'، 'نرخ خروج'، 'ارزش طول عمر مشتری'، و 'سبد خرید بهینه' غنی شده باشد.";
    
    $apiKey = $project['settings']['gemini_api_key'] ?? $project['settings']['connections']['gemini_api_key'] ?? '';
    $res = geminiGenerateResponse($prompt, "شما مشاور استراتژیک ارشد رشد و اقتصاد خرده‌فروشی هستید. با تحلیل دقیق داده‌ها، خروجی فارسی غنی و خوانا با ساختار مارک‌داون تمیز ارائه کنید.", $apiKey);
    
    if (isset($res['error'])) {
        echo json_encode(['success' => false, 'message' => $res['error']]);
    } else {
        echo json_encode(['success' => true, 'response' => $res['text']]);
    }
    exit;
}

// General statistics
$total_sales_money = 0;
$total_sales_count = 0;
$total_invoice_count = 0;
$total_visits_count = 0;
foreach ($sales_data as $row) {
    if (isset($row['sales_amount']) && $row['sales_amount'] !== '') {
        $total_sales_money += floatval($row['sales_amount']);
    }
    if (isset($row['sales_count']) && $row['sales_count'] !== '') {
        $total_sales_count += intval($row['sales_count']);
    }
    if (isset($row['invoice_count']) && $row['invoice_count'] !== '') {
        $total_invoice_count += intval($row['invoice_count']);
    }
    if (isset($row['visits']) && $row['visits'] !== '') {
        $total_visits_count += intval($row['visits']);
    }
}

// Current active targets
$targets = $current_store['targets'] ?? [];
$active_target = !empty($targets) ? end($targets) : null;
$personnel_list = $current_store['personnel'] ?? [];
$custom_metrics = $current_store['custom_metrics'] ?? [];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحلیل فروش و شعب | <?php echo htmlspecialchars($project['name']); ?></title>
    <link rel="stylesheet" href="<?php echo getFullUrl('assets/style.css'); ?>">
    <script src="<?php echo getFullUrl('assets/tailwindcss.js'); ?>"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Vazirmatn', 'Tahoma', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            200: '#ddd6fe',
                            300: '#c4b5fd',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            800: '#5b21b6',
                            900: '#4c1d95',
                        }
                    },
                    boxShadow: {
                        'premium': '0 20px 40px -15px rgba(0,0,0,0.05)',
                        'elevated': '0 25px 50px -12px rgba(0,0,0,0.08)',
                    }
                }
            }
        }
    </script>
    <style>
        @font-face {
            font-family: 'Vazirmatn';
            src: url('<?php echo getFullUrl('assets/fonts/Vazirmatn-Regular.woff2'); ?>') format('woff2');
            font-weight: normal;
            font-style: normal;
        }

        @font-face {
            font-family: 'Vazirmatn';
            src: url('<?php echo getFullUrl('assets/fonts/Vazirmatn-Bold.woff2'); ?>') format('woff2');
            font-weight: bold;
            font-style: normal;
        }

        body {
            font-family: 'Vazirmatn', 'Tahoma', sans-serif !important;
            background-color: #f8fafc;
            color: #0f172a;
        }
    </style>
    <!-- Jalali Datepicker (Zero dependency, high compatibility) -->
    <link rel="stylesheet" href="https://unpkg.com/@majidh1/jalali-datepicker/dist/jalali-datepicker.min.css">
    <script src="https://unpkg.com/@majidh1/jalali-datepicker/dist/jalali-datepicker.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            if (typeof jalaliDatepicker !== 'undefined') {
                jalaliDatepicker.startWatch({
                    minDate: "attr",
                    maxDate: "attr",
                    minDateOnToday: false,
                    showTodayBtn: true,
                    showEmptyBtn: true,
                    separator: "/"
                });
            }
        });
    </script>
    <style>
        /* Custom styled Jalali Datepicker popup for absolute aesthetic alignment */
        jdp-container {
            font-family: 'Vazirmatn', Tahoma, sans-serif !important;
            border-radius: 1.25rem !important;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1) !important;
            border: 1px solid #e2e8f0 !important;
            padding: 0.5rem !important;
            background-color: #ffffff !important;
            z-index: 999999 !important;
        }
        jdp-container .jdp-btn {
            border-radius: 0.5rem !important;
            font-size: 0.75rem !important;
            font-weight: 800 !important;
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">
<?php require_once __DIR__.'/inc/ai/HubSummary.php';maarkshoHubSummary($project,'sales'); ?>
<div style="padding:16px;background:#e5eee8;text-align:center"><a href="/panel/data-hub.php?project=<?php echo rawurlencode($project['id']); ?>&kind=sales">فروش تجمیعی ووکامرس، CRM، اکسل و JSON با تفکیک منبع، فروشگاه و تاریخ ←</a></div>

<!-- Top Navigation Header -->
<header class="sticky top-0 z-40 bg-white/80 backdrop-blur-xl border-b border-slate-100 px-6 py-4 shadow-sm">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-4">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-2xl bg-indigo-600 text-white flex items-center justify-center text-2xl font-black shadow-md shadow-indigo-600/20">💰</div>
            <div>
                <h1 class="font-extrabold text-lg text-slate-800 leading-tight"><?php echo htmlspecialchars($project['name']); ?></h1>
                <p class="text-[10px] text-indigo-600 font-extrabold tracking-widest uppercase">Maarksho Sale dashboard</p>
            </div>
        </div>
        
        <?php if (isSuperAdmin()): ?>
            <!-- Project Selector for general manager/owner -->
            <?php
            $allPrjs = getDB()['projects'] ?? [];
            if (count($allPrjs) > 1):
            ?>
            <div class="flex items-center gap-2 bg-slate-100 p-1.5 rounded-xl border border-slate-200">
                <span class="text-[10px] font-black mr-2 text-slate-500">📁 پروژه فعال:</span>
                <select onchange="window.location.href='sale.php?project=' + this.value" class="px-2.5 py-1 bg-white text-xs font-black rounded-lg outline-none border border-slate-200 focus:border-brand-500">
                    <?php foreach ($allPrjs as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $p['id'] === $projectId ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="flex items-center gap-3">
            <div class="hidden md:flex items-center gap-2 px-3.5 py-2 bg-slate-100 border border-slate-200/60 rounded-xl text-[11px] font-black text-slate-600">
                <span class="text-xs">👤</span>
                <span><?php echo htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['user']['username'] ?? 'کاربر سیستم'); ?></span>
            </div>

            <!-- Back to User Catalog Portal button -->
            <a href="portal.php<?php echo isSuperAdmin() ? '?project='.$projectId : ''; ?>" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all flex items-center gap-1.5">
                <span>◀️</span>
                <span>بازگشت به پورتال اصلی</span>
            </a>
            
            <a href="logout.php" class="px-3.5 py-2.5 bg-rose-50 hover:bg-rose-100 border border-rose-100 text-rose-500 rounded-xl transition-all" title="خروج">🚪</a>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-8">

    <?php if ($msg): ?>
        <div class="p-4 mb-6 bg-emerald-50 text-emerald-800 border border-emerald-100 rounded-2xl text-xs font-black flex items-center gap-3">
            <span>✅</span>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="p-4 mb-6 bg-rose-50 text-rose-800 border border-rose-100 rounded-2xl text-xs font-black flex items-center gap-3">
            <span>❌</span>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Top Analytical Intro Card -->
    <div class="premium-card bg-gradient-to-br from-slate-900 via-indigo-950 to-indigo-900 text-white rounded-[2.5rem] p-8 shadow-xl relative overflow-hidden mb-8">
        <span class="absolute top-0 right-1/4 w-96 h-96 bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></span>
        <span class="absolute bottom-0 left-10 w-64 h-64 bg-emerald-500/10 rounded-full blur-2xl pointer-events-none"></span>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
                <span class="text-[10px] bg-indigo-500/30 text-indigo-200 border border-indigo-400/20 px-3.5 py-1.5 rounded-full font-black uppercase tracking-wider mb-3.5 inline-block">سامانه تحلیل و بررسی مالی Maarksho Flow</span>
                <h2 class="text-3xl font-black tracking-tight mb-2">رصد روند و تحلیل هوشمند داده‌های شعب</h2>
                <p class="text-slate-300 text-xs font-semibold leading-relaxed max-w-xl">
                    گزارش مبالغ تراکنش، حجم‌های فیزیکی اقلام فروخته شده، اهداف ماهیانه، و بررسی فاکتورها بر اساس شعب اینترنتی و حضوری گالری.
                </p>
            </div>
            
            <!-- Store selector -->
            <div class="bg-white/10 backdrop-blur-md p-4 border border-white/10 rounded-3xl flex flex-col gap-2 min-w-[280px]">
                <label class="text-[10px] font-black text-indigo-200 block">🏪 انتخاب شعبه / کانال فروش فعال</label>
                <div class="flex gap-2">
                    <select onchange="window.location.href='sale.php?store_id=' + this.value + '<?php echo isSuperAdmin() ? '&project='.$projectId : ''; ?>'" class="flex-1 px-3 py-2 bg-slate-900 border border-white/20 text-white rounded-xl text-xs font-black outline-none focus:border-brand-400">
                        <?php if (empty($stores)): ?>
                            <option value="">شعبه‌ای تعریف نشده</option>
                        <?php endif; ?>
                        <?php foreach ($stores as $st): ?>
                            <option value="<?php echo $st['id']; ?>" <?php echo $st['id'] === $selected_store_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($st['name']); ?> (<?php echo $st['type'] === 'physical' ? 'حضوری' : 'آنلاین شاپ'; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <?php if ($isAdmin): ?>
                        <button onclick="document.getElementById('addStoreModal').style.display='flex'" class="p-2.5 bg-brand-500 hover:bg-brand-600 text-white rounded-xl text-xs font-black shadow-lg transition-all" title="تعریف فروشگاه جدید">➕</button>
                        <?php if ($current_store): ?>
                            <button onclick="document.getElementById('configureStoreModal').style.display='flex'" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-black transition-all" title="تنظیمات فروشگاه">⚙️</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($stores)): ?>
        <div class="p-8 bg-amber-50 border border-amber-250 text-amber-950 rounded-[2.25rem] flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-start gap-4">
                <span class="text-4xl">⚠️</span>
                <div>
                    <h4 class="font-black text-sm text-amber-950 mb-1">هیچ کانال فروشگاهی یا شعبه‌ای برای این پروژه ایجاد نشده است!</h4>
                    <p class="text-xs font-semibold text-amber-800 leading-relaxed">برای آغاز ثبت داده‌های فاکتور، نیاز دارید تا ابتدا یک شعبه یا کانال تعریف کنید.</p>
                </div>
            </div>
            <?php if ($isAdmin): ?>
                <button onclick="document.getElementById('addStoreModal').style.display='flex'" class="px-5 py-3.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-black transition-all shadow-md">تعریف اولین شعبه</button>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <!-- Overall numerical widgets -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-36 rounded-3xl">
                <div class="flex items-center justify-between">
                    <span class="bg-indigo-50 text-indigo-700 text-[10px] font-black px-2.5 py-1 rounded-full">کل ترن‌اور (خالص درآمد مالی)</span>
                    <span class="text-xl">💰</span>
                </div>
                <div>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tight"><?php echo number_format($total_sales_money); ?> <span class="text-xs text-slate-400 font-bold">تومان</span></h3>
                    <p class="text-[9px] font-black text-slate-400 mt-1.5">مجموع فاکتورهای ثبت شده دوره</p>
                </div>
            </div>

            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-36 rounded-3xl">
                <div class="flex items-center justify-between">
                    <span class="bg-emerald-50 text-emerald-700 text-[10px] font-black px-2.5 py-1 rounded-full">حجم فروش فیزیکی (پوشاک)</span>
                    <span class="text-xl">👕</span>
                </div>
                <div>
                    <h3 class="text-3xl font-black text-emerald-700 tracking-tight"><?php echo number_format($total_sales_count); ?> <span class="text-xs text-emerald-400 font-bold">قلم</span></h3>
                    <p class="text-[9px] font-black text-slate-400 mt-1.5">کل اقلام تحویل داده شده</p>
                </div>
            </div>

            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-36 rounded-3xl">
                <div class="flex items-center justify-between">
                    <span class="bg-sky-50 text-sky-700 text-[10px] font-black px-2.5 py-1 rounded-full">تعداد کل فاکتورها</span>
                    <span class="text-xl">📝</span>
                </div>
                <div>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tight"><?php echo number_format($total_invoice_count); ?> <span class="text-xs text-slate-400 font-bold">عدد</span></h3>
                    <p class="text-[9px] font-black text-slate-400 mt-1.5">تعداد کل تراکنش های موفق فروشگاه</p>
                </div>
            </div>

            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-36 rounded-3xl">
                <div class="flex items-center justify-between">
                    <?php if (($current_store['type'] ?? 'online') === 'online'): ?>
                        <span class="bg-indigo-50 text-indigo-700 text-[10px] font-black px-2.5 py-1 rounded-full">ترافیک وب‌سایت و تبدیل</span>
                        <span class="text-xl">🌐</span>
                    <?php else: ?>
                        <span class="bg-teal-50 text-teal-700 text-[10px] font-black px-2.5 py-1 rounded-full">پاخور پاساژ / تبدیل حضوری</span>
                        <span class="text-xl">👣</span>
                    <?php endif; ?>
                </div>
                <div>
                    <?php
                    $conv_rate = $total_visits_count > 0 ? round(($total_invoice_count / $total_visits_count) * 100, 2) : 0;
                    ?>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tight">
                        <?php echo number_format($total_visits_count); ?> 
                        <span class="text-xs text-slate-450 font-bold"><?php echo ($current_store['type'] ?? 'online') === 'online' ? 'بازدید' : 'مراجع'; ?></span>
                        <span class="text-sm text-brand-600 font-black mr-2">(<?php echo $conv_rate; ?>%)</span>
                    </h3>
                    <p class="text-[9px] font-black text-slate-400 mt-1.5">نسبت فاکتور صادر شده به کل مراجعین</p>
                </div>
            </div>
        </div>

        <!-- AI Strategic Analysis Agent Section -->
        <div class="premium-card p-6 bg-gradient-to-r from-violet-50/70 to-indigo-50/70 border border-violet-100 shadow-sm rounded-3xl mb-8">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-start gap-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-brand-500 to-indigo-600 text-white flex items-center justify-center shadow-md shadow-brand-500/20 text-xl font-bold shrink-0">✨</div>
                    <div>
                        <h4 class="font-black text-sm text-slate-850">تحلیـل‌گر هوشمند شعبه Maarksho Flow AI</h4>
                        <p class="text-[10px] text-slate-550 font-bold mt-1 leading-relaxed">مشاوره اختصاصی رشد ترافیک، افزایش ارزش فاکتور، بهبود نرخ تبدیل و استراتژی فروش اختصاصی شعبه شما با استفاده از موتور هوش مصنوعی Gemini-3.5-Flash</p>
                    </div>
                </div>
                <div class="shrink-0">
                    <button id="btn-sales-ai" onclick="runSalesAnalyticsAI()" class="px-5 py-3 bg-indigo-650 hover:bg-indigo-700 text-white text-[11px] font-black rounded-xl shadow-lg shadow-indigo-600/10 transition-all flex items-center gap-1.5 duration-200">
                        <span id="ai-sales-spinner" class="hidden animate-spin">⏳</span>
                        <span>📊</span> دریافت مشاوره استراتژیک هوشمند
                    </button>
                </div>
            </div>
            
            <div id="ai-sales-report-container" class="hidden mt-5 pt-5 border-t border-slate-200/60 space-y-3">
                <div class="flex items-center gap-2 text-indigo-700 text-xs font-black">
                    <span class="animate-pulse text-lg">📊</span> گزارش تحلیل استراتژیک و سناریوی رشد فروش:
                </div>
                <div id="ai-sales-report-content" class="text-xs text-slate-700 leading-relaxed font-semibold bg-white p-5 border border-indigo-100/60 rounded-2xl shadow-sm whitespace-pre-wrap text-right" dir="rtl">
                </div>
                <div class="flex justify-end">
                    <button onclick="copySalesAiReport()" class="px-4 py-2 bg-white hover:bg-slate-50 text-indigo-700 border border-indigo-200 text-[10px] font-black rounded-xl transition-all">📋 نسخه‌برداری از گزاره‌های گزارش</button>
                </div>
            </div>
        </div>

        <!-- Targets & Milestone goals -->
        <h3 class="font-black text-sm text-slate-500 mb-3 tracking-wider">🎯 پیشرفت ماهیانه اهداف کلیدی فروش</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm space-y-4 rounded-3xl">
                <div class="flex justify-between items-center">
                    <div>
                        <h4 class="font-black text-sm text-slate-800">تارگت و هدف مالی دوره</h4>
                        <p class="text-[9px] text-slate-400 font-bold mt-1">پیشرفت هدف‌گذاری عددی درآمد</p>
                    </div>
                    <span class="text-xs bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full font-black"><?php echo htmlspecialchars($active_target['period_name'] ?? 'تعریف نشده'); ?></span>
                </div>

                <?php
                $targetMoney = $active_target['target_money'] ?? 1;
                $percentMoney = min(100, round(($total_sales_money / ($targetMoney ?: 1)) * 100));
                ?>
                <div class="flex items-end justify-between font-bold text-xs mt-3">
                    <span class="text-slate-500">جذب شده: <?php echo number_format($total_sales_money); ?> ت</span>
                    <span class="text-indigo-700 font-black">تارگت: <?php echo number_format($targetMoney); ?> ت</span>
                </div>

                <div class="w-full bg-slate-100 rounded-full h-3.5 overflow-hidden">
                    <div class="bg-indigo-600 h-full rounded-full transition-all duration-1000" style="width: <?php echo $percentMoney; ?>%;"></div>
                </div>

                <div class="flex justify-between items-center text-[11px]">
                    <span class="text-slate-400 font-bold">ضریب تحقق مالی</span>
                    <span class="text-xs font-black text-indigo-600"><?php echo $percentMoney; ?>% حاصل شده</span>
                </div>
            </div>

            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm space-y-4 rounded-3xl">
                <div class="flex justify-between items-center">
                    <div>
                        <h4 class="font-black text-sm text-slate-800">تارگت فیزیکی تعداد اقلام</h4>
                        <p class="text-[9px] text-slate-400 font-bold mt-1">حجم خالص پوشاک فروخته شده</p>
                    </div>
                    <span class="text-xs bg-emerald-50 text-emerald-700 px-3 py-1 rounded-full font-black"><?php echo htmlspecialchars($active_target['period_name'] ?? 'تعریف نشده'); ?></span>
                </div>

                <?php
                $targetCount = $active_target['target_count'] ?? 1;
                $percentCount = min(100, round(($total_sales_count / ($targetCount ?: 1)) * 100));
                ?>
                <div class="flex items-end justify-between font-bold text-xs mt-3">
                    <span class="text-slate-500">اقلام رفته: <?php echo number_format($total_sales_count); ?> قلم</span>
                    <span class="text-emerald-700 font-black">تارگت: <?php echo number_format($targetCount); ?> قلم</span>
                </div>

                <div class="w-full bg-slate-100 rounded-full h-3.5 overflow-hidden">
                    <div class="bg-emerald-500 h-full rounded-full transition-all duration-1000" style="width: <?php echo $percentCount; ?>%;"></div>
                </div>

                <div class="flex justify-between items-center text-[11px]">
                    <span class="text-slate-400 font-bold">تحقق ظرفیت سفارش کالا</span>
                    <span class="text-xs font-black text-emerald-600"><?php echo $percentCount; ?>% پوشش داده شده</span>
                </div>
            </div>
        </div>

        <!-- Bento parameters statistics -->
        <h3 class="font-black text-sm text-slate-500 mb-3 tracking-wider">🌟 شاخص‌های درخشان و برتر هفته</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- 1. Top seller personnel -->
            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-40 rounded-3xl">
                <div>
                    <span class="text-[9px] bg-indigo-50 text-indigo-700 px-2.5 py-1 rounded-full font-black">پرسنل برتر ماه</span>
                    <h4 class="font-black text-[11px] text-slate-400 mt-3.5">بیشترین مشارکت در نهایی‌سازی</h4>
                </div>
                <?php
                $pCounts = [];
                foreach ($sales_data as $row) {
                    if (!empty($row['personnel_name'])) {
                        $pCounts[$row['personnel_name']] = ($pCounts[$row['personnel_name']] ?? 0) + floatval($row['sales_amount'] ?? 0);
                    }
                }
                arsort($pCounts);
                $topPers = !empty($pCounts) ? key($pCounts) : 'ثبت نشده';
                $topPersVal = !empty($pCounts) ? current($pCounts) : 0;
                ?>
                <div>
                    <h4 class="text-lg font-black text-slate-800 leading-tight"><?php echo htmlspecialchars($topPers); ?></h4>
                    <p class="text-[10px] text-slate-400 font-bold mt-1">تراکنش ثبت‌شده: <?php echo number_format($topPersVal); ?> تومان</p>
                </div>
            </div>

            <!-- 2. Top Couple Set style -->
            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-40 rounded-3xl">
                <div>
                    <span class="text-[9px] bg-rose-50 text-rose-700 px-2.5 py-1 rounded-full font-black">ست زوجی / برتر لباس</span>
                    <h4 class="font-black text-[11px] text-slate-400 mt-3.5">بیشترین همبستگی خرید همزمان</h4>
                </div>
                <?php
                $setCounts = [];
                foreach ($sales_data as $row) {
                    if (!empty($row['best_selling_set'])) {
                        $setCounts[$row['best_selling_set']] = ($setCounts[$row['best_selling_set']] ?? 0) + 1;
                    }
                }
                arsort($setCounts);
                $topSet = !empty($setCounts) ? key($setCounts) : 'ثبت نشده';
                ?>
                <div>
                    <h4 class="text-lg font-black text-rose-700 leading-tight"><?php echo htmlspecialchars($topSet); ?></h4>
                    <p class="text-[10px] text-slate-400 font-bold mt-1">محبوب‌ترین ست هماهنگ هفته</p>
                </div>
            </div>

            <!-- 3. Top selling product of the week -->
            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-40 rounded-3xl">
                <div>
                    <span class="text-[9px] bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-full font-black">محصول ترند تکی</span>
                    <h4 class="font-black text-[11px] text-slate-400 mt-3.5">بیشترین سفارش از کاتالوگ</h4>
                </div>
                <?php
                $prCounts = [];
                foreach ($sales_data as $row) {
                    if (!empty($row['best_product_of_week'])) {
                        $prCounts[$row['best_product_of_week']] = ($prCounts[$row['best_product_of_week']] ?? 0) + 1;
                    }
                }
                arsort($prCounts);
                $topPr = !empty($prCounts) ? key($prCounts) : 'ثبت نشده';
                ?>
                <div>
                    <h4 class="text-lg font-black text-emerald-700 leading-tight"><?php echo htmlspecialchars($topPr); ?></h4>
                    <p class="text-[10px] text-slate-400 font-bold mt-1">بازه تقویمی هفته اخیر</p>
                </div>
            </div>

            <!-- 4. Most popular sizes & colors -->
            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-40 rounded-3xl">
                <div>
                    <span class="text-[9px] bg-sky-50 text-sky-700 px-2.5 py-1 rounded-full font-black">رنگ و مدل ترند هفته</span>
                    <h4 class="font-black text-[11px] text-slate-400 mt-3.5">رنگبندی غالب و سایز بندی پرطرفدار</h4>
                </div>
                <?php
                $colorsRaw = [];
                foreach ($sales_data as $row) {
                    if (!empty($row['sizes_and_colors'])) {
                        $colorsRaw[] = $row['sizes_and_colors'];
                    }
                }
                $combColors = !empty($colorsRaw) ? implode(" / ", array_slice(array_unique($colorsRaw), 0, 2)) : 'ثبت نشده';
                ?>
                <div>
                    <h4 class="text-xs font-black text-slate-800 leading-tight"><?php echo htmlspecialchars($combColors); ?></h4>
                    <p class="text-[10px] text-slate-400 font-bold mt-1">سایزهای ترند کالا</p>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <!-- Importer & Excel Paste / Manual Entry Container -->
            <div class="premium-card p-8 bg-indigo-50/30 border border-indigo-100 rounded-3xl shadow-premium mb-8">
                <div class="flex flex-col lg:flex-row justify-between items-start gap-6 mb-6">
                    <div class="flex items-start gap-4">
                        <span class="text-4xl">📥</span>
                        <div>
                            <h3 class="font-black text-base text-slate-950 mb-1">بارگذاری هوشمند داده‌های فروش روزانه</h3>
                            <p class="text-xs font-bold text-slate-500 leading-relaxed">
                                شعبه انتخابی فعلی: <span class="text-indigo-650 font-black"><?php echo htmlspecialchars($current_store['name']); ?></span> 
                                (نوع: <span class="bg-indigo-100 px-2.5 py-0.5 rounded-md text-[10px] text-indigo-700 font-extrabold"><?php echo $current_store['type'] === 'physical' ? '🏬 حضوری / شعب پاساژ' : '🌐 آنلاین شاپ / سایت'; ?></span>)
                            </p>
                        </div>
                    </div>
                    
                    <!-- Mode Selector Buttons with Dynamic Templates -->
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" onclick="switchInputMode('excel')" id="btn-mode-excel" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-black transition-all">📋 ورود دسته‌ای اکسل</button>
                        <button type="button" onclick="switchInputMode('manual')" id="btn-mode-manual" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl text-xs font-black transition-all">➕ ثبت تکی دستی</button>
                        
                        <div class="flex items-center gap-2 bg-white p-2 border border-indigo-150 rounded-xl">
                            <span class="text-xl">📊</span>
                            <button type="button" onclick="downloadExcelTemplate('<?php echo $current_store['type']; ?>')" class="flex items-center gap-1 px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-black transition-all">
                                <span>دانلود تمپلیت اکسل</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 1. Mode: EXCEL PASTE FORM -->
                <div id="section-input-excel" class="space-y-4">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="store_id" value="<?php echo $selected_store_id; ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <span class="text-[10px] font-black text-slate-400 block mb-1 uppercase">فرمت ورودی دیتا</span>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-1.5 text-xs font-bold text-slate-700 cursor-pointer">
                                        <input type="radio" name="import_type" value="excel" checked class="text-indigo-600 focus:ring-indigo-500 border-slate-300">
                                        <span>کپی مستقیم حاصل از اکسل (TSV Tab-separated)</span>
                                    </label>
                                    <label class="flex items-center gap-1.5 text-xs font-bold text-slate-700 cursor-pointer">
                                        <input type="radio" name="import_type" value="json" class="text-indigo-600 focus:ring-indigo-500 border-slate-300">
                                        <span>نوع ساختاری JSON</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div>
                            <textarea name="import_content" rows="4" class="w-full p-4 bg-white border border-indigo-150 rounded-2xl text-xs font-mono outline-none focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 leading-relaxed transition-all" placeholder="ستون‌های شیت را کپی نموده و در این جایگاه بچسبانید (Paste)..." required></textarea>
                        </div>

                        <div class="flex flex-col md:flex-row justify-between items-center gap-4 pt-1">
                            <div class="text-[10px] text-indigo-700 font-extrabold leading-relaxed flex flex-wrap items-center gap-1">
                                <span>💡 ستون‌های لازم اکسل شما:</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">تاریخ</span>
                                <span>•</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">روز هفته</span>
                                <span>•</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">مبلغ فروش (تومان)</span>
                                <span>•</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">تعداد اقلام</span>
                                <span>•</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">تعداد فاکتور</span>
                                <span>•</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">ثبت‌کننده</span>
                                <span>•</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">ست برتر</span>
                                <span>•</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">محصول ترند</span>
                                <span>•</span>
                                <span class="bg-indigo-100/50 text-indigo-900 border border-indigo-100/30 px-2 py-0.5 rounded-md">سایز و رنگ</span>
                                <span>•</span>
                                <span class="bg-teal-100 text-teal-900 border border-teal-200 px-2 py-0.5 rounded-md"><?php echo $current_store['type'] === 'physical' ? 'مراجعین حضوری' : 'بازدید سایت'; ?></span>
                            </div>
                            <button type="submit" name="import_sales_data_action" class="px-8 py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-md transition-all self-stretch md:self-auto">بروزرسانی فوری آمار</button>
                        </div>
                    </form>
                </div>

                <!-- 2. Mode: SINGLE DAY MANUAL FORM -->
                <div id="section-input-manual" class="hidden space-y-4">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="store_id" value="<?php echo $selected_store_id; ?>">
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4">
                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">تاریخ شروع / تک‌روز (شمسی)</label>
                                <input type="text" name="date" data-jdp required placeholder="کلیک کنید..." class="w-full p-2.5 bg-white border border-indigo-200 rounded-xl text-xs font-bold outline-none cursor-pointer hover:bg-slate-50 transition-colors focus:ring-2 focus:ring-indigo-400">
                            </div>
                            <div>
                                <label class="text-[9px] font-black text-indigo-700 block mb-1">تاریخ پایان - برای بازه (شمسی/اختیاری)</label>
                                <input type="text" name="date_end" data-jdp placeholder="کلیک کنید (اختیاری)..." class="w-full p-2.5 bg-white border border-indigo-200 rounded-xl text-xs font-bold outline-none cursor-pointer hover:bg-slate-50 transition-colors focus:ring-2 focus:ring-indigo-400">
                            </div>
                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">روز هفته</label>
                                <select name="day_of_week" class="w-full px-2 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none">
                                    <option value="">خودکار براساس تاریخ (یا بازه)</option>
                                    <option value="شنبه">شنبه</option>
                                    <option value="یکشنبه">یکشنبه</option>
                                    <option value="دوشنبه">دوشنبه</option>
                                    <option value="سه‌شنبه">سه‌شنبه</option>
                                    <option value="چهارشنبه">چهارشنبه</option>
                                    <option value="پنج‌شنبه">پنج‌شنبه</option>
                                    <option value="جمعه">جمعه</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">کل درآمد روز (تومان / اختیاری)</label>
                                <input type="number" name="sales_amount" placeholder="مثال: 18500000" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none">
                            </div>
                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">تعداد کل اقلام (قلم / اختیاری)</label>
                                <input type="number" name="sales_count" placeholder="مثال: 9" class="w-full p-2.5 bg-white border border-slate-250 rounded-xl text-xs font-bold outline-none">
                            </div>
                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">تعداد کل فاکتورها (اختیاری)</label>
                                <input type="number" name="invoice_count" placeholder="مثال: 4" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none">
                            </div>
                            
                            <!-- Traffic parameter based on store mode -->
                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1"><?php echo $current_store['type'] === 'physical' ? 'مراجعین حضوری (پاخور / اختیاری)' : 'بازدید روزانه سایت (اختیاری)'; ?></label>
                                <input type="number" name="visits" placeholder="مثال: 120" class="w-full p-2.5 bg-emerald-50 border border-emerald-100 rounded-xl text-xs font-bold outline-none">
                            </div>
 
                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">فروشنده شیفت / پرسنل</label>
                                <select name="personnel_name" class="w-full px-2 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold">
                                    <option value="سیستم">سیستم عمومی</option>
                                    <?php foreach ($personnel_list as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="text-[9px] font-black text-indigo-700 block mb-1">🎯 انتساب به کمپین تبلیغاتی فعال</label>
                                <select name="linked_campaign_promo" class="w-full px-2 py-2.5 bg-indigo-50/50 border border-indigo-100 rounded-xl text-xs font-bold outline-none">
                                    <option value="">-- بدون انتساب کمپین --</option>
                                    <?php 
                                    $all_camps = $project['settings']['campaigns'] ?? [];
                                    foreach ($all_camps as $camp): 
                                        $pcode = $camp['promo_code'] ?? '';
                                        if (empty($pcode)) continue;
                                    ?>
                                        <option value="<?php echo htmlspecialchars($pcode); ?>">
                                            <?php echo htmlspecialchars($camp['title'] . ' (' . ($camp['platform'] ?? '') . ' - ' . $pcode . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">بهترین ست زوج / هماهنگ</label>
                                <input type="text" name="best_selling_set" placeholder="مثال: ست کتان پاییزه" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none">
                            </div>

                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">محصول تکی ترند هفته</label>
                                <input type="text" name="best_product_of_week" placeholder="مثال: کت چرم" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none">
                            </div>

                            <div>
                                <label class="text-[9px] font-black text-slate-500 block mb-1">سایزبندی و رنگ ترند</label>
                                <input type="text" name="sizes_and_colors" placeholder="مثال: XL طوسی" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none">
                            </div>

                            <!-- Custom indicators defined dynamically by user -->
                            <?php foreach ($custom_metrics as $cm): ?>
                                <div>
                                    <label class="text-[9px] font-black text-indigo-700 block mb-1">📊 <?php echo htmlspecialchars($cm['name']); ?></label>
                                    <input type="number" name="<?php echo htmlspecialchars($cm['key']); ?>" placeholder="0" class="w-full p-2.5 bg-indigo-50 border border-indigo-100 rounded-xl text-xs font-bold outline-none">
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex justify-end pt-2">
                            <button type="submit" name="add_single_day_sale_action" class="px-10 py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black shadow-md transition-all">✅ ثبت نهایی این ردیف روزانه</button>
                        </div>
                    </form>

                    <!-- List of already registered days/ranges to prevent duplicates -->
                    <div class="mt-6 border-t border-slate-100 pt-6">
                        <h4 class="font-black text-xs text-slate-800 mb-3 flex items-center gap-2">
                            <span>📅</span>
                            <span>بازه و روزهای ثبت شده کنونی (جلوگیری از ایجاد تداخل و تکرار)</span>
                        </h4>
                        
                        <?php if (empty($sales_data)): ?>
                            <p class="text-[10px] text-slate-400 font-bold">هیچ دوره‌ای هنوز ثبت نشده است.</p>
                        <?php else: ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 font-semibold">
                                <?php
                                $groupedByMonth = [];
                                $mShortlist = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
                                foreach ($sales_data as $row) {
                                    $d = $row['date'];
                                    $parts = explode('-', $d);
                                    if (count($parts) === 3) {
                                        $y = $parts[0];
                                        $mIdx = intval($parts[1]) - 1;
                                        $mName = $mShortlist[$mIdx] ?? 'نامشخص';
                                        $groupedByMonth["$y - $mName"][] = $row;
                                    } else {
                                        $groupedByMonth["سایر موارد"][] = $row;
                                    }
                                }
                                ?>
                                <?php foreach ($groupedByMonth as $monthLabel => $items): ?>
                                    <div class="p-3.5 bg-slate-50 border border-slate-200/50 rounded-2xl space-y-2">
                                        <div class="font-extrabold text-[11px] text-indigo-700 border-b border-indigo-100 pb-1.5"><?php echo htmlspecialchars($monthLabel); ?></div>
                                        <div class="max-h-32 overflow-y-auto space-y-1.5 pr-1">
                                            <?php foreach ($items as $it): ?>
                                                <div class="flex justify-between items-center bg-white p-2 border border-slate-100 rounded-xl text-[10px] font-bold">
                                                    <span class="font-mono text-slate-705">
                                                        <?php 
                                                        echo htmlspecialchars($it['date']); 
                                                        if (!empty($it['date_end'])) {
                                                            echo ' تا ' . htmlspecialchars($it['date_end']); 
                                                        }
                                                        ?>
                                                    </span>
                                                    <span class="bg-indigo-50/70 text-indigo-700 px-2 py-0.5 rounded-lg text-[9px] font-extrabold shrink-0">
                                                        <?php echo number_format($it['sales_amount'] ?? 0); ?> ت
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Scripts for mode switching & downloader -->
            <script>
            function switchInputMode(mode) {
                var secExcel = document.getElementById('section-input-excel');
                var secManual = document.getElementById('section-input-manual');
                var btnExcel = document.getElementById('btn-mode-excel');
                var btnManual = document.getElementById('btn-mode-manual');
                
                if (mode === 'excel') {
                    secExcel.classList.remove('hidden');
                    secManual.classList.add('hidden');
                    
                    btnExcel.className = "px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-black transition-all";
                    btnManual.className = "px-4 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl text-xs font-black transition-all";
                } else {
                    secExcel.classList.add('hidden');
                    secManual.classList.remove('hidden');
                    
                    btnExcel.className = "px-4 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl text-xs font-black transition-all";
                    btnManual.className = "px-4 py-2 bg-emerald-600 text-white rounded-xl text-xs font-black transition-all";
                }
            }
            
            function downloadExcelTemplate(type) {
                let headers = "";
                let sampleData = "";
                let fileName = "";
                
                if (type === 'online') {
                    headers = "تاریخ\tروز هفته\tمبلغ فروش\tتعداد اقلام\tتعداد فاکتور\tثبت کننده\tست برتر\tمحصول ترند\tسایز و رنگ\tبازدید سایت\n";
                    sampleData = "1405/03/10\tشنبه\t12000000\t6\t3\tمرضیه عباسی\tست اسپرت بهار\tمانتو کتان\tM/L طوسی\t180\n" +
                                 "1405/03/11\tیکشنبه\t17500000\t9\t4\tمرضیه عباسی\tست زوجی نخی\tشومیز کرم\tL صورتی\t240\n" +
                                 "1405/03/12\tدوشنبه\t980000\t1\t1\tسیستم\t---\tمانتو نخی\tFree مشکی\t95\n";
                    fileName = "maarksho_online_sale_template.tsv";
                } else {
                    headers = "تاریخ\tروز هفته\tمبلغ فروش\tتعداد اقلام\tتعداد فاکتور\tثبت کننده\tست برتر\tمحصول ترند\tسایز و رنگ\tمراجعین حضوری\n";
                    sampleData = "1405/03/10\tشنبه\t24300000\t12\t6\tعلی محمدی\tست زوج پایایی\tکت کتان کلاهدار\tXL کرم\t55\n" +
                                 "1405/03/11\tیکشنبه\t18200000\t9\t4\tعلی محمدی\tست اسپرت راحتی\tمانتو لینن\tFree سبز\t42\n" +
                                 "1405/03/12\tدوشنبه\t32000000\t15\t7\tشیفت عصر\tست ساحلی خنک\tساحلی قواره‌دار\tL سرمه‌ای\t78\n";
                    fileName = "maarksho_physical_sale_template.tsv";
                }
                
                let blob = new Blob(["\ufeff" + headers + sampleData], { type: 'text/tab-separated-values;charset=utf-8;' });
                let link = document.createElement("a");
                let url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", fileName);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
            </script>
        <?php endif; ?>

        <!-- Detailed Daily Metrics Grid & Table -->
        <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm rounded-3xl mb-8">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-6">
                <div>
                    <h3 class="font-black text-sm text-slate-800">📋 کارپوشه تجمیعی روزانه شاخص‌های فروش فروشگاه</h3>
                    <p class="text-[9px] text-slate-400 font-bold mt-1">محاسبه برون‌خم‌های فروشگاهی UPT , AUV , ATV</p>
                </div>
                
                <button onclick="document.getElementById('comparisonToolSection').classList.toggle('hidden')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all">🔄 ابزار مقایسه تطبیقی برهه‌ها</button>
            </div>

            <!-- Comparison popup box inside page -->
            <div id="comparisonToolSection" class="p-5 mb-6 bg-slate-50 border border-slate-200 rounded-2xl hidden space-y-4">
                <div class="flex justify-between items-center pb-2 border-b border-slate-200">
                    <h4 class="font-black text-xs text-slate-800">🔄 ابزار مقایسه زمانی هوشمند (Period Comparison)</h4>
                    <button onclick="document.getElementById('comparisonToolSection').classList.add('hidden')" class="text-slate-400 font-black">×</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-500 block mb-1">انتخاب روز اول (مبنا)</label>
                        <select id="compareA" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold" onchange="runComparison()">
                            <option value="">یکی از روزهای بارگذاری شده را انتخاب کنید</option>
                            <?php foreach ($sales_data as $row): ?>
                                <option value="<?php echo htmlspecialchars($row['date']); ?>"><?php echo htmlspecialchars($row['date']); ?> (<?php echo htmlspecialchars($row['day_of_week'] ?? ''); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-500 block mb-1">انتخاب روز دوم (مقایسه)</label>
                        <select id="compareB" class="w-full px-3 py-2 bg-white border border-slate-100 rounded-xl text-xs font-bold" onchange="runComparison()">
                            <option value="">یکی از روزهای بارگذاری شده را انتخاب کنید</option>
                            <?php foreach ($sales_data as $row): ?>
                                <option value="<?php echo htmlspecialchars($row['date']); ?>"><?php echo htmlspecialchars($row['date']); ?> (<?php echo htmlspecialchars($row['day_of_week'] ?? ''); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="comparisonResultBox" class="p-4 bg-white border border-slate-200 rounded-2xl hidden">
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div class="p-2.5 bg-slate-50 rounded-xl">
                            <span class="text-[9px] font-black text-slate-400 block mb-1">فروش روز اول</span>
                            <h5 id="compValA" class="text-sm font-black">0</h5>
                        </div>
                        <div class="p-2.5 bg-slate-50 rounded-xl">
                            <span class="text-[9px] font-black text-slate-400 block mb-1">فروش روز دوم</span>
                            <h5 id="compValB" class="text-sm font-black">0</h5>
                        </div>
                        <div class="p-2.5 rounded-xl flex items-center justify-center font-black text-xs" id="compScoreBox">
                            <span>در حال مقایسه...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table content -->
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-indigo-50/50 text-indigo-950 font-black border-b border-indigo-100 rounded-xl">
                            <th class="p-3.5">تاریخ</th>
                            <th class="p-3.5">روز هفته</th>
                            <th class="p-3.5">کل درآمد روزانه (تومان)</th>
                            <th class="p-3.5">تعداد اقلام فروش</th>
                            <th class="p-3.5">تعداد کل فاکتورها</th>
                            <th class="p-3.5">ترافیک (بازدید / پاخور)</th>
                            <th class="p-3.5">نرخ تبدیل (CR)</th>
                            <th class="p-3.5">پارامتر UPT</th>
                            <th class="p-3.5">پارامتر AUV (تومان)</th>
                            <th class="p-3.5">پارامتر ATV (تومان)</th>
                            <th class="p-3.5">ثبت‌کننده</th>
                            <?php if ($isAdmin): ?>
                                <th class="p-3.5 text-center">حذف ردیف</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-semibold text-slate-650">
                        <?php if (empty($sales_data)): ?>
                            <tr>
                                <td colspan="12" class="p-10 text-center text-slate-400 font-bold">اطلاعاتی یافت نشد. ردیف‌های شیت مالی را در گره بالا بارگذاری کنید.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales_data as $row): 
                                $rMoney = ($row['sales_amount'] !== '' && $row['sales_amount'] !== null) ? (float)$row['sales_amount'] : null;
                                $rQty = ($row['sales_count'] !== '' && $row['sales_count'] !== null) ? (int)$row['sales_count'] : null;
                                $rInvoices = ($row['invoice_count'] !== '' && $row['invoice_count'] !== null) ? (int)$row['invoice_count'] : null;
                                $rVisits = ($row['visits'] !== '' && $row['visits'] !== null) ? (int)$row['visits'] : null;
                                
                                $upt = ($rInvoices > 0 && $rQty !== null) ? round($rQty / $rInvoices, 2) : null;
                                $auv = ($rQty > 0 && $rMoney !== null) ? round($rMoney / $rQty) : null;
                                $atv = ($rInvoices > 0 && $rMoney !== null) ? round($rMoney / $rInvoices) : null;
                                $cr = ($rVisits > 0 && $rInvoices !== null) ? round(($rInvoices / $rVisits) * 100, 1) . '%' : '0%';
                            ?>
                                <tr class="hover:bg-slate-50/80 transition-all">
                                    <td class="p-3.5 font-mono font-bold text-slate-900">
                                        <?php 
                                        echo htmlspecialchars($row['date']); 
                                        if (!empty($row['date_end'])) {
                                            echo ' <span class="text-indigo-600 font-extrabold mx-1">تا</span> ' . htmlspecialchars($row['date_end']); 
                                        }
                                        ?>
                                    </td>
                                    <td class="p-3.5"><?php echo htmlspecialchars($row['day_of_week'] ?? '---'); ?></td>
                                    <td class="p-3.5 font-mono text-slate-900 font-black">
                                        <?php echo ($rMoney !== null) ? number_format($rMoney) : '<span class="text-slate-300 font-normal">---</span>'; ?>
                                    </td>
                                    <td class="p-3.5 font-mono">
                                        <?php echo ($rQty !== null) ? number_format($rQty) : '<span class="text-slate-300 font-normal">---</span>'; ?>
                                    </td>
                                    <td class="p-3.5 font-mono">
                                        <?php echo ($rInvoices !== null) ? number_format($rInvoices) : '<span class="text-slate-300 font-normal">---</span>'; ?>
                                    </td>
                                    <td class="p-3.5 font-mono text-indigo-650 font-bold">
                                        <?php echo ($rVisits !== null) ? number_format($rVisits) : '<span class="text-slate-300 font-normal">---</span>'; ?>
                                    </td>
                                    <td class="p-3.5 font-mono text-teal-600 font-black"><?php echo $cr; ?></td>
                                    <td class="p-3.5 font-mono text-indigo-600 font-bold">
                                        <?php echo ($upt !== null) ? $upt : '<span class="text-slate-300 font-normal">---</span>'; ?>
                                    </td>
                                    <td class="p-3.5 font-mono">
                                        <?php echo ($auv !== null) ? number_format($auv) : '<span class="text-slate-300 font-normal">---</span>'; ?>
                                    </td>
                                    <td class="p-3.5 font-mono">
                                        <?php echo ($atv !== null) ? number_format($atv) : '<span class="text-slate-300 font-normal">---</span>'; ?>
                                    </td>
                                    <td class="p-3.5 text-slate-400 text-[10px] font-black"><?php echo htmlspecialchars($row['personnel_name'] ?? 'سیستم'); ?></td>
                                    <?php if ($isAdmin): ?>
                                        <td class="p-3.5 text-center">
                                            <form method="POST" onsubmit="return confirm('آیا از حذف داده‌های عملیاتی این روز مطمئن هستید؟')">
                                                <input type="hidden" name="store_id" value="<?php echo $selected_store_id; ?>">
                                                <input type="hidden" name="day_date" value="<?php echo htmlspecialchars($row['date']); ?>">
                                                <button name="delete_day_action" class="text-rose-500 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 p-1 rounded-lg">🗑️</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</main>

<!-- ============================================== -->
<!-- CONFIG MODALS AND WINDOWS -->
<!-- ============================================== -->
<?php if ($isAdmin): ?>
    <!-- 1. Modal: Define Store Brand -->
    <div id="addStoreModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] p-8 max-w-md w-full border border-slate-100 shadow-2xl relative">
            <div class="flex items-center justify-between mb-6">
                <h3 class="font-black text-lg text-slate-900">🏪 تعریف شعبه / کانال جدید</h3>
                <button onclick="document.getElementById('addStoreModal').style.display='none'" class="text-slate-400 text-xl font-bold">×</button>
            </div>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1 uppercase">نام کامل شعبه</label>
                    <input type="text" name="store_name" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500" placeholder="مثال: شعبه پاساژ تندیس">
                </div>

                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1 uppercase">نوع تراکنش</label>
                    <select name="store_type" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                        <option value="physical">حضوری / فروشگاه فیزیکی</option>
                        <option value="online">آنلاین شاپ / گالری اینترنتی</option>
                    </select>
                </div>

                <button type="submit" name="add_store_action" class="w-full py-4 bg-brand-500 hover:bg-brand-600 text-white rounded-xl text-xs font-black shadow-md transition-all">ایجاد و ثبت در تنظیمات</button>
            </form>
        </div>
    </div>

    <!-- 2. Modal: Configure Store Details & Targets -->
    <?php if ($current_store): ?>
    <div id="configureStoreModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-[2.5rem] p-8 max-w-2xl w-full border border-slate-100 shadow-2xl relative my-8" onclick="event.stopPropagation();">
            <div class="flex items-center justify-between mb-6 border-b border-slate-100 pb-3">
                <div>
                    <h3 class="font-black text-lg text-slate-900">⚙️ تنظیمات شعبه: <?php echo htmlspecialchars($current_store['name']); ?></h3>
                    <p class="text-[9px] text-slate-400 font-bold mt-1">تعریف پرسنل، تارگت‌های سالانه/ماهانه، و شاخص‌های آماری دلخواه</p>
                </div>
                <button onclick="document.getElementById('configureStoreModal').style.display='none'" class="text-slate-400 text-xl font-bold">×</button>
            </div>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="store_id" value="<?php echo $selected_store_id; ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <span class="text-[10px] font-black text-slate-400 block mb-1 uppercase">نام فروشگاه</span>
                        <input type="text" name="store_name" value="<?php echo htmlspecialchars($current_store['name']); ?>" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none">
                    </div>
                    <div>
                        <span class="text-[10px] font-black text-slate-400 block mb-1 uppercase">نوع فروشگاه</span>
                        <select name="store_type" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none">
                            <option value="online" <?php echo $current_store['type'] === 'online' ? 'selected' : ''; ?>>اینترنتی</option>
                            <option value="physical" <?php echo $current_store['type'] === 'physical' ? 'selected' : ''; ?>>حضوری</option>
                        </select>
                    </div>
                </div>

                <div>
                    <span class="text-[10px] font-black text-slate-400 block mb-1 uppercase">✍️ تعریف نام پرسنل (هر فروشنده در یک خط)</span>
                    <textarea name="personnel_list" rows="3" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-400" placeholder="علی احمدی&#11088;مهدی رضایی"><?php echo htmlspecialchars(implode("\n", $current_store['personnel'] ?? [])); ?></textarea>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <h4 class="text-xs font-black text-slate-800 mb-3">📍 تعریف تارگت‌های ماهیانه (ماه شِمسی)</h4>
                    <div class="space-y-3 mb-3">
                        <?php foreach ($targets as $tg): ?>
                            <div class="flex items-center justify-between bg-slate-50 p-3 rounded-xl text-xs">
                                <div class="font-bold">
                                    <span class="text-slate-800 font-black"><?php echo htmlspecialchars($tg['period_name']); ?></span> |
                                    <span class="text-indigo-600 font-bold"><?php echo number_format($tg['target_money']); ?> ت</span> |
                                    <span class="text-emerald-600"><?php echo number_format($tg['target_count']); ?> کالا</span>
                                </div>
                                <button type="submit" name="delete_target_id" value="<?php echo $tg['id']; ?>" class="text-rose-500 hover:text-rose-700 text-[10px]">❌ حذف</button>
                                <input type="hidden" name="edit_target_ids[]" value="<?php echo $tg['id']; ?>">
                                <input type="hidden" name="edit_period_name[]" value="<?php echo htmlspecialchars($tg['period_name']); ?>">
                                <input type="hidden" name="edit_target_money[]" value="<?php echo $tg['target_money']; ?>">
                                <input type="hidden" name="edit_target_count[]" value="<?php echo $tg['target_count']; ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="bg-indigo-50/30 p-4 border border-indigo-100/50 rounded-2xl grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                        <div class="grid grid-cols-2 gap-2">
                            <?php
                            $monthsListT = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
                            $currentJMonthT = (int)jdate('m', time(), '', 'Asia/Tehran', 'en');
                            $currentJYearT = (int)jdate('Y', time(), '', 'Asia/Tehran', 'en');
                            ?>
                            <div>
                                <label class="text-[9px] font-black text-indigo-900 block mb-1">انتخاب ماه</label>
                                <select name="new_period_month" class="w-full p-2 bg-white border border-indigo-100 rounded-lg text-[11px] font-black outline-none">
                                    <?php foreach ($monthsListT as $mIdx => $mName): ?>
                                        <option value="<?php echo $mName; ?>" <?php echo ($mIdx + 1 == $currentJMonthT) ? 'selected' : ''; ?>>
                                            <?php echo $mName; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-[9px] font-black text-indigo-900 block mb-1">انتخاب سال</label>
                                <select name="new_period_year" class="w-full p-2 bg-white border border-indigo-100 rounded-lg text-[11px] font-black outline-none">
                                    <?php for ($yr = 1400; $yr <= 1415; $yr++): ?>
                                        <option value="<?php echo $yr; ?>" <?php echo ($yr == $currentJYearT) ? 'selected' : ''; ?>>
                                            <?php echo $yr; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <span class="text-[9px] font-black text-indigo-900 block mb-1">تارگت مبلغ فروش (تومان)</span>
                            <input type="number" name="new_target_money" class="w-full p-2 bg-white border border-indigo-100 rounded-lg text-xs font-bold outline-none" placeholder="150000000">
                        </div>
                        <div>
                            <span class="text-[9px] font-black text-indigo-900 block mb-1">تارگت تعداد اقلام فروش</span>
                            <input type="number" name="new_target_count" class="w-full p-2 bg-white border border-indigo-100 rounded-lg text-xs font-bold outline-none" placeholder="500">
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center gap-3 border-t border-slate-100 pt-4">
                    <button type="submit" name="delete_store_action" onclick="return confirm('آیا از حذف کامل این شعبه و سوابق آن اطمینان دارید؟')" class="px-5 py-3.5 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded-xl text-xs font-black transition-all">🗑️ حذف کل شعبه</button>
                    <div class="flex gap-2">
                        <button type="button" onclick="document.getElementById('configureStoreModal').style.display='none'" class="px-5 py-3.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-black">انصراف</button>
                        <button type="submit" name="save_store_config_action" class="px-7 py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-md">ذخیره نهایی تنظیمات</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
// Period comparison logic
function runComparison() {
    var a = document.getElementById('compareA').value;
    var b = document.getElementById('compareB').value;
    var resBox = document.getElementById('comparisonResultBox');
    
    if (a && b) {
        resBox.classList.remove('hidden');
        var salesData = <?php echo json_encode($sales_data, JSON_UNESCAPED_UNICODE); ?>;
        var rA = salesData.find(r => r.date === a);
        var rB = salesData.find(r => r.date === b);
        
        if (rA && rB) {
            var vA = parseFloat(rA.sales_amount || 0);
            var vB = parseFloat(rB.sales_amount || 0);
            
            document.getElementById('compValA').innerHTML = vA.toLocaleString() + ' ت';
            document.getElementById('compValB').innerHTML = vB.toLocaleString() + ' ت';
            
            var scoreBox = document.getElementById('compScoreBox');
            if (vA === 0) {
                scoreBox.className = 'p-3 bg-slate-100 text-slate-600 font-black rounded-xl text-center';
                scoreBox.innerHTML = 'بدون تغییر';
            } else {
                var growth = ((vB - vA) / vA) * 100;
                if (growth >= 0) {
                    scoreBox.className = 'p-3 bg-emerald-50 text-emerald-700 font-black rounded-xl text-center';
                    scoreBox.innerHTML = '📈 ' + growth.toFixed(1) + '% رشد';
                } else {
                    scoreBox.className = 'p-3 bg-rose-50 text-rose-700 font-black rounded-xl text-center';
                    scoreBox.innerHTML = '📉 ' + Math.abs(growth).toFixed(1) + '% کاهش';
                }
            }
        }
    }
}

function runSalesAnalyticsAI() {
    var btn = document.getElementById('btn-sales-ai');
    var spinner = document.getElementById('ai-sales-spinner');
    var container = document.getElementById('ai-sales-report-container');
    var reportContent = document.getElementById('ai-sales-report-content');
    
    btn.disabled = true;
    if (spinner) spinner.classList.remove('hidden');
    btn.classList.add('opacity-75');
    container.classList.remove('hidden');
    reportContent.innerHTML = '<span class="text-slate-400 font-bold animate-pulse">⏱️ هوش مصنوعی Maarksho Flow در حال استخراج متریک‌ها و تحلیل هوشمند داده‌های فروشگاه است... لطفاً شکیبا باشید چند لحظه...</span>';
    
    // Build query with store_id
    var storeId = "<?php echo $selected_store_id; ?>";
    var projectId = "<?php echo $projectId; ?>";
    var ajaxUrl = "sale.php?ajax_action=get_sales_ai_analysis&store_id=" + encodeURIComponent(storeId) + "&project=" + encodeURIComponent(projectId);
    
    fetch(ajaxUrl)
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            if (spinner) spinner.classList.add('hidden');
            btn.classList.remove('opacity-75');
            
            if (res.success) {
                // Apply response
                // Format markdown headers, lists into beautifully readable layout
                var text = res.response;
                // Simple Markdown-to-HTML formatting for bold and list items
                text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                text = text.replace(/### (.*?)\n/g, '<h4 class="text-sm font-extrabold text-indigo-700 mt-4 mb-2">$1</h4>');
                text = text.replace(/## (.*?)\n/g, '<h3 class="text-sm font-black text-indigo-800 mt-5 mb-3 border-b pb-1">$1</h3>');
                text = text.replace(/- (.*?)\n/g, '<li class="mr-4 list-disc text-slate-700 my-1">$1</li>');
                
                reportContent.innerHTML = text;
            } else {
                reportContent.innerHTML = '<span class="text-rose-650 font-black">❌ خطا در تولید تحلیل هوش مصنوعی: ' + res.message + '</span>';
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            if (spinner) spinner.classList.add('hidden');
            btn.classList.remove('opacity-75');
            reportContent.innerHTML = '<span class="text-rose-650 font-black">❌ خطای مکرر ارتباطی در اتصال به سرور جهت بازیابی اطلاعات.</span>';
        });
}

function copySalesAiReport() {
    var el = document.getElementById('ai-sales-report-content');
    var text = el.textContent || el.innerText || '';
    if (!text || text.includes('⏱️ هوش مصنوعی') || text.includes('❌ خطا')) {
        alert('هیچ گزارش معتبری برای تولید و کپی وجود ندارد.');
        return;
    }
    
    var tempTextarea = document.createElement('textarea');
    tempTextarea.value = text;
    document.body.appendChild(tempTextarea);
    tempTextarea.select();
    document.execCommand('copy');
    document.body.removeChild(tempTextarea);
    alert('گزارش استراتژیک هوش مصنوعی با موفقیت در کلیپ‌بورد ذخیره شد! 📈');
}
</script>

</body>
</html>
