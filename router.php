<?php
/**
 * Main Application Router for Built-in PHP Server and Reverse Proxy
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// 1. Direct Static File Check
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false; // Let PHP built-in server serve static assets (css, js, images, etc.)
}

// 1.1 Instagram OAuth 2.0 Start & Callback Routes
if (preg_match('#^/instagram/oauth/start(?:/.*)?$#', $uri)) {
    require __DIR__ . '/panel/instagram_oauth_start.php';
    return true;
}

if (preg_match('#^/callback/account/[iI]nstagram(?:/.*)?$#', $uri)) {
    require __DIR__ . '/panel/callback_instagram.php';
    return true;
}

// 2. VM Project Routes: /admin/projects/{slug}/vm ...
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

    require __DIR__ . '/panel/vm/index.php';
    return true;
}

// 2.1 Enterprise SEO & Analytics Routes:
// /admin/business/{slug}/seo ... or /admin/projects/{slug}/seo ...
if (preg_match('#^/admin/(?:business|projects)/([^/]+)/seo(?:/(.*))?$#', $uri, $matches)) {
    $_GET['business'] = $matches[1];
    $sub = trim($matches[2] ?? '', '/');

    if (!empty($sub)) {
        $_GET['view'] = $sub;
    } else {
        $_GET['view'] = $_GET['view'] ?? 'overview';
    }

    require __DIR__ . '/panel/seo_intel/index.php';
    return true;
}

// 2.1.1 Redirect shorthand /admin/business/{slug} to /admin/business/{slug}/seo
if (preg_match('#^/admin/business/([^/]+)/?$#', $uri, $matches)) {
    header('Location: /admin/business/' . urlencode($matches[1]) . '/seo');
    return true;
}

if ($uri === '/admin/business' || $uri === '/admin/business/') {
    header('Location: /admin/business/6a00feeee7669/seo');
    return true;
}

// 2.2 Direct SEO shortcuts: /admin/seo or /panel/seo
if (preg_match('#^/(?:admin|panel)/seo(?:/(.*))?$#', $uri, $matches)) {
    $sub = trim($matches[1] ?? '', '/');
    if (!empty($sub)) {
        $_GET['view'] = $sub;
    }
    require __DIR__ . '/panel/seo_intel/index.php';
    return true;
}

// 3. Admin shorthand route
if ($uri === '/admin' || $uri === '/admin/') {
    require __DIR__ . '/panel/admin.php';
    return true;
}

// 4. Directory index
if (is_dir($filePath) && file_exists($filePath . '/index.php') && $uri !== '/') {
    require $filePath . '/index.php';
    return true;
}

// 5. Default
return false;
