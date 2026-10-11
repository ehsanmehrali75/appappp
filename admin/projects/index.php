<?php
/**
 * Direct Path Routing for:
 * - /admin/projects/{project-slug}/vm
 * - /admin/projects/{project-slug}/seo
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// 1. Enterprise SEO Route: /admin/projects/{slug}/seo[/{view}]
if (preg_match('#^/admin/projects/([^/]+)/seo(?:/(.*))?$#', $uri, $matches)) {
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

// 2. VM Intelligence Route: /admin/projects/{slug}/vm[/{view}]
if (preg_match('#^/admin/projects/([^/]+)/vm(?:/(.*))?$#', $uri, $matches)) {
    $_GET['project_slug'] = $matches[1];
    $sub = trim($matches[2] ?? '', '/');
    if (!empty($sub)) {
        if (preg_match('#^stores/([^/]+)$#', $sub, $sMatch)) {
            $_GET['view'] = 'store';
            $_GET['store_id'] = $sMatch[1];
        } else {
            $_GET['view'] = $sub;
        }
    } else {
        $_GET['view'] = $_GET['view'] ?? 'overview';
    }
    require_once __DIR__ . '/../../panel/vm/index.php';
    exit;
}

header('Location: /panel/admin.php');
exit;
