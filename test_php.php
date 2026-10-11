<?php
require_once 'inc/functions.php';

$url = 'https://www.tanedorost.com/';
echo "Testing discovery for $url...\n";

$cats = scrapeCategories($url);

echo "Found " . count($cats) . " categories:\n";
foreach ($cats as $c) {
    echo "- {$c['name']} : {$c['url']}\n";
}

if (empty($cats)) {
    echo "\nTrying raw fetch to see if menu exists in HTML...\n";
    $html = fetchUrl($url);
    if ($html) {
        echo "SUCCESS: Fetched " . strlen($html) . " bytes.\n";
        if (strpos($html, 'class="menu"') !== false) {
            echo "SUCCESS: Found 'class=\"menu\"' in raw HTML.\n";
        } else {
            echo "FAILURE: 'class=\"menu\"' NOT found in raw HTML. Site might be dynamic.\n";
        }
    } else {
        global $last_fetch_error;
        echo "ERROR: Failed to fetch HTML. Error: " . $last_fetch_error . "\n";
    }
}
