<?php
/**
 * InstagramAdapter.php
 * Modular Instagram Channel Adapter for Instagram Graph API & OAuth 2.0
 */

class InstagramAdapter {

    const OAUTH_AUTHORIZE_URL = "https://www.instagram.com/oauth/authorize";
    const OAUTH_TOKEN_URL = "https://api.instagram.com/oauth/access_token";
    const GRAPH_EXCHANGE_URL = "https://graph.instagram.com/access_token";
    const GRAPH_BASE_URL = "https://graph.facebook.com/v19.0";
    const GRAPH_IG_BASE_URL = "https://graph.instagram.com/v19.0";

    const SCOPES = [
        'business_basic',
        'business_content_publish',
        'business_manage_comments',
        'business_manage_messages'
    ];

    /**
     * Get OAuth Configuration from Environment or Admin Settings
     */
    public static function getOAuthConfig($project = null) {
        if (!$project && function_exists('getCurrentProject')) {
            $project = getCurrentProject();
        }

        // 1. Check Environment Variables (getenv, $_ENV, $_SERVER)
        $envClientId = getenv('INSTAGRAM_CLIENT_ID') ?: ($_ENV['INSTAGRAM_CLIENT_ID'] ?? ($_SERVER['INSTAGRAM_CLIENT_ID'] ?? ''));
        $envClientSecret = getenv('INSTAGRAM_CLIENT_SECRET') ?: ($_ENV['INSTAGRAM_CLIENT_SECRET'] ?? ($_SERVER['INSTAGRAM_CLIENT_SECRET'] ?? ''));
        $envRedirectUri = getenv('INSTAGRAM_REDIRECT_URI') ?: ($_ENV['INSTAGRAM_REDIRECT_URI'] ?? ($_SERVER['INSTAGRAM_REDIRECT_URI'] ?? ''));

        // Check .env files directly if not populated in environment
        if (empty($envClientId) || empty($envClientSecret)) {
            $possibleEnvFiles = [
                __DIR__ . '/../../.env',
                __DIR__ . '/../.env',
                dirname(__DIR__, 2) . '/.env',
                getcwd() . '/.env'
            ];
            foreach ($possibleEnvFiles as $envFile) {
                if (file_exists($envFile) && is_readable($envFile)) {
                    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line) || $line[0] === '#') continue;
                        if (strpos($line, '=') !== false) {
                            list($k, $v) = explode('=', $line, 2);
                            $k = trim($k);
                            $v = trim($v, " \t\n\r\0\x0B\"'");
                            if ($k === 'INSTAGRAM_CLIENT_ID' && empty($envClientId)) $envClientId = $v;
                            if ($k === 'INSTAGRAM_CLIENT_SECRET' && empty($envClientSecret)) $envClientSecret = $v;
                            if ($k === 'INSTAGRAM_REDIRECT_URI' && empty($envRedirectUri)) $envRedirectUri = $v;
                        }
                    }
                    if (!empty($envClientId) && !empty($envClientSecret)) break;
                }
            }
        }

        // 2. Check Admin / Project Settings (Settings -> Social API Configuration)
        $settingsIg = $project['settings']['connections']['instagram'] ?? [];
        $adminClientId = $settingsIg['client_id'] ?? ($settingsIg['app_id'] ?? '');
        $adminClientSecret = $settingsIg['client_secret'] ?? ($settingsIg['app_secret'] ?? '');
        $adminRedirectUri = $settingsIg['redirect_uri'] ?? '';

        if (empty($adminClientId) && function_exists('getProjects')) {
            $allProjects = getProjects();
            foreach ($allProjects as $p) {
                $pIg = $p['settings']['connections']['instagram'] ?? [];
                if (!empty($pIg['client_id']) || !empty($pIg['app_id'])) {
                    $adminClientId = $pIg['client_id'] ?? ($pIg['app_id'] ?? '');
                    if (empty($adminClientSecret)) {
                        $adminClientSecret = $pIg['client_secret'] ?? ($pIg['app_secret'] ?? '');
                    }
                    if (empty($adminRedirectUri)) {
                        $adminRedirectUri = $pIg['redirect_uri'] ?? '';
                    }
                    break;
                }
            }
        }

        $clientId = !empty($envClientId) ? trim($envClientId) : trim($adminClientId);
        $clientSecret = !empty($envClientSecret) ? trim($envClientSecret) : trim($adminClientSecret);

        // 3. Determine Redirect URI
        if (!empty($envRedirectUri)) {
            $redirectUri = trim($envRedirectUri);
        } elseif (!empty($adminRedirectUri)) {
            $redirectUri = trim($adminRedirectUri);
        } else {
            $appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ($_SERVER['APP_URL'] ?? ''));
            if (!empty($appUrl)) {
                $redirectUri = rtrim($appUrl, '/') . '/callback/account/Instagram';
            } else {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
                $redirectUri = $protocol . '://' . $host . '/callback/account/Instagram';
            }
        }

        $source = !empty($envClientId) ? 'environment' : (!empty($adminClientId) ? 'settings' : 'none');

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'is_configured' => (!empty($clientId) && !empty($clientSecret)),
            'has_client_id' => !empty($clientId),
            'has_client_secret' => !empty($clientSecret),
            'source' => $source
        ];
    }

    /**
     * Generate the OAuth 2.0 Authorization URL
     */
    public static function getAuthUrl($clientId, $redirectUri, $state = '') {
        $scopesStr = implode(',', self::SCOPES);
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scopesStr,
            'force_authentication' => '1',
            'enable_fb_login' => '0'
        ];
        if (!empty($state)) {
            $params['state'] = $state;
        }
        return self::OAUTH_AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * Connect: Exchange OAuth authorization code for Access Token and Account profile
     * Returns array with tokens and account information
     */
    public function connect($code, $clientId, $clientSecret, $redirectUri, $proxy = null) {
        if (empty($code)) {
            return [
                'ok' => false,
                'error_type' => 'invalid_request',
                'error' => 'کد دسترسی (Authorization Code) معتبر نیست.'
            ];
        }

        // 1. Exchange authorization code for short-lived access token
        $tokenPostData = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code
        ];

        $ch = curl_init(self::OAUTH_TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenPostData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if (function_exists('applyCurlProxySettings') && $proxy) {
            applyCurlProxySettings($ch, $proxy);
        }

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return [
                'ok' => false,
                'error_type' => 'network_error',
                'error' => "خطای شبکه هنگام ارتباط با سرور OAuth: $curlErr"
            ];
        }

        $tokenData = json_decode($res, true);
        if ($httpCode !== 200 || !isset($tokenData['access_token'])) {
            $errMsg = $tokenData['error_message'] ?? ($tokenData['error']['message'] ?? "خطا در تبادل کد با توکن (HTTP $httpCode)");
            return [
                'ok' => false,
                'error_type' => 'oauth_exchange_failed',
                'error' => $errMsg
            ];
        }

        $shortLivedToken = $tokenData['access_token'];
        $userId = $tokenData['user_id'] ?? '';

        // 2. Exchange for Long-Lived Token (60 days)
        $exchangeParams = [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $clientSecret,
            'access_token' => $shortLivedToken
        ];
        $exchangeUrl = self::GRAPH_EXCHANGE_URL . '?' . http_build_query($exchangeParams);

        $ch = curl_init($exchangeUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if (function_exists('applyCurlProxySettings') && $proxy) {
            applyCurlProxySettings($ch, $proxy);
        }

        $resEx = curl_exec($ch);
        $httpCodeEx = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $longLivedToken = $shortLivedToken;
        $expiresIn = 5184000; // 60 days default
        if ($httpCodeEx === 200) {
            $exData = json_decode($resEx, true);
            if (!empty($exData['access_token'])) {
                $longLivedToken = $exData['access_token'];
                $expiresIn = (int)($exData['expires_in'] ?? 5184000);
            }
        }

        // 3. Get Account Profile details (Username & Account ID)
        $profile = $this->getProfile($longLivedToken, $userId, $proxy);
        $igAccountId = !empty($profile['id']) ? $profile['id'] : $userId;
        $username = $profile['username'] ?? ('ig_user_' . substr($igAccountId, -4));

        return [
            'ok' => true,
            'access_token' => $longLivedToken,
            'instagram_account_id' => $igAccountId,
            'username' => $username,
            'account_type' => $profile['account_type'] ?? 'BUSINESS',
            'token_expiry' => time() + $expiresIn,
            'expires_in' => $expiresIn,
            'connection_status' => 'connected',
            'connected_at' => time()
        ];
    }

    /**
     * Fetch user profile from Instagram Graph API
     */
    public function getProfile($accessToken, $userId = 'me', $proxy = null) {
        $url = self::GRAPH_IG_BASE_URL . "/{$userId}?fields=id,username,account_type&access_token=" . urlencode($accessToken);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if (function_exists('applyCurlProxySettings') && $proxy) {
            applyCurlProxySettings($ch, $proxy);
        }
        $res = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res, true);
        if (is_array($data) && !empty($data['id'])) {
            return $data;
        }

        // Fallback to me on Graph Facebook
        $urlFb = self::GRAPH_BASE_URL . "/me?fields=id,name&access_token=" . urlencode($accessToken);
        $chFb = curl_init($urlFb);
        curl_setopt($chFb, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chFb, CURLOPT_TIMEOUT, 20);
        curl_setopt($chFb, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chFb, CURLOPT_SSL_VERIFYHOST, 0);
        if (function_exists('applyCurlProxySettings') && $proxy) {
            applyCurlProxySettings($chFb, $proxy);
        }
        $resFb = curl_exec($chFb);
        curl_close($chFb);
        $dataFb = json_decode($resFb, true);

        return [
            'id' => $dataFb['id'] ?? $userId,
            'username' => $dataFb['name'] ?? ('user_' . $userId),
            'account_type' => 'BUSINESS'
        ];
    }

    /**
     * Disconnect: Reset Instagram credentials and mark as disconnected
     */
    public function disconnect(&$project) {
        if (!isset($project['settings']['connections']['instagram'])) {
            $project['settings']['connections']['instagram'] = [];
        }
        $project['settings']['connections']['instagram']['connection_status'] = 'disconnected';
        $project['settings']['connections']['instagram']['access_token'] = '';
        $project['settings']['connections']['instagram']['token'] = '';
        $project['settings']['connections']['instagram']['disconnected_at'] = time();

        if (function_exists('saveProject')) {
            saveProject($project);
        }
        return ['ok' => true];
    }

    /**
     * Publish content to Instagram Graph API
     * Supports:
     * - Image Post (Single Image)
     * - Carousel (Multiple Images)
     * - Reel (Video)
     */
    public function publish($contentItem, $settings) {
        $conn = $settings['connections']['instagram'] ?? [];
        $token = $conn['access_token'] ?? ($conn['token'] ?? '');
        $accountId = $conn['instagram_account_id'] ?? ($conn['chatId'] ?? '');
        $proxy = $conn['proxy'] ?? null;

        // Check token and account ID
        if (empty($token) || empty($accountId)) {
            return [
                'ok' => false,
                'error_type' => 'unauthorized',
                'error' => 'اکانت اینستاگرام متصل نیست یا توکن دسترسی خالی است. لطفا در بخش تنظیمات اکانت خود را متصل کنید.'
            ];
        }

        // Check token expiry
        $expiry = (int)($conn['token_expiry'] ?? 0);
        if ($expiry > 0 && $expiry < time()) {
            return [
                'ok' => false,
                'error_type' => 'expired_token',
                'error' => 'Expired Token: توکن اتصال اینستاگرام منقضی شده است. لطفا روی اتصال مجدد کلیک کنید.'
            ];
        }

        // Determine Post Type: 'image', 'carousel', 'reel'
        $postType = $contentItem['post_type'] ?? 'image';
        $caption = !empty($contentItem['instagram_caption']) ? $contentItem['instagram_caption'] : ($contentItem['caption'] ?? '');
        $mediaUrl = $contentItem['media_url'] ?? ($contentItem['mediaUrl'] ?? '');

        // Resolve media URLs
        $imagesList = [];
        if (!empty($mediaUrl)) {
            $cleanMedia = $mediaUrl;
            if (strpos($cleanMedia, '|') !== false) {
                list($cleanMedia, ) = explode('|', $cleanMedia, 2);
            }
            $imagesList = array_filter(array_map('trim', explode(',', $cleanMedia)));
        }

        // If multiple images are provided and post_type is image, automatically treat as carousel
        if (count($imagesList) > 1 && $postType !== 'reel') {
            $postType = 'carousel';
        }

        // Base API Host (supports custom API proxy or worker)
        $apiHost = "https://graph.facebook.com/v19.0";
        $method = $proxy['bypass_method'] ?? '';
        if (($method === 'custom_api' || $method === 'hybrid' || empty($method)) && !empty($proxy['custom_api_url'])) {
            $apiHost = rtrim($proxy['custom_api_url'], '/');
            if (strpos($apiHost, '/v19.0') === false && strpos($apiHost, 'workers.dev') === false) {
                $apiHost .= '/v19.0';
            }
        }

        // STEP 1: Create Media Container
        $containerId = null;

        if ($postType === 'reel') {
            // REELS CONTAINER
            $videoUrl = !empty($imagesList[0]) ? $imagesList[0] : $mediaUrl;
            if (empty($videoUrl)) {
                return [
                    'ok' => false,
                    'error_type' => 'invalid_media',
                    'error' => 'Invalid Media: آدرس فایل ویدیو برای انتشار ریلز خالی است.'
                ];
            }
            $videoUrl = function_exists('getFullUrl') ? getFullUrl($videoUrl) : $videoUrl;

            $containerParams = [
                'media_type' => 'REELS',
                'video_url' => $videoUrl,
                'caption' => $caption,
                'share_to_feed' => 'true',
                'access_token' => $token
            ];

            $resContainer = $this->sendApiRequest("{$apiHost}/{$accountId}/media", $containerParams, $proxy);
            if (!$resContainer['ok']) {
                return $resContainer;
            }
            $containerId = $resContainer['data']['id'] ?? null;

        } elseif ($postType === 'carousel' && count($imagesList) > 1) {
            // CAROUSEL CONTAINER
            $childContainerIds = [];
            foreach ($imagesList as $childImg) {
                $fullChildUrl = function_exists('getFullUrl') ? getFullUrl($childImg) : $childImg;
                $childParams = [
                    'image_url' => $fullChildUrl,
                    'is_carousel_item' => 'true',
                    'access_token' => $token
                ];
                $resChild = $this->sendApiRequest("{$apiHost}/{$accountId}/media", $childParams, $proxy);
                if (!$resChild['ok']) {
                    return [
                        'ok' => false,
                        'error_type' => $resChild['error_type'],
                        'error' => 'خطا در ایجاد اسلاید کاروسل: ' . $resChild['error']
                    ];
                }
                $childContainerIds[] = $resChild['data']['id'];
                usleep(500000); // 0.5s pause between container items
            }

            // Create Parent Carousel Container
            $parentParams = [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $childContainerIds),
                'caption' => $caption,
                'access_token' => $token
            ];
            $resParent = $this->sendApiRequest("{$apiHost}/{$accountId}/media", $parentParams, $proxy);
            if (!$resParent['ok']) {
                return $resParent;
            }
            $containerId = $resParent['data']['id'] ?? null;

        } else {
            // SINGLE IMAGE CONTAINER
            $singleImg = !empty($imagesList[0]) ? $imagesList[0] : $mediaUrl;
            if (empty($singleImg)) {
                $singleImg = "https://placehold.co/1080x1080?text=Instagram+Publish";
            }
            $fullImgUrl = function_exists('getFullUrl') ? getFullUrl($singleImg) : $singleImg;

            $containerParams = [
                'image_url' => $fullImgUrl,
                'caption' => $caption,
                'access_token' => $token
            ];
            $resContainer = $this->sendApiRequest("{$apiHost}/{$accountId}/media", $containerParams, $proxy);
            if (!$resContainer['ok']) {
                return $resContainer;
            }
            $containerId = $resContainer['data']['id'] ?? null;
        }

        if (empty($containerId)) {
            return [
                'ok' => false,
                'error_type' => 'container_failed',
                'error' => 'شناسه Container دریافت نشد.'
            ];
        }

        // Allow Graph API background processing for media (especially video / reel / carousel)
        sleep(2);

        // STEP 2: Publish Container
        $publishParams = [
            'creation_id' => $containerId,
            'access_token' => $token
        ];
        $resPublish = $this->sendApiRequest("{$apiHost}/{$accountId}/media_publish", $publishParams, $proxy);
        if (!$resPublish['ok']) {
            return $resPublish;
        }

        $publishedMediaId = $resPublish['data']['id'] ?? $containerId;
        return [
            'ok' => true,
            'media_id' => $publishedMediaId,
            'container_id' => $containerId,
            'status' => 'published',
            'published_at' => time()
        ];
    }

    /**
     * Schedule a post: If Graph API scheduled_publish_time is requested or queues it
     */
    public function schedule($contentItem, $settings) {
        $timestamp = (int)($contentItem['scheduledAt'] ?? ($contentItem['scheduled_at'] ?? 0));
        if ($timestamp <= time() + 300) {
            // If schedule time is less than 5 minutes away, execute immediate publish
            return $this->publish($contentItem, $settings);
        }

        return [
            'ok' => true,
            'status' => 'scheduled',
            'scheduled_at' => $timestamp
        ];
    }

    /**
     * Get published media status or container status
     */
    public function getStatus($mediaId = null, $settings = null) {
        if (empty($mediaId)) {
            return ['ok' => false, 'error' => 'Media ID is required'];
        }

        $conn = $settings['connections']['instagram'] ?? [];
        $token = $conn['access_token'] ?? ($conn['token'] ?? '');
        $proxy = $conn['proxy'] ?? null;

        if (empty($token)) {
            return ['ok' => false, 'error' => 'Token not found'];
        }

        $url = self::GRAPH_BASE_URL . "/{$mediaId}?fields=id,status_code,status&access_token=" . urlencode($token);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if (function_exists('applyCurlProxySettings') && $proxy) {
            applyCurlProxySettings($ch, $proxy);
        }
        $res = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res, true);
        return [
            'ok' => true,
            'data' => $data
        ];
    }

    /**
     * Make GET request to Graph API
     */
    public function makeGetRequest($url, $proxy = null) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if (function_exists('applyCurlProxySettings') && $proxy) {
            applyCurlProxySettings($ch, $proxy);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return [
                'ok' => false,
                'error' => "Network Error: $curlErr"
            ];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && is_array($data) && !isset($data['error'])) {
            return [
                'ok' => true,
                'data' => $data
            ];
        }

        $errMsg = $data['error']['message'] ?? "Instagram API error (HTTP $httpCode)";
        return [
            'ok' => false,
            'error' => $errMsg,
            'raw' => $data
        ];
    }

    /**
     * Helper to send API request to Graph API with robust error classification
     */
    protected function sendApiRequest($url, $postFields, $proxy = null) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if (function_exists('applyCurlProxySettings') && $proxy) {
            applyCurlProxySettings($ch, $proxy);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return [
                'ok' => false,
                'error_type' => 'network_error',
                'error' => "Network Error: خطا در برقراری ارتباط شبکه با سرور ($curlErr)"
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode === 200 || $httpCode === 201) {
            if (isset($data['id'])) {
                return ['ok' => true, 'data' => $data];
            }
        }

        // Categorize Graph API Errors
        $errObj = $data['error'] ?? [];
        $code = (int)($errObj['code'] ?? 0);
        $subcode = (int)($errObj['error_subcode'] ?? 0);
        $msg = $errObj['message'] ?? ("خطای نامشخص اینستاگرام (HTTP $httpCode)");

        $errorType = 'api_error';
        $userFriendlyMsg = $msg;

        if ($code === 190 || $subcode === 463 || $subcode === 467) {
            $errorType = 'expired_token';
            $userFriendlyMsg = "Expired Token (انقضای توکن اینستاگرام): توکن اتصال نامعتبر یا منقضی شده است. لطفا در تب تنظیمات مجدداً متصل شوید.";
        } elseif ($code === 200 || $code === 10 || strpos(strtolower($msg), 'permission') !== false) {
            $errorType = 'permission_error';
            $userFriendlyMsg = "Permission Error (خطای دسترسی): پرمیشن‌های لازم مانند business_content_publish به برنامه داده نشده است.";
        } elseif ($code === 36003 || strpos(strtolower($msg), 'aspect ratio') !== false || strpos(strtolower($msg), 'media') !== false) {
            $errorType = 'invalid_media';
            $userFriendlyMsg = "Invalid Media (ابعاد یا فرمت نامعتبر): نسبت تصویر یا مشخصات ویدیو با استانداردهای اینستاگرام همخوانی ندارد ($msg).";
        } else {
            $userFriendlyMsg = "API Error (خطای وب‌سرویس): $msg (Code: $code, Subcode: $subcode)";
        }

        return [
            'ok' => false,
            'error_type' => $errorType,
            'error' => $userFriendlyMsg,
            'raw_error' => $errObj
        ];
    }

    /**
     * Test connection with existing Access Token and Account ID
     */
    public function testConnection($accessToken, $accountId, $proxy = null) {
        if (empty($accessToken) || empty($accountId)) {
            return [
                'ok' => false,
                'error' => 'توکن دسترسی یا شناسه اکانت اینستاگرام تنظیم نشده است.'
            ];
        }

        $url = self::GRAPH_BASE_URL . "/$accountId?fields=id,username,name&access_token=" . urlencode($accessToken);
        $res = $this->makeGetRequest($url, $proxy);

        if ($res['ok']) {
            return [
                'ok' => true,
                'account_id' => $res['data']['id'] ?? $accountId,
                'username' => $res['data']['username'] ?? $accountId,
                'name' => $res['data']['name'] ?? ''
            ];
        }

        // Try Instagram Graph API /me endpoint as fallback
        $url2 = self::GRAPH_IG_BASE_URL . "/me?fields=id,username,account_type&access_token=" . urlencode($accessToken);
        $res2 = $this->makeGetRequest($url2, $proxy);
        if ($res2['ok']) {
            return [
                'ok' => true,
                'account_id' => $res2['data']['id'] ?? $accountId,
                'username' => $res2['data']['username'] ?? $accountId,
                'name' => $res2['data']['username'] ?? ''
            ];
        }

        return [
            'ok' => false,
            'error' => $res['error'] ?? 'برقراری ارتباط با اکانت اینستاگرام با شکست مواجه شد.'
        ];
    }
}
