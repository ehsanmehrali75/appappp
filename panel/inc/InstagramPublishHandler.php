<?php
/**
 * InstagramPublishHandler.php
 * Handles publishing cycle, retry logic, error recording, and database status updates for Instagram posts
 */

require_once __DIR__ . '/InstagramAdapter.php';

class InstagramPublishHandler {

    /**
     * Process an Instagram schedule item for publication
     * 
     * @param array $scheduleItem The schedule task record
     * @param array $project Reference to project state
     * @param array|null $product Optional product details
     * @return array Result summary with status and media details
     */
    public static function handle($scheduleItem, &$project, $product = null) {
        $adapter = new InstagramAdapter();
        $sid = $scheduleItem['id'] ?? '';
        $pid = $scheduleItem['productId'] ?? ($scheduleItem['product_id'] ?? '');

        // Resolve product if not provided
        if (!$product && !empty($pid) && !empty($project['products'])) {
            foreach ($project['products'] as &$p) {
                if ($p['id'] == $pid) {
                    $product = &$p;
                    break;
                }
            }
        }

        // Determine media URL
        $mediaUrl = $scheduleItem['mediaUrl'] ?? ($scheduleItem['media_url'] ?? '');
        if (empty($mediaUrl) && $product) {
            $mediaUrl = !empty($product['social_image']) ? $product['social_image'] : ($product['image'] ?? '');
        }

        // Determine post type
        $postType = $scheduleItem['post_type'] ?? 'image';
        if (empty($postType)) {
            // Check if multiple images or video
            if (strpos($mediaUrl, ',') !== false) {
                $postType = 'carousel';
            } elseif (preg_match('/\.(mp4|mov|avi)$/i', $mediaUrl)) {
                $postType = 'reel';
            } else {
                $postType = 'image';
            }
        }

        // Determine Caption
        $caption = $scheduleItem['instagram_caption'] ?? ($scheduleItem['caption'] ?? '');
        if (empty($caption) && $product) {
            $template = $project['settings']['platform_captions']['instagram'] ?? '';
            $finalLink = function_exists('getUtmLink') ? getUtmLink($product, 'instagram') : '';
            $defaultTemplate = "{LINK}";
            $linkTemplate = $project['settings']['platform_link_templates']['instagram'] ?? $defaultTemplate;
            $buyLink = str_replace('{LINK}', $finalLink, $linkTemplate);

            if (empty($template)) {
                $cleanName = str_replace(['*', '_', '`', '['], '', $product['name'] ?? '');
                $caption = "🌟 " . $cleanName . "\n💰 قیمت: " . ($product['price'] ?? '---') . "\n🔗 " . $finalLink;
            } else {
                $caption = function_exists('compileTemplatePHP') ? compileTemplatePHP($template, $product, 'instagram', $finalLink, $buyLink) : $template;
            }
        }

        // Update status to 'publishing'
        $scheduleItem['status'] = 'publishing';
        $scheduleItem['publish_status'] = 'publishing';
        self::updateScheduleInProjectAndDb($scheduleItem, $project);

        // Prepare Content Item for Adapter
        $contentItem = [
            'id' => $sid,
            'post_type' => $postType,
            'caption' => $caption,
            'instagram_caption' => $caption,
            'media_url' => $mediaUrl,
            'mediaUrl' => $mediaUrl,
            'scheduledAt' => $scheduleItem['scheduledAt'] ?? time()
        ];

        // Execute Publish via Adapter
        $res = $adapter->publish($contentItem, $project['settings']);

        if ($res['ok']) {
            // SUCCESS
            $scheduleItem['status'] = 'completed';
            $scheduleItem['publish_status'] = 'published';
            $scheduleItem['instagram_media_id'] = $res['media_id'] ?? '';
            $scheduleItem['error_log'] = '';
            $scheduleItem['published_at'] = time();

            // Store target Instagram account ID on record
            $igAccountId = $project['settings']['connections']['instagram']['instagram_account_id'] ?? ($project['settings']['connections']['instagram']['chatId'] ?? '');
            $scheduleItem['instagram_account_id'] = $igAccountId;

            // Increment product statistics
            if ($product) {
                if (!isset($product['platformsSent']) || !is_array($product['platformsSent'])) {
                    $product['platformsSent'] = ['telegram' => 0, 'bale' => 0, 'rubika' => 0, 'instagram' => 0];
                }
                $product['platformsSent']['instagram'] = ($product['platformsSent']['instagram'] ?? 0) + 1;
                $product['total_sent_count'] = ($product['total_sent_count'] ?? 0) + 1;
            }

            // Append to project activity log
            $prodName = $product['name'] ?? ($scheduleItem['productName'] ?? 'پست اینستاگرام');
            $project['logs'][] = [
                'at' => time(),
                'type' => 'ارسال اینستاگرام Graph API',
                'productName' => $prodName,
                'platform' => 'instagram',
                'post_type' => $postType,
                'media_id' => $scheduleItem['instagram_media_id']
            ];

            self::updateScheduleInProjectAndDb($scheduleItem, $project);

            return [
                'ok' => true,
                'status' => 'published',
                'media_id' => $scheduleItem['instagram_media_id'],
                'message' => 'پست با موفقیت در اینستاگرام منتشر شد.'
            ];
        } else {
            // FAILED
            $errorMsg = $res['error'] ?? 'خطای نامشخص در انتشار اینستاگرام';
            $errorType = $res['error_type'] ?? 'unknown_error';

            $scheduleItem['status'] = 'failed';
            $scheduleItem['publish_status'] = 'failed';
            $scheduleItem['error_log'] = $errorMsg;
            $scheduleItem['retry_count'] = (int)($scheduleItem['retry_count'] ?? 0) + 1;
            $scheduleItem['rejection_reason'] = $errorMsg;

            // If error is expired token, update connection status
            if ($errorType === 'expired_token') {
                $project['settings']['connections']['instagram']['connection_status'] = 'expired';
            }

            self::updateScheduleInProjectAndDb($scheduleItem, $project);

            return [
                'ok' => false,
                'status' => 'failed',
                'error_type' => $errorType,
                'error' => $errorMsg
            ];
        }
    }

    /**
     * Helper to keep project memory array and MySQL database in sync
     */
    protected static function updateScheduleInProjectAndDb($scheduleItem, &$project) {
        $sid = $scheduleItem['id'] ?? '';
        if (empty($sid)) return;

        // Update in project schedules array
        if (isset($project['schedules']) && is_array($project['schedules'])) {
            foreach ($project['schedules'] as &$s) {
                if ($s['id'] == $sid) {
                    $s = array_merge($s, $scheduleItem);
                    break;
                }
            }
        }

        // Save in DB if available
        if (function_exists('db_save_schedule')) {
            db_save_schedule($scheduleItem);
        }

        // Save project json
        if (function_exists('saveProject')) {
            saveProject($project);
        }
    }
}
