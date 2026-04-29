<?php
/*
Plugin Name: Campaign Builder
Plugin URI:  https://yourdomain.com
Description: Multi-step advertising campaign builder with CPM bidding across 20 marketplaces.
Version:     1.2.0
Author:      Your Name
Author URI:  https://yourdomain.com
*/

if (!defined('ABS_PATH')) { exit; }

// ── Reliable path constants ───────────────────────────────────────────────────
// IMPORTANT: osc_plugins_path(__FILE__) returns the global /plugins/ root,
// NOT this plugin's folder. Always use dirname(__FILE__) for plugin-relative paths.
define('CB_PLUGIN_PATH',   dirname(__FILE__) . '/');
define('CB_PLUGIN_FOLDER', osc_plugin_folder(__FILE__));

// ── Load DAO ──────────────────────────────────────────────────────────────────
require_once CB_PLUGIN_PATH . 'dao/CampaignBuilderDAO.php';

// ── Run DB migrations (safe to call on every load — checks column existence) ──
// Run migrations immediately on plugin load (safe — checks column existence)
//(new CampaignBuilderDAO())->runMigrations();

// ══════════════════════════════════════════════════════════════════════════════
// INSTALL / UNINSTALL
// ══════════════════════════════════════════════════════════════════════════════
function cb_install() {
    $dao = new CampaignBuilderDAO();
    $dao->installTables();
}

function cb_uninstall() {
    $dao = new CampaignBuilderDAO();
    $dao->uninstallTables();
}

// ══════════════════════════════════════════════════════════════════════════════
// ADMIN MENU — identical pattern to reference itembanners plugin
// ══════════════════════════════════════════════════════════════════════════════
function cb_admin_menu() {
    osc_add_admin_submenu_page(
        'plugins',
        'Campaign Builder',
        osc_admin_base_url(true) . '?page=plugins&action=renderplugin&file=campaign_builder/admin/dashboard.php',
        'campaign_builder'
    );
}

// ══════════════════════════════════════════════════════════════════════════════
// USER ROUTES
// Using osc_add_route exactly like the reference plugin does.
// Third param is the URL pattern; fourth is the PHP file to include.
// ══════════════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════════════
// USER ROUTES (Updated for native User Dashboard integration)
// ══════════════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════════════
// USER ROUTES (Native User Dashboard Integration)
// ══════════════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════════════
// USER ROUTES (Standalone routing)
// ══════════════════════════════════════════════════════════════════════════════
osc_add_route('cb-campaigns',  'my-campaigns',                  'my-campaigns',              CB_PLUGIN_FOLDER . 'user/list.php', false);
osc_add_route('cb-wizard-new', 'my-campaigns/create',           'my-campaigns/create',       CB_PLUGIN_FOLDER . 'user/wizard.php', false);
osc_add_route('cb-wizard-id',  'my-campaigns/edit/([0-9]+)',    'my-campaigns/edit/{id}',    CB_PLUGIN_FOLDER . 'user/wizard.php', false);
osc_add_route('cb-success',    'my-campaigns/success/([0-9]+)', 'my-campaigns/success/{id}', CB_PLUGIN_FOLDER . 'user/success.php', false);
// Register the AJAX route
osc_add_route('cb-ajax', 'cb-ajax', 'cb-ajax', osc_plugin_folder(__FILE__) . 'user/ajax.php');

// ── AJAX / form POST — fires on header hook when cb_ajax=1 ───────────────────
function cb_handle_ajax() {
    if (Params::getParam('cb_ajax') !== '1') { return; }
    if (!osc_is_web_user_logged_in()) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Not logged in']);
            exit;
        }
        osc_redirect_to(osc_user_login_url());
    }
    require CB_PLUGIN_PATH . 'user/ajax.php';
    exit;
}

// ── "My Campaigns" in user nav ────────────────────────────────────────────────
function cb_user_menu() {
    if (!osc_is_web_user_logged_in()) { return; }
    echo '<li><a href="' . osc_route_url('cb-campaigns') . '">My Campaigns</a></li>';
}

// ── OsclassPay success callback ───────────────────────────────────────────────
function cb_payment_success($paymentData) {
    if (empty($paymentData['item_id'])) { return; }
    $dao = new CampaignBuilderDAO();
    $c   = $dao->getCampaignById((int)$paymentData['item_id']);
    if ($c && $c['status'] === 'pending_payment') {
        $dao->updateCampaign((int)$c['id'], [
            'status'     => 'active',
            'start_date' => date('Y-m-d'),
            'end_date'   => date('Y-m-d', strtotime('+' . (int)$c['duration_days'] . ' days')),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

// ── Daily cron ────────────────────────────────────────────────────────────────
function cb_daily_cron() {
    $dao = new CampaignBuilderDAO();
    $dao->expireOldCampaigns();
    $dao->activateDueCampaigns();
}

// ══════════════════════════════════════════════════════════════════════════════
// REGISTER HOOKS
// ══════════════════════════════════════════════════════════════════════════════
osc_register_plugin(osc_plugin_path(__FILE__), 'cb_install');
osc_add_hook('uninstall_campaign_builder/index.php', 'cb_uninstall');
osc_add_hook('admin_menu_init',    'cb_admin_menu');
osc_add_hook('init',             'cb_handle_ajax');
osc_add_hook('user_menu_item',     'cb_user_menu');
osc_add_hook('osclass_pay_success','cb_payment_success');
osc_add_hook('daily_cron',         'cb_daily_cron');
