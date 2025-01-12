<?php
/**
 * Custom CSS Manager functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class CustomCSSManager {
    public function output_custom_css() {
        $options = get_option('css_optimizer_options', []);
        
        if (!empty($options['custom_css'])) {
            echo "\n<!-- CSS Optimizer Custom CSS -->\n";
            echo "<style type='text/css'>\n";
            echo wp_strip_all_tags($options['custom_css']) . "\n";
            echo "</style>\n";
        }
    }
}