<?php
/**
 * Plugin Name: CSS Optimizer
 * Description: Optimizes CSS by removing unused rules and improving performance
 * Version: 1.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

// Set memory limit to 512M if possible
$current_limit = ini_get('memory_limit');
$current_limit_bytes = wp_convert_hr_to_bytes($current_limit);
if ($current_limit_bytes < 536870912) { // 512MB
    @ini_set('memory_limit', '512M');
}

// Helper function
function wp_convert_hr_to_bytes($size) {
    $size = trim($size);
    $unit = strtolower(substr($size, -1));
    $size = (int)$size;
    
    switch ($unit) {
        case 'g':
            $size *= 1024;
        case 'm':
            $size *= 1024;
        case 'k':
            $size *= 1024;
    }
    
    return $size;
}

define('CSS_OPTIMIZER_PLUGIN_FILE', __FILE__);
define('CSS_OPTIMIZER_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Error handler
function css_optimizer_error_handler($errno, $errstr, $errfile, $errline) {
    if (strpos($errstr, 'memory') !== false) {
        error_log("CSS Optimizer Memory Error: $errstr in $errfile:$errline");
        return true;
    }
    return false;
}
set_error_handler('css_optimizer_error_handler', E_ERROR);

require_once CSS_OPTIMIZER_PLUGIN_DIR . 'includes/class-css-optimizer.php';
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Database setup
register_activation_hook(__FILE__, 'css_optimizer_activate');

function css_optimizer_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'css_optimizer';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        page_url varchar(2083) NOT NULL,
        css_content mediumtext,
        is_processed tinyint(1) DEFAULT 0,
        processing_started datetime DEFAULT NULL,
        last_updated datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY page_url (page_url(191)),
        KEY is_processed (is_processed)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Cleanup functions
function css_optimizer_cleanup() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'css_optimizer';
    
    // Remove old entries and reset stuck processes
    $wpdb->query("
        DELETE FROM $table_name 
        WHERE last_updated < DATE_SUB(NOW(), INTERVAL 7 DAY)
        OR (is_processed = 0 AND processing_started < DATE_SUB(NOW(), INTERVAL 1 HOUR))
    ");
}
add_action('wp_scheduled_delete', 'css_optimizer_cleanup');

// Initialize the optimizer
try {
    new CSSOptimizer();
} catch (Exception $e) {
    error_log('CSS Optimizer Error: ' . $e->getMessage());
}
