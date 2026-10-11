<?php
/**
 * Direct Path Routing for /admin/business/{business_slug}/seo[/{view}]
 *
 * Handles Apache, LiteSpeed, Nginx try_files and direct directory execution for:
 * - /admin/business/{slug}/seo
 * - /admin/business/{slug}/seo/{view} (e.g. technical, integrations, keywords, pages, etc.)
 * - /admin/business/{slug} (auto-redirects to /seo)
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// 1. Match /admin/business/{slug}/seo or /admin/business/{slug}/seo/{view}
if (preg_match('#^/admin/business/([^/]+)/seo(?:/(.*))?$#', $uri, $matches)) {
    $_GET['business'] = $matches[1];
    $sub = trim($matches[2] ?? '', '/');

    if (!empty($sub)) {
        $_GET['view'] = $sub;
    } else {
        $_GET['view'] = $_GET['view'] ?? 'overview';
    }

    require_once __DIR__ . '/../../panel/seo_intel/index.php';
    exit;
}

// 2. Direct /admin/business/{slug} without /seo (redirect to /seo)
if (preg_match('#^/admin/business/([^/]+)/?$#', $uri, $matches)) {
    header('Location: /admin/business/' . urlencode($matches[1]) . '/seo');
    exit;
}

// 3. Fallback to admin panel
header('Location: /panel/admin.php');
exit;
