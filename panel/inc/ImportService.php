<?php
/**
 * ImportService.php
 * Premium ETL Data Import/Export Engine for Google Analytics 4 (GA4) and Google Search Console (GSC) Exports
 */

class ImportService {

    /**
     * Define internal structure mappings and synonyms for columns to support auto-mapping.
     */
    private static $mapping_rules = [
        // Date dimension
        'date' => ['date', 'Date', 'تاریخ', 'روز', 'day', 'Day', 'عملکرد', 'تاریخ عملکرد', 'تاریخ ها', 'تاریخ‌ها'],
        
        // Search Console dimensions
        'query' => ['query', 'Query', 'search query', 'عبارت', 'جستجو', 'عبارت جستجو', 'کلمه کلیدی', 'top queries', 'Top queries', 'پرس‌وجو', 'جستارهای برتر', 'جستارها'],
        'page' => ['page', 'Page', 'landing page', 'Landing Page', 'url', 'URL', 'وبسایت', 'صفحه', 'لندینک', 'لندینگ پیج', 'صفحات', 'صفحه‌ها'],
        'device' => ['device', 'Device', 'دستگاه', 'نوع دستگاه', 'دستگاه‌ها', 'دستگاه ها'],
        'country' => ['country', 'Country', 'کشور', 'نام کشور', 'کشورها', 'کشور ها'],
        
        // Search Console metrics
        'clicks' => ['clicks', 'Clicks', 'کلیک‌', 'کلیک', 'کلیک‌ها', 'تعداد کلیک', 'کلیک ها'],
        'impressions' => ['impressions', 'Impressions', 'impression', 'Impression', 'نمایش', 'نمایش‌ها', 'ایمپرشن', 'تعداد نمایش', 'نمایش ها'],
        'ctr' => ['ctr', 'CTR', 'Click-through rate', 'نرخ کلیک', 'سی‌تی‌آر', 'نرخ کلیک کل'],
        'position' => ['position', 'Position', 'avg position', 'Average Position', 'رتبه', 'جایگاه', 'رتبه متوسط', 'موقعیت'],

        // Analytics metrics
        'users' => ['users', 'Users', 'total users', 'Active Users', 'کاربران', 'کاربران فعال', 'کاربر', 'تعداد کاربران'],
        'new_users' => ['new users', 'new_users', 'New Users', 'کاربران جدید', 'کاربر جدید'],
        'sessions' => ['sessions', 'Sessions', 'سشن', 'سشن‌ها', 'نشست‌', 'نشست‌ها', 'جلسات'],
        'engaged_sessions' => ['engaged sessions', 'engaged_sessions', 'Engaged sessions', 'نشست‌های درگیر', 'سشن‌های معتبر'],
        'views' => ['views', 'Views', 'pageviews', 'Pageviews', 'بازدید', 'بازدیدها', 'مشاهده', 'تعداد بازدید'],
        'event_count' => ['event count', 'event_count', 'Event Count', 'تعداد رویداد', 'رویدادها'],
        'conversions' => ['conversions', 'Conversions', 'تبدیل', 'تبدیل‌ها', 'تعداد تبدیل', 'اهداف'],
        'engagement_rate' => ['engagement rate', 'engagement_rate', 'Engagement Rate', 'نرخ تعامل', 'درصد درگیری'],
        'average_engagement_time' => ['average engagement time', 'average_engagement_time', 'Avg Engagement Time', 'میانگین زمان تعامل', 'متوسط زمان حضور'],

        // Analytics dimensions
        'channel' => ['channel', 'Default channel group', 'Channel', 'کانال', 'منبع ترافیک', 'Traffic Source', 'source', 'medium']
    ];

    /**
     * Create the database tables if they do not exist.
     */
    public static function initTables() {
        $conn = getConn();
        if (!$conn) {
            return false;
        }

        // 1. Google Analytics 4 (GA4) Daily Stats
        $conn->query("CREATE TABLE IF NOT EXISTS analytics_daily_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(128) NOT NULL,
            store_id VARCHAR(128) NOT NULL,
            date VARCHAR(20) NOT NULL,
            users INT DEFAULT 0,
            new_users INT DEFAULT 0,
            sessions INT DEFAULT 0,
            engaged_sessions INT DEFAULT 0,
            views INT DEFAULT 0,
            event_count INT DEFAULT 0,
            conversions INT DEFAULT 0,
            engagement_rate DOUBLE DEFAULT 0.0,
            average_engagement_time DOUBLE DEFAULT 0.0,
            created_at INT,
            UNIQUE(project_id, store_id, date)
        )");

        // 2. GA4 Pages (Page Paths or Landing Pages)
        $conn->query("CREATE TABLE IF NOT EXISTS analytics_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(128) NOT NULL,
            store_id VARCHAR(128) NOT NULL,
            date VARCHAR(20) NOT NULL,
            page VARCHAR(500) NOT NULL,
            views INT DEFAULT 0,
            users INT DEFAULT 0,
            sessions INT DEFAULT 0,
            event_count INT DEFAULT 0,
            conversions INT DEFAULT 0,
            created_at INT,
            UNIQUE(project_id, store_id, date, page)
        )");

        // 3. GA4 Traffic Channels
        $conn->query("CREATE TABLE IF NOT EXISTS analytics_channels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(128) NOT NULL,
            store_id VARCHAR(128) NOT NULL,
            date VARCHAR(20) NOT NULL,
            channel VARCHAR(255) NOT NULL,
            sessions INT DEFAULT 0,
            users INT DEFAULT 0,
            views INT DEFAULT 0,
            conversions INT DEFAULT 0,
            created_at INT,
            UNIQUE(project_id, store_id, date, channel)
        )");

        // 4. Google Search Console Queries
        $conn->query("CREATE TABLE IF NOT EXISTS search_console_queries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(128) NOT NULL,
            store_id VARCHAR(128) NOT NULL,
            date VARCHAR(20) NOT NULL,
            query VARCHAR(255) NOT NULL,
            clicks INT DEFAULT 0,
            impressions INT DEFAULT 0,
            ctr DOUBLE DEFAULT 0.0,
            position DOUBLE DEFAULT 0.0,
            created_at INT,
            UNIQUE(project_id, store_id, date, query)
        )");

        // 5. Google Search Console Pages
        $conn->query("CREATE TABLE IF NOT EXISTS search_console_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(128) NOT NULL,
            store_id VARCHAR(128) NOT NULL,
            date VARCHAR(20) NOT NULL,
            page VARCHAR(500) NOT NULL,
            clicks INT DEFAULT 0,
            impressions INT DEFAULT 0,
            ctr DOUBLE DEFAULT 0.0,
            position DOUBLE DEFAULT 0.0,
            created_at INT,
            UNIQUE(project_id, store_id, date, page)
        )");

        // 6. Google Search Console Devices
        $conn->query("CREATE TABLE IF NOT EXISTS search_console_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(128) NOT NULL,
            store_id VARCHAR(128) NOT NULL,
            date VARCHAR(20) NOT NULL,
            device VARCHAR(100) NOT NULL,
            clicks INT DEFAULT 0,
            impressions INT DEFAULT 0,
            ctr DOUBLE DEFAULT 0.0,
            position DOUBLE DEFAULT 0.0,
            created_at INT,
            UNIQUE(project_id, store_id, date, device)
        )");

        // 7. Google Search Console Countries
        $conn->query("CREATE TABLE IF NOT EXISTS search_console_countries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(128) NOT NULL,
            store_id VARCHAR(128) NOT NULL,
            date VARCHAR(20) NOT NULL,
            country VARCHAR(150) NOT NULL,
            clicks INT DEFAULT 0,
            impressions INT DEFAULT 0,
            ctr DOUBLE DEFAULT 0.0,
            position DOUBLE DEFAULT 0.0,
            created_at INT,
            UNIQUE(project_id, store_id, date, country)
        )");

        // 8. Import Logs (Logs details of each file import)
        $conn->query("CREATE TABLE IF NOT EXISTS import_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(128) NOT NULL,
            store_id VARCHAR(128) NOT NULL,
            file_name VARCHAR(255),
            file_size INT,
            file_type VARCHAR(50),
            module_type VARCHAR(50), -- 'analytics' or 'search_console'
            sub_type VARCHAR(50),    -- 'daily', 'pages', 'channels', 'queries', 'devices', 'countries'
            imported_at INT,
            status VARCHAR(50),      -- 'success', 'failed'
            total_records INT DEFAULT 0,
            new_records INT DEFAULT 0,
            updated_records INT DEFAULT 0,
            error_count INT DEFAULT 0,
            error_details TEXT,
            file_version VARCHAR(100)
        )");

        return true;
    }

    /**
     * Clean and normalize a string number to standard english float/int.
     */
    public static function cleanNumber($val) {
        if ($val === null || $val === '') return 0;
        
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $val = str_replace($persian, $english, $val);
        $val = str_replace($arabic, $english, $val);
        
        // Remove spaces, commas, percents
        $val = str_replace([',', ' ', '%', 'تومان', 'ریال'], '', $val);
        
        // Handle scientific notation or dashes
        if ($val === '-' || $val === 'N/A' || $val === 'null') return 0;
        
        return (float)$val;
    }

    /**
     * Convert and normalize standard and custom date formats into a Persian Jalaali YYYY-MM-DD date.
     */
    public static function normalizeToJalali($dateStr) {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $dateStr = str_replace($persian, $english, $dateStr);
        $dateStr = str_replace($arabic, $english, $dateStr);
        $dateStr = trim($dateStr);
        
        if (empty($dateStr)) {
            return jdate('Y-m-d');
        }

        // Try YYYY-MM-DD or YYYY/MM/DD
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $dateStr, $m)) {
            $part1 = (int)$m[1];
            $part2 = (int)$m[2];
            $part3 = (int)$m[3];
            // Gregorian?
            if ($part1 > 1700) {
                if (function_exists('gregorian_to_jalali')) {
                    $j = gregorian_to_jalali($part1, $part2, $part3);
                    return sprintf('%04d-%02d-%02d', $j[0], $j[1], $j[2]);
                }
            } else {
                return sprintf('%04d-%02d-%02d', $part1, $part2, $part3);
            }
        }

        // Try DD-MM-YYYY or DD/MM/YYYY or MM-DD-YYYY
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $dateStr, $m)) {
            $p1 = (int)$m[1];
            $p2 = (int)$m[2];
            $p3 = (int)$m[3];
            if (function_exists('gregorian_to_jalali')) {
                // If p1 > 12, p1 is day, p2 is month
                $month = ($p1 > 12) ? $p2 : $p1;
                $day = ($p1 > 12) ? $p1 : $p2;
                $j = gregorian_to_jalali($p3, $month, $day);
                return sprintf('%04d-%02d-%02d', $j[0], $j[1], $j[2]);
            }
        }

        // Parse textual string like "Jun 13, 2026"
        $ts = strtotime($dateStr);
        if ($ts) {
            if (function_exists('gregorian_to_jalali')) {
                $y = (int)date('Y', $ts);
                $m = (int)date('n', $ts);
                $d = (int)date('j', $ts);
                $j = gregorian_to_jalali($y, $m, $d);
                return sprintf('%04d-%02d-%02d', $j[0], $j[1], $j[2]);
            }
        }

        // Default fallback if is already a valid format
        return $dateStr;
    }

    /**
     * Standalone pure PHP parser for XLSX files.
     * Uses ZipArchive to inspect raw sheets without external dependencies.
     */
    public static function parseXlsx($filePath) {
        if (!class_exists('ZipArchive')) {
            throw new Exception("افزونه ZipArchive در پی‌اچ‌پی فعال نیست.");
        }
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== TRUE) {
            throw new Exception("قالب فایل XLSX نامعتبر یا آسیب دیده است.");
        }

        // Shared strings reader (index mappings)
        $sharedStrings = [];
        $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXml) {
            $xml = @simplexml_load_string($stringsXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } else if (isset($si->r)) {
                        $textParts = [];
                        foreach ($si->r as $r) {
                            if (isset($r->t)) {
                                $textParts[] = (string)$r->t;
                            }
                        }
                        $sharedStrings[] = implode('', $textParts);
                    }
                }
            }
        }

        // Parse sheet names from xl/workbook.xml to link sheet index to its human-readable name (e.g. "Queries", "Pages")
        $sheetNames = [];
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml) {
            preg_match_all('/<sheet\s+[^>]*name=["\']([^"\']+)["\']/i', $workbookXml, $matches);
            if (!empty($matches[1])) {
                $sheetNames = $matches[1];
            }
        }

        // Find all worksheet files inside the zip
        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/xl\/worksheets\/sheet(\d+)\.xml$/i', $name, $matches)) {
                $sheetIndex = (int)$matches[1];
                $sheets[$sheetIndex] = $name;
            }
        }
        ksort($sheets);

        $worksheetsData = [];
        $idx = 0;
        foreach ($sheets as $sheetIndex => $zipPath) {
            $sheetName = $sheetNames[$idx] ?? "Sheet " . $sheetIndex;
            $idx++;

            $sheetXml = $zip->getFromName($zipPath);
            if (!$sheetXml) continue;

            $xml = @simplexml_load_string($sheetXml);
            $rows = [];
            if ($xml && isset($xml->sheetData)) {
                foreach ($xml->sheetData->row as $row) {
                    $rowIndex = (int)$row['r'] - 1;
                    $rowData = [];
                    foreach ($row->c as $cell) {
                        $coord = (string)$cell['r'];
                        preg_match('/^[A-Z]+/', $coord, $matches);
                        $colStr = $matches[0] ?? '';
                        $colIndex = 0;
                        for ($k = 0; $k < strlen($colStr); $k++) {
                            $colIndex = $colIndex * 26 + (ord($colStr[$k]) - 64);
                        }
                        $colIndex = $colIndex - 1;

                        $type = (string)$cell['t'];
                        $val = (string)$cell->v;

                        if ($type === 's') {
                            $val = $sharedStrings[(int)$val] ?? '';
                        }
                        $rowData[$colIndex] = trim($val);
                    }

                    if (!empty($rowData)) {
                        $maxCol = max(array_keys($rowData));
                        for ($col = 0; $col <= $maxCol; $col++) {
                            if (!isset($rowData[$col])) {
                                $rowData[$col] = '';
                            }
                        }
                        ksort($rowData);
                        $rows[$rowIndex] = $rowData;
                    }
                }
            }

            if (!empty($rows)) {
                ksort($rows);
                $worksheetsData[$sheetName] = array_values($rows);
            }
        }

        $zip->close();

        // If there's only 1 sheet, or if calling code wants a flat layout, return just the first sheet's values
        // to maintain maximum backwards compatibility.
        if (count($worksheetsData) === 1) {
            return reset($worksheetsData);
        }

        return $worksheetsData;
    }

    /**
     * Standalone pure PHP parser for CSV/TSV files with dynamic delimiter detection.
     */
    public static function parseCsv($filePath) {
        $content = file_get_contents($filePath);
        if (!$content) {
            throw new Exception("محتوای فایل خالی است یا خوانده نشد.");
        }

        // Detect delimiters
        $delimiters = [",", "\t", ";", "|"];
        $detected = ",";
        $maxCount = 0;
        $lines = explode("\n", $content, 5);
        foreach ($lines as $line) {
            foreach ($delimiters as $delim) {
                $count = substr_count($line, $delim);
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $detected = $delim;
                }
            }
        }

        $rows = [];
        $handle = fopen($filePath, "r");
        if ($handle) {
            while (($data = fgetcsv($handle, 10000, $detected)) !== FALSE) {
                $purified = array_map(function($cell) {
                    return trim(preg_replace('/[\x{FEFF}\x{200B}]/u', '', $cell));
                }, $data);
                $rows[] = $purified;
            }
            fclose($handle);
        }
        return $rows;
    }

    /**
     * Parse files based on extension, supporting CSV, XLSX and JSON.
     */
    public static function parseFile($filePath, $extension) {
        $extension = strtolower(trim($extension));
        if ($extension === 'xlsx') {
            return self::parseXlsx($filePath);
        } elseif ($extension === 'csv' || $extension === 'tsv') {
            return self::parseCsv($filePath);
        } elseif ($extension === 'json') {
            $json = file_get_contents($filePath);
            $decoded = json_decode($json, true);
            if ($decoded === null) {
                throw new Exception("قالب محتوای JSON نامعتبر است.");
            }
            // If it is flat object list, convert to rows
            if (is_array($decoded) && !empty($decoded)) {
                $first = reset($decoded);
                if (is_array($first)) {
                    $headers = array_keys($first);
                    $matrix = [$headers];
                    foreach ($decoded as $obj) {
                        $row = [];
                        foreach ($headers as $h) {
                            $row[] = $obj[$h] ?? '';
                        }
                        $matrix[] = $row;
                    }
                    return $matrix;
                }
            }
            throw new Exception("ساختار لایه‌ای دیتای JSON متناسب با جدول ارزیابی نیست.");
        }
        throw new Exception("فرمت فایل مورد نظر پشتیبانی نمی‌شود.");
    }

    /**
     * Map headings dynamically and import data incrementally. Handles single sheet
     * or multi-sheet (e.g. from GSC direct export) arrays automatically.
     */
    public static function mapAndImport($projectId, $storeId, $rows, $originalFileName, $fileSize, $manualModuleType = null) {
        self::initTables();
        $conn = getConn();
        if (!$conn) {
            throw new Exception("عدم اتصال موفق به دیتابیس.");
        }

        if (empty($rows)) {
            throw new Exception("فایل بارگذاری شده فاقد اطلاعات یا خالی است.");
        }

        // Detect if this is a multi-sheet array
        $isMultiSheet = false;
        if (!empty($rows)) {
            $firstKey = array_key_first($rows);
            $firstVal = $rows[$firstKey];
            if (is_string($firstKey)) {
                $isMultiSheet = true;
            } elseif (is_array($firstVal)) {
                $subKey = array_key_first($firstVal);
                if (isset($firstVal[$subKey]) && is_array($firstVal[$subKey])) {
                    $isMultiSheet = true;
                }
            }
        }

        if ($isMultiSheet) {
            // Process ALL sheets and aggregate results
            $aggregatedResult = [
                'status' => 'success',
                'module_type' => 'search_console', // Default fallback
                'sub_type' => 'multi',
                'total_records' => 0,
                'new_records' => 0,
                'updated_records' => 0,
                'error_count' => 0,
                'error_details' => [],
                'file_version' => ''
            ];

            $successCount = 0;
            $moduleTypesDetected = [];

            foreach ($rows as $sheetName => $sheetRows) {
                if (empty($sheetRows) || count($sheetRows) < 2) continue; // skip small or empty sheets
                
                try {
                    // Call mapAndImportSingleSheet on this individual sheet
                    $sheetResult = self::mapAndImportSingleSheet($projectId, $storeId, $sheetRows, $originalFileName . " (" . $sheetName . ")", $fileSize, $manualModuleType);
                    
                    if ($sheetResult['status'] === 'success') {
                        $successCount++;
                    }
                    $aggregatedResult['total_records'] += $sheetResult['total_records'];
                    $aggregatedResult['new_records'] += $sheetResult['new_records'];
                    $aggregatedResult['updated_records'] += $sheetResult['updated_records'];
                    $aggregatedResult['error_count'] += $sheetResult['error_count'];
                    if (!empty($sheetResult['error_details'])) {
                        $aggregatedResult['error_details'] = array_merge($aggregatedResult['error_details'], $sheetResult['error_details']);
                    }
                    if (!empty($sheetResult['file_version'])) {
                        $aggregatedResult['file_version'] = $sheetResult['file_version'];
                    }
                    if (!empty($sheetResult['module_type'])) {
                        $moduleTypesDetected[] = $sheetResult['module_type'];
                    }
                } catch (Exception $sheetEx) {
                    // If a single sheet failed parsing (like Filters sheet because it has no headers/dimensions),
                    // we just log it or ignore if other sheets succeed.
                    if (strpos($sheetEx->getMessage(), 'ساختار فیلدها') === false) {
                        $aggregatedResult['error_details'][] = "برگ \"" . $sheetName . "\": " . $sheetEx->getMessage();
                        $aggregatedResult['error_count']++;
                    }
                }
            }

            if (!empty($moduleTypesDetected)) {
                // Pick the most common module type
                $counts = array_count_values($moduleTypesDetected);
                arsort($counts);
                $aggregatedResult['module_type'] = array_key_first($counts);
            }

            if ($successCount === 0 && $aggregatedResult['total_records'] === 0) {
                throw new Exception("هیچ‌کدام از صفحات فایل اکسل حاوی هدرها یا داده‌های منطبق بر ساختار سرچ کنسول یا آنالیتیکس نبودند.");
            }

            return $aggregatedResult;
        }

        return self::mapAndImportSingleSheet($projectId, $storeId, $rows, $originalFileName, $fileSize, $manualModuleType);
    }

    /**
     * Map headings dynamically and import data incrementally for a single sheet.
     */
    public static function mapAndImportSingleSheet($projectId, $storeId, $rows, $originalFileName, $fileSize, $manualModuleType = null) {
        self::initTables();
        $conn = getConn();
        if (!$conn) {
            throw new Exception("عدم اتصال موفق به دیتابیس.");
        }

        if (empty($rows)) {
            throw new Exception("فایل بارگذاری شده فاقد اطلاعات یا خالی است.");
        }

        // 1. Identify the header row
        $headerRowIndex = -1;
        $bestMatchCount = 0;
        $matchedColumnsMap = [];

        // Scan first 12 rows to find headings mapping
        for ($i = 0; $i < min(12, count($rows)); $i++) {
            $row = $rows[$i];
            if (empty($row) || count($row) < 2) continue;

            $matches = [];
            $matchCount = 0;

            foreach ($row as $colIdx => $colName) {
                $colNameClean = strtolower(trim(preg_replace('/[\xF0\x9F\x98\x80-\xF0\x9F\x99\x8F]/', '', $colName)));
                if (empty($colNameClean)) continue;

                foreach (self::$mapping_rules as $fieldKey => $synonyms) {
                    foreach ($synonyms as $syn) {
                        if (gettype($syn) === 'string' && (strpos($colNameClean, strtolower($syn)) !== false || strpos(strtolower($syn), $colNameClean) !== false)) {
                            $matches[$fieldKey] = $colIdx;
                            $matchCount++;
                            break;
                        }
                    }
                }
            }

            if ($matchCount > $bestMatchCount) {
                $bestMatchCount = $matchCount;
                $headerRowIndex = $i;
                $matchedColumnsMap = $matches;
            }
        }

        if ($headerRowIndex === -1 || count($matchedColumnsMap) < 2) {
            throw new Exception("سیستم قادر به شناسایی ساختار فیلدها و هدرهای ستون هماهنگ نبود. لطفاً از هدرهای استاندارد استفاده نمایید.");
        }

        // 2. Identify Module Type and Sub Type
        $moduleType = $manualModuleType;
        if (!$moduleType) {
            // Auto detect
            $scIndicators = ['query', 'clicks', 'impressions', 'position', 'ctr'];
            $scMatches = count(array_intersect($scIndicators, array_keys($matchedColumnsMap)));
            
            $gaIndicators = ['users', 'views', 'sessions', 'engaged_sessions', 'event_count', 'conversions', 'engagement_rate', 'average_engagement_time'];
            $gaMatches = count(array_intersect($gaIndicators, array_keys($matchedColumnsMap)));

            if ($scMatches >= $gaMatches && $scMatches > 0) {
                $moduleType = 'search_console';
            } elseif ($gaMatches > 0) {
                $moduleType = 'analytics';
            } else {
                throw new Exception("نوع فایل ارسالی (Google Analytics یا Search Console) شناسایی نشد.");
            }
        }

        // Identify sub type
        $subType = 'daily'; // Default
        if ($moduleType === 'search_console') {
            if (isset($matchedColumnsMap['query'])) {
                $subType = 'queries';
            } elseif (isset($matchedColumnsMap['page'])) {
                $subType = 'pages';
            } elseif (isset($matchedColumnsMap['device'])) {
                $subType = 'devices';
            } elseif (isset($matchedColumnsMap['country'])) {
                $subType = 'countries';
            }
        } else { // analytics
            if (isset($matchedColumnsMap['page'])) {
                $subType = 'pages';
            } elseif (isset($matchedColumnsMap['channel'])) {
                $subType = 'channels';
            }
        }

        // 3. Prepare data variables
        $dataRows = array_slice($rows, $headerRowIndex + 1);
        $totalRecords = count($dataRows);
        $newRecords = 0;
        $updatedRecords = 0;
        $errorCount = 0;
        $errorDetailsList = [];

        // Versioning details
        $backupDir = __DIR__ . '/../uploads/analytics_imports/';
        if (!file_exists($backupDir)) {
            @mkdir($backupDir, 0777, true);
        }
        $fileVersionName = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '', $originalFileName);
        $backupPath = $backupDir . $fileVersionName;

        // Verify key requirements
        if (!isset($matchedColumnsMap['date'])) {
            // If date is missing, let's assume today's date so we don't throw away useful data
            $matchedColumnsMap['date'] = -1; // Flag to use standard today date
        }

        // 4. Begin incremental ETL processing
        $conn->begin_transaction();
        try {
            foreach ($dataRows as $idx => $row) {
                // Skip empty or summary rows
                if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                    continue;
                }

                try {
                    // Extract date
                    $rawDate = '';
                    if ($matchedColumnsMap['date'] !== -1) {
                        $rawDate = $row[$matchedColumnsMap['date']] ?? '';
                    }
                    $normalizedDate = self::normalizeToJalali($rawDate);

                    if (empty($normalizedDate)) {
                        $normalizedDate = jdate('Y-m-d');
                    }

                    // Perform insertions depending on sub type
                    if ($moduleType === 'search_console') {
                        $clicks = (int)self::cleanNumber($row[$matchedColumnsMap['clicks'] ?? -1] ?? 0);
                        $impressions = (int)self::cleanNumber($row[$matchedColumnsMap['impressions'] ?? -1] ?? 0);
                        $ctr = (float)self::cleanNumber($row[$matchedColumnsMap['ctr'] ?? -1] ?? 0);
                        $position = (float)self::cleanNumber($row[$matchedColumnsMap['position'] ?? -1] ?? 0.0);

                        // If CTR is absolute like 0.05, make it percentage if other values suggest
                        if ($ctr > 0 && $ctr < 1 && $clicks > 0) {
                            $ctr = $ctr * 100;
                        }

                        if ($subType === 'queries') {
                            $queryVal = trim($row[$matchedColumnsMap['query']] ?? '');
                            if (empty($queryVal)) continue;

                            $stmt = $conn->prepare("SELECT id, clicks, impressions, ctr, position FROM search_console_queries WHERE project_id = ? AND store_id = ? AND date = ? AND query = ?");
                            $stmt->bind_param("ssss", $projectId, $storeId, $normalizedDate, $queryVal);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $existing = $res ? $res->fetch_assoc() : null;
                            $stmt->close();

                            if ($existing) {
                                if ((int)$existing['clicks'] !== $clicks || (int)$existing['impressions'] !== $impressions || abs((float)$existing['position'] - $position) > 0.01) {
                                    $stmtUp = $conn->prepare("UPDATE search_console_queries SET clicks = ?, impressions = ?, ctr = ?, position = ? WHERE id = ?");
                                    $stmtUp->bind_param("iiddi", $clicks, $impressions, $ctr, $position, $existing['id']);
                                    $stmtUp->execute();
                                    $stmtUp->close();
                                    $updatedRecords++;
                                }
                            } else {
                                $stmtIn = $conn->prepare("INSERT INTO search_console_queries (project_id, store_id, date, query, clicks, impressions, ctr, position, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $at = time();
                                $stmtIn->bind_param("ssssiiddi", $projectId, $storeId, $normalizedDate, $queryVal, $clicks, $impressions, $ctr, $position, $at);
                                $stmtIn->execute();
                                $stmtIn->close();
                                $newRecords++;
                            }

                        } elseif ($subType === 'pages') {
                            $pageVal = trim($row[$matchedColumnsMap['page']] ?? '');
                            if (empty($pageVal)) continue;

                            $stmt = $conn->prepare("SELECT id, clicks, impressions, ctr, position FROM search_console_pages WHERE project_id = ? AND store_id = ? AND date = ? AND page = ?");
                            $stmt->bind_param("ssss", $projectId, $storeId, $normalizedDate, $pageVal);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $existing = $res ? $res->fetch_assoc() : null;
                            $stmt->close();

                            if ($existing) {
                                if ((int)$existing['clicks'] !== $clicks || (int)$existing['impressions'] !== $impressions || abs((float)$existing['position'] - $position) > 0.01) {
                                    $stmtUp = $conn->prepare("UPDATE search_console_pages SET clicks = ?, impressions = ?, ctr = ?, position = ? WHERE id = ?");
                                    $stmtUp->bind_param("iiddi", $clicks, $impressions, $ctr, $position, $existing['id']);
                                    $stmtUp->execute();
                                    $stmtUp->close();
                                    $updatedRecords++;
                                }
                            } else {
                                $stmtIn = $conn->prepare("INSERT INTO search_console_pages (project_id, store_id, date, page, clicks, impressions, ctr, position, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $at = time();
                                $stmtIn->bind_param("ssssiiddi", $projectId, $storeId, $normalizedDate, $pageVal, $clicks, $impressions, $ctr, $position, $at);
                                $stmtIn->execute();
                                $stmtIn->close();
                                $newRecords++;
                            }

                        } elseif ($subType === 'devices') {
                            $deviceVal = trim($row[$matchedColumnsMap['device']] ?? 'desktop');
                            if (empty($deviceVal)) continue;

                            $stmt = $conn->prepare("SELECT id, clicks, impressions, ctr, position FROM search_console_devices WHERE project_id = ? AND store_id = ? AND date = ? AND device = ?");
                            $stmt->bind_param("ssss", $projectId, $storeId, $normalizedDate, $deviceVal);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $existing = $res ? $res->fetch_assoc() : null;
                            $stmt->close();

                            if ($existing) {
                                if ((int)$existing['clicks'] !== $clicks || (int)$existing['impressions'] !== $impressions || abs((float)$existing['position'] - $position) > 0.01) {
                                    $stmtUp = $conn->prepare("UPDATE search_console_devices SET clicks = ?, impressions = ?, ctr = ?, position = ? WHERE id = ?");
                                    $stmtUp->bind_param("iiddi", $clicks, $impressions, $ctr, $position, $existing['id']);
                                    $stmtUp->execute();
                                    $stmtUp->close();
                                    $updatedRecords++;
                                }
                            } else {
                                $stmtIn = $conn->prepare("INSERT INTO search_console_devices (project_id, store_id, date, device, clicks, impressions, ctr, position, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $at = time();
                                $stmtIn->bind_param("ssssiiddi", $projectId, $storeId, $normalizedDate, $deviceVal, $clicks, $impressions, $ctr, $position, $at);
                                $stmtIn->execute();
                                $stmtIn->close();
                                $newRecords++;
                            }

                        } elseif ($subType === 'countries') {
                            $countryVal = trim($row[$matchedColumnsMap['country']] ?? 'other');
                            if (empty($countryVal)) continue;

                            $stmt = $conn->prepare("SELECT id, clicks, impressions, ctr, position FROM search_console_countries WHERE project_id = ? AND store_id = ? AND date = ? AND country = ?");
                            $stmt->bind_param("ssss", $projectId, $storeId, $normalizedDate, $countryVal);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $existing = $res ? $res->fetch_assoc() : null;
                            $stmt->close();

                            if ($existing) {
                                if ((int)$existing['clicks'] !== $clicks || (int)$existing['impressions'] !== $impressions || abs((float)$existing['position'] - $position) > 0.01) {
                                    $stmtUp = $conn->prepare("UPDATE search_console_countries SET clicks = ?, impressions = ?, ctr = ?, position = ? WHERE id = ?");
                                    $stmtUp->bind_param("iiddi", $clicks, $impressions, $ctr, $position, $existing['id']);
                                    $stmtUp->execute();
                                    $stmtUp->close();
                                    $updatedRecords++;
                                }
                            } else {
                                $stmtIn = $conn->prepare("INSERT INTO search_console_countries (project_id, store_id, date, country, clicks, impressions, ctr, position, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $at = time();
                                $stmtIn->bind_param("ssssiiddi", $projectId, $storeId, $normalizedDate, $countryVal, $clicks, $impressions, $ctr, $position, $at);
                                $stmtIn->execute();
                                $stmtIn->close();
                                $newRecords++;
                            }
                        }

                    } else { // analytics
                        $users = (int)self::cleanNumber($row[$matchedColumnsMap['users'] ?? -1] ?? 0);
                        $newUsers = (int)self::cleanNumber($row[$matchedColumnsMap['new_users'] ?? -1] ?? 0);
                        $sessions = (int)self::cleanNumber($row[$matchedColumnsMap['sessions'] ?? -1] ?? 0);
                        $engagedSessions = (int)self::cleanNumber($row[$matchedColumnsMap['engaged_sessions'] ?? -1] ?? 0);
                        $views = (int)self::cleanNumber($row[$matchedColumnsMap['views'] ?? -1] ?? 0);
                        $eventCount = (int)self::cleanNumber($row[$matchedColumnsMap['event_count'] ?? -1] ?? 0);
                        $conversions = (int)self::cleanNumber($row[$matchedColumnsMap['conversions'] ?? -1] ?? 0);
                        $engagementRate = (float)self::cleanNumber($row[$matchedColumnsMap['engagement_rate'] ?? -1] ?? 0.0);
                        $avgEngagementTime = (float)self::cleanNumber($row[$matchedColumnsMap['average_engagement_time'] ?? -1] ?? 0.0);

                        // If Engagement rate is decimal like 0.65, scale to percent
                        if ($engagementRate > 0 && $engagementRate < 1) {
                            $engagementRate = $engagementRate * 100;
                        }

                        if ($subType === 'daily') {
                            $stmt = $conn->prepare("SELECT id, users, sessions, views FROM analytics_daily_stats WHERE project_id = ? AND store_id = ? AND date = ?");
                            $stmt->bind_param("sss", $projectId, $storeId, $normalizedDate);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $existing = $res ? $res->fetch_assoc() : null;
                            $stmt->close();

                            if ($existing) {
                                if ((int)$existing['users'] !== $users || (int)$existing['sessions'] !== $sessions || (int)$existing['views'] !== $views) {
                                    $stmtUp = $conn->prepare("UPDATE analytics_daily_stats SET users = ?, new_users = ?, sessions = ?, engaged_sessions = ?, views = ?, event_count = ?, conversions = ?, engagement_rate = ?, average_engagement_time = ? WHERE id = ?");
                                    $stmtUp->bind_param("iiiiiiiddi", $users, $newUsers, $sessions, $engagedSessions, $views, $eventCount, $conversions, $engagementRate, $avgEngagementTime, $existing['id']);
                                    $stmtUp->execute();
                                    $stmtUp->close();
                                    $updatedRecords++;
                                }
                            } else {
                                $stmtIn = $conn->prepare("INSERT INTO analytics_daily_stats (project_id, store_id, date, users, new_users, sessions, engaged_sessions, views, event_count, conversions, engagement_rate, average_engagement_time, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $at = time();
                                $stmtIn->bind_param("sssiiiiiiiddi", $projectId, $storeId, $normalizedDate, $users, $newUsers, $sessions, $engagedSessions, $views, $eventCount, $conversions, $engagementRate, $avgEngagementTime, $at);
                                $stmtIn->execute();
                                $stmtIn->close();
                                $newRecords++;
                            }

                        } elseif ($subType === 'pages') {
                            $pageVal = trim($row[$matchedColumnsMap['page']] ?? '');
                            if (empty($pageVal)) continue;

                            $stmt = $conn->prepare("SELECT id, views, users FROM analytics_pages WHERE project_id = ? AND store_id = ? AND date = ? AND page = ?");
                            $stmt->bind_param("ssss", $projectId, $storeId, $normalizedDate, $pageVal);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $existing = $res ? $res->fetch_assoc() : null;
                            $stmt->close();

                            if ($existing) {
                                if ((int)$existing['views'] !== $views || (int)$existing['users'] !== $users) {
                                    $stmtUp = $conn->prepare("UPDATE analytics_pages SET views = ?, users = ?, sessions = ?, event_count = ?, conversions = ? WHERE id = ?");
                                    $stmtUp->bind_param("iiiiii", $views, $users, $sessions, $eventCount, $conversions, $existing['id']);
                                    $stmtUp->execute();
                                    $stmtUp->close();
                                    $updatedRecords++;
                                }
                            } else {
                                $stmtIn = $conn->prepare("INSERT INTO analytics_pages (project_id, store_id, date, page, views, users, sessions, event_count, conversions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $at = time();
                                $stmtIn->bind_param("ssssiiiiii", $projectId, $storeId, $normalizedDate, $pageVal, $views, $users, $sessions, $eventCount, $conversions, $at);
                                $stmtIn->execute();
                                $stmtIn->close();
                                $newRecords++;
                            }

                        } elseif ($subType === 'channels') {
                            $chanVal = trim($row[$matchedColumnsMap['channel']] ?? 'Direct');
                            if (empty($chanVal)) continue;

                            $stmt = $conn->prepare("SELECT id, sessions, users FROM analytics_channels WHERE project_id = ? AND store_id = ? AND date = ? AND channel = ?");
                            $stmt->bind_param("ssss", $projectId, $storeId, $normalizedDate, $chanVal);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $existing = $res ? $res->fetch_assoc() : null;
                            $stmt->close();

                            if ($existing) {
                                if ((int)$existing['sessions'] !== $sessions || (int)$existing['users'] !== $users) {
                                    $stmtUp = $conn->prepare("UPDATE analytics_channels SET sessions = ?, users = ?, views = ?, conversions = ? WHERE id = ?");
                                    $stmtUp->bind_param("iiiii", $sessions, $users, $views, $conversions, $existing['id']);
                                    $stmtUp->execute();
                                    $stmtUp->close();
                                    $updatedRecords++;
                                }
                            } else {
                                $stmtIn = $conn->prepare("INSERT INTO analytics_channels (project_id, store_id, date, channel, sessions, users, views, conversions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $at = time();
                                $stmtIn->bind_param("ssssiiiiii", $projectId, $storeId, $normalizedDate, $chanVal, $sessions, $users, $views, $conversions, $at);
                                $stmtIn->execute();
                                $stmtIn->close();
                                $newRecords++;
                            }
                        }
                    }

                } catch (Exception $rowEx) {
                    $errorCount++;
                    $errorDetailsList[] = "ردیف " . ($idx + 2) . ": " . $rowEx->getMessage();
                    if ($errorCount >= 100) {
                        break; // Fail-safe limit logs
                    }
                }
            }

            // Sync with current simple analytics_history structure as well to support existing elements without friction
            self::syncToSimpleHistory($projectId, $storeId);

            $conn->commit();
        } catch (Exception $etlEx) {
            $conn->rollback();
            throw $etlEx;
        }

        // Save imported file version
        @copy($originalFileName, $backupPath);

        // 5. Save detailed Import Log
        $errorDetails = implode(" \n", $errorDetailsList);
        $status = ($errorCount === 0) ? 'success' : 'failed';
        if ($totalRecords > 0 && $errorCount < $totalRecords) {
            $status = 'success'; // Partial success
        }

        $stmtLog = $conn->prepare("INSERT INTO import_logs (project_id, store_id, file_name, file_size, file_type, module_type, sub_type, imported_at, status, total_records, new_records, updated_records, error_count, error_details, file_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $at = time();
        $ext = pathinfo($originalFileName, PATHINFO_EXTENSION);
        $stmtLog->bind_param("sssisssisiiiiss", $projectId, $storeId, $originalFileName, $fileSize, $ext, $moduleType, $subType, $at, $status, $totalRecords, $newRecords, $updatedRecords, $errorCount, $errorDetails, $fileVersionName);
        $stmtLog->execute();
        $stmtLog->close();

        return [
            'status' => $status,
            'module_type' => $moduleType,
            'sub_type' => $subType,
            'total_records' => $totalRecords,
            'new_records' => $newRecords,
            'updated_records' => $updatedRecords,
            'error_count' => $errorCount,
            'error_details' => $errorDetailsList,
            'file_version' => $fileVersionName
        ];
    }

    /**
     * Synchronize newly imported granular records into the old legacy `analytics_history` configuration array,
     * so that existing elements continue to display flawlessly without requiring any file rewrites.
     */
    public static function syncToSimpleHistory($projectId, $storeId) {
        $conn = getConn();
        if (!$conn) return;

        // Fetch aggregate daily metrics from our custom tables Group By date
        $history = [];
        
        // Let's read existing simple history if any
        $project = getCurrentProject($projectId);
        if (!$project) return;

        $store_index = -1;
        if (isset($project['settings']['stores']) && is_array($project['settings']['stores'])) {
            foreach ($project['settings']['stores'] as $idx => $st) {
                if ($st['id'] === $storeId) {
                    $store_index = $idx;
                    break;
                }
            }
        }
        if ($store_index === -1) return;

        $existingHistory = $project['settings']['stores'][$store_index]['analytics_history'] ?? [];
        $historyByDate = [];
        foreach ($existingHistory as $entry) {
            if (!empty($entry['date'])) {
                $historyByDate[$entry['date']] = $entry;
            }
        }

        // Fetch matching dates from our standard GA4 and GSC tables, combine them seamlessly
        $gscQuery = $conn->query("SELECT date, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as pos 
                                  FROM search_console_queries 
                                  WHERE project_id = '$projectId' AND store_id = '$storeId' 
                                  GROUP BY date");
        
        $gscPageQuery = $conn->query("SELECT date, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as pos 
                                      FROM search_console_pages 
                                      WHERE project_id = '$projectId' AND store_id = '$storeId' 
                                      GROUP BY date");

        // Merge GSC daily aggregates
        $gscAggs = [];
        if ($gscQuery) {
            while ($row = $gscQuery->fetch_assoc()) {
                $gscAggs[$row['date']] = $row;
            }
        }
        if ($gscPageQuery) {
            while ($row = $gscPageQuery->fetch_assoc()) {
                if (!isset($gscAggs[$row['date']])) {
                    $gscAggs[$row['date']] = $row;
                } else {
                    // Maximum of them or sum? Use page sum if query sum was zero
                    if ($gscAggs[$row['date']]['clicks'] < $row['clicks']) {
                        $gscAggs[$row['date']] = $row;
                    }
                }
            }
        }

        // Merging GA4 daily aggregates
        $gaQuery = $conn->query("SELECT date, SUM(users) as users, SUM(sessions) as sessions, SUM(views) as views, AVG(engagement_rate) as engagement 
                                 FROM analytics_daily_stats
                                 WHERE project_id = '$projectId' AND store_id = '$storeId' 
                                 GROUP BY date");

        $gaAggs = [];
        if ($gaQuery) {
            while ($row = $gaQuery->fetch_assoc()) {
                $gaAggs[$row['date']] = $row;
            }
        }

        // Build list of all dates
        $allDates = array_unique(array_merge(array_keys($gscAggs), array_keys($gaAggs)));
        
        foreach ($allDates as $dt) {
            $gsc = $gscAggs[$dt] ?? null;
            $ga = $gaAggs[$dt] ?? null;

            if (!isset($historyByDate[$dt])) {
                $historyByDate[$dt] = [
                    'date' => $dt,
                    'is_manual' => false,
                    'website' => [
                        'search_console_clicks' => 0,
                        'search_console_impressions' => 0,
                        'search_console_ctr' => 0.0,
                        'search_console_position' => 0.0,
                        'analytics_visits' => 0,
                        'analytics_users' => 0,
                        'analytics_sessions' => 0,
                        'analytics_engagement' => 0.0,
                        'analytics_conversion' => 0.0,
                        'pageviews' => 0
                    ]
                ];
            }

            if ($gsc) {
                $historyByDate[$dt]['website']['search_console_clicks'] = (int)$gsc['clicks'];
                $historyByDate[$dt]['website']['search_console_impressions'] = (int)$gsc['impressions'];
                $historyByDate[$dt]['website']['search_console_ctr'] = $gsc['impressions'] > 0 ? round(($gsc['clicks'] / $gsc['impressions']) * 100, 2) : 0;
                $historyByDate[$dt]['website']['search_console_position'] = round((float)$gsc['pos'], 2);
            }

            if ($ga) {
                $historyByDate[$dt]['website']['analytics_visits'] = (int)$ga['views'];
                $historyByDate[$dt]['website']['pageviews'] = (int)$ga['views'];
                $historyByDate[$dt]['website']['analytics_users'] = (int)$ga['users'];
                $historyByDate[$dt]['website']['analytics_sessions'] = (int)$ga['sessions'];
                $historyByDate[$dt]['website']['analytics_engagement'] = round((float)$ga['engagement'], 2);
            }
        }

        // Sort by date ascending
        $newHistory = array_values($historyByDate);
        usort($newHistory, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        // Save legacy setting array so other modules render instantly
        $project['settings']['stores'][$store_index]['analytics_history'] = $newHistory;
        saveProject($project);
    }
}
