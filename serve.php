<?php
// 1. Strict Origin Whitelisting
$allowedOrigins = [
    'https://racemarket.net',
    'https://cz.racemarket.net',
    'https://de.racemarket.net',
    'https://dk.racemarket.net',
    'https://es.racemarket.net',
    'https://ee.racemarket.net',
    'https://fi.racemarket.net',
    'https://fr.racemarket.net',
    'https://gr.racemarket.net',
    'https://hr.racemarket.net',
    'https://hu.racemarket.net',
    'https://it.racemarket.net',
    'https://lt.racemarket.net',
    'https://lv.racemarket.net',
    'https://nl.racemarket.net',
    'https://no.racemarket.net',
    'https://pl.racemarket.net',
    'https://pt.racemarket.net',
    'https://se.racemarket.net',
    'https://si.racemarket.net',
    'https://sim.racemarket.net'
];

$httpOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($httpOrigin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $httpOrigin);
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Content-Type: application/json; charset=utf-8");
} else {
    // If it's a direct browser hit (no origin), you might want to allow it for testing, 
    // OR keep it strict. Here we stay strict:
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden Origin']));
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// 2. Load Osclass Core
// This MUST happen before using Params::getParam or CampaignBuilderDAO
define('ABS_PATH', dirname(dirname(dirname(dirname(__FILE__)))) . '/');
require_once ABS_PATH . 'oc-load.php';

// 3. Public API Key Verification
$expectedApiKey = "racemarket_net_public_ad_key_99283";
$providedApiKey = Params::getParam('api_key');

if ($providedApiKey !== $expectedApiKey) {
    http_response_code(401);
    exit(json_encode(['error' => 'Invalid API Key']));
}

// 4. Bidding & Serving Logic
$dao = new CampaignBuilderDAO();
$zone   = Params::getParam('zone');
$mktKey = Params::getParam('mkt');

if (empty($zone) || empty($mktKey)) {
    exit(json_encode(['status' => 'error', 'message' => 'Missing parameters']));
}

// Resolve Marketplace
$marketplace = $dao->getMarketplaceByKey($mktKey);
$marketId = isset($marketplace['id']) ? (int)$marketplace['id'] : 0;

if ($marketId === 0) {
    exit(json_encode(['status' => 'error', 'message' => 'Invalid market']));
}

// Fetch Winner
$placements = $dao->getActivePlacementsForZone($zone, $marketId);
if (empty($placements)) {
    exit(json_encode(['status' => 'empty', 'message' => 'No active ads']));
}

$winner = $placements[0];
$placementId = (int)$winner['id'];

// Check Daily Cap
if (!$dao->isDailyCapAvailable($placementId, (int)$winner['daily_impressions_limit'])) {
    exit(json_encode(['status' => 'empty', 'message' => 'Cap reached']));
}

// Get Banners
$banners = $dao->getBannersByCampaign($winner['campaign_id']);
if (empty($banners)) {
    exit(json_encode(['status' => 'empty', 'message' => 'No banner found']));
}
$banner = $banners[0];

// Log Impression (Billing happens here)
$dao->logImpression($placementId, $marketId);

// Output Response
echo json_encode([
    'status' => 'success',
    'ad' => [
        'id'      => $placementId,
        'image'   => osc_base_url() . 'oc-content/uploads/cb_banners/' . $banner['image_file'],
        'image_m' => osc_base_url() . 'oc-content/uploads/cb_banners/' . $banner['image_file_mobile'],
        'url'     => osc_base_url(true) . '?cb_click=1&b=' . $banner['id'] . '&p=' . $placementId . '&m=' . $marketId,
        'size'    => $winner['size']
    ]
]);