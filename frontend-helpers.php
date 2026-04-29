<?php
/**
 * frontend-helpers.php
 * Drop-in banner zone renderer for Osclass themes.
 *
 * Usage in any theme template:
 * require_once osc_plugins_path() . 'campaign_builder/frontend-helpers.php';
 * cb_render_zone('homepage', 'hp_leaderboard');
 * cb_render_zone('category', 'cat_top');
 * cb_render_zone('listing',  'lst_sidebar');
 *
 * CPM bidding: highest bidder wins proportionally more impressions.
 * Daily cap: placements that have hit their daily limit are skipped.
 */

// ── INTERCEPT & LOG CLICKS ────────────────────────────────────────────────────
if (isset($_GET['cb_click']) && isset($_GET['b'])) {
    if (class_exists('CampaignBuilderDAO')) {
        $dao = new CampaignBuilderDAO();
        $bannerId    = (int)$_GET['b'];
        $placementId = (int)($_GET['p'] ?? 0);
        $marketId    = (int)($_GET['m'] ?? 0);

        // Fetch banner details
        $dao->dao->select('*');
        $dao->dao->from($dao->tBanners);
        $dao->dao->where('id', $bannerId);
        $res = $dao->dao->get();
        $banner = ($res !== false) ? $res->row() : [];

        if (!empty($banner)) {
            
            // 1. Log the click for the specific creative graphic
            $dao->incrementBannerClicks($bannerId);

            // 2. Log the click for the specific placement position
            if ($placementId > 0) {
                $dao->incrementPlacementClicks($placementId);
            }

            // Redirect to target URL
            $url = !empty($banner['target_url']) ? $banner['target_url'] : osc_base_url();
            header("Location: " . $url);
            exit;
        }
    }
    header("Location: " . osc_base_url());
    exit;
}
// ──────────────────────────────────────────────────────────────────────────────

if (!function_exists('cb_render_zone')) {

    function cb_render_zone($placementType, $positionKey, $options = []) {
        if (!class_exists('CampaignBuilderDAO')) { return; }

        $host   = strtolower(preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? ''));
        $dao    = new CampaignBuilderDAO();
        $market = $dao->getMarketplaceByDomain($host);
        if (empty($market)) { return; }

        $candidates = $dao->getActivePlacementsForZone($positionKey, (int)$market['id']);
        if (empty($candidates)) { return; }

        // Filter out placements that have hit their daily cap
        $campaign_cache = [];
        $eligible = [];
        foreach ($candidates as $pl) {
            $cId = (int)$pl['campaign_id'];
            if (!isset($campaign_cache[$cId])) {
                $campaign_cache[$cId] = $dao->getCampaignById($cId);
            }
            $camp = $campaign_cache[$cId];
            if (empty($camp)) { continue; }

            $campaignCap  = (int)($camp['daily_impressions_limit'] ?? 0);
            $placementCap = (int)($pl['daily_impressions_limit'] ?? 0);
            // Placement-level cap overrides campaign-level if set
            $effectiveCap = $placementCap > 0 ? $placementCap : $campaignCap;

            if ($effectiveCap > 0) {
                $todayCount = $dao->getTodayImpressionCount((int)$pl['id']);
                if ($todayCount >= $effectiveCap) {
                    continue; // Daily cap reached — skip
                }
            }
            $eligible[] = $pl;
        }

        if (empty($eligible)) { return; }

        // Weighted random pick: higher CPM bid = more impressions
        $chosen = cb_cpm_weighted_pick($eligible);
        if (!$chosen) { return; }

        // Fetch uploaded creatives for the winning campaign
        $banners = $dao->getBannersByCampaign((int)$chosen['campaign_id']);
        if (empty($banners)) { return; } // Skip if user hasn't uploaded any images yet

        // Rotate banners (pick one randomly from the available 1-3)
        $banner = $banners[array_rand($banners)];

        // Log impression (Wallet deduction logic lives inside this DAO method)
        $dao->logImpression((int)$chosen['id'], (int)$market['id']);
        $dao->incrementBannerViews((int)$banner['id']);
        // Prepare physical paths and routing URLs
        $imgDesktopUrl = osc_base_url() . 'oc-content/uploads/cb_banners/' . $banner['image_file'];
        $imgMobileUrl  = osc_base_url() . 'oc-content/uploads/cb_banners/' . $banner['image_file_mobile'];
        $clickUrl      = osc_base_url() . '?cb_click=1&b=' . $banner['id'] . '&p=' . $chosen['id'] . '&m=' . $market['id'];

        // Render real banner zone
        echo '<div class="cb-zone cb-zone--' . htmlspecialchars($positionKey, ENT_QUOTES) . '" style="text-align:center; overflow:hidden; margin-bottom:15px;">';
        echo '<a href="' . htmlspecialchars($clickUrl, ENT_QUOTES) . '" target="_blank" rel="noopener nofollow" style="display:inline-block; max-width:100%;">';
        echo '<img src="' . htmlspecialchars($imgDesktopUrl, ENT_QUOTES) . '" class="banner_desktop_size"  style="max-width:100%; height:auto;">';
        echo '<img src="' . htmlspecialchars($imgMobileUrl, ENT_QUOTES) . '" class="banner_mobile_size" style="max-width:100%; height:auto;">';
        echo '</a>';
        echo '</div>';
    }

    function cb_cpm_weighted_pick(array $placements) {
        if (empty($placements))  { return null; }
        if (count($placements) === 1) { return $placements[0]; }
        $total = array_sum(array_column($placements, 'user_max_cpm'));
        if ($total <= 0) { return $placements[0]; }
        $rand = (mt_rand() / mt_getrandmax()) * $total;
        $cum  = 0.0;
        foreach ($placements as $pl) {
            $cum += (float)$pl['user_max_cpm'];
            if ($rand <= $cum) { return $pl; }
        }
        return $placements[0];
    }
}