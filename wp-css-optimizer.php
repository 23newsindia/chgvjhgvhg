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

define('CSS_OPTIMIZER_PLUGIN_FILE', __FILE__);
define('CSS_OPTIMIZER_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once CSS_OPTIMIZER_PLUGIN_DIR . 'includes/class-css-optimizer.php';

new CSSOptimizer();