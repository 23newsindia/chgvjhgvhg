<?php
/**
 * CSS Processing functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class CSSProcessor {
    private $options;
    private $used_selectors;
    private $is_mobile;
    private $table_name;
    
    public function __construct($options) {
        global $wpdb;
        $this->options = $options;
        $this->used_selectors = [];
        $this->is_mobile = wp_is_mobile();
        $this->table_name = $wpdb->prefix . 'css_optimizer';
    }

    public function process_styles() {
    global $wp_styles, $wpdb;
    
    if (!is_object($wp_styles)) {
        return;
    }

    // Set a reasonable memory limit for processing
    $current_limit = ini_get('memory_limit');
    if ((int)$current_limit < 256) {
        ini_set('memory_limit', '256M');
    }

    // Get current page URL
    $current_url = $this->get_current_page_url();
    
    // Check if we have cached CSS that's less than 24 hours old
    $cached_css = $wpdb->get_var($wpdb->prepare(
        "SELECT css_content FROM {$this->table_name} 
         WHERE page_url = %s 
         AND last_updated > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        $current_url
    ));

    if ($cached_css) {
        $this->output_optimized_css($cached_css);
        return;
    }

    // Process CSS if not cached
    $optimized_css = '';
    $batch_size = 5; // Process 5 styles at a time
    $processed = 0;

    foreach (array_chunk($wp_styles->queue, $batch_size) as $batch) {
        if ($this->is_memory_exhausted(0.8)) { // Check if memory usage is above 80%
            error_log('CSS Optimizer: Memory limit approaching, using cached CSS');
            break;
        }

        foreach ($batch as $handle) {
            if (!$this->should_process_style($handle, $wp_styles)) {
                continue;
            }

            $css_content = $this->get_css_content($handle, $wp_styles);
            if (!$css_content) {
                continue;
            }

            // Process in smaller chunks
            $optimized_chunk = $this->analyze_and_optimize_css($css_content, $this->get_page_html());
            $optimized_css .= $optimized_chunk;
            
            // Clean up memory after each style
            unset($css_content);
            unset($optimized_chunk);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            $processed++;
        }
    }

    // Only store in database if we processed some styles
    if ($processed > 0 && !empty($optimized_css)) {
        // Delete old entries for this URL to prevent table bloat
        $wpdb->delete($this->table_name, ['page_url' => $current_url]);
        
        // Insert new optimized CSS
        $wpdb->insert(
            $this->table_name,
            array(
                'page_url' => $current_url,
                'css_content' => $optimized_css,
                'last_updated' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
    }

    // Output the optimized CSS
    $this->output_optimized_css($optimized_css);
}

  
  private function cleanup_old_cache() {
    global $wpdb;
    
    // Remove entries older than 24 hours
    $wpdb->query(
        "DELETE FROM {$this->table_name} 
         WHERE last_updated < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
}

  

    private function output_optimized_css($css) {
        if (!empty($css)) {
            echo "\n<!-- CSS Optimizer Output -->\n";
            echo "<style type='text/css'>\n";
            echo $css;
            echo "\n</style>\n";
        }
    }

    private function get_current_page_url() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        return $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }

    private function get_page_html() {
        ob_start();
        wp_head();
        wp_footer();
        return ob_get_clean();
    }

    private function is_memory_exhausted($threshold = 0.85) {
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->wp_convert_hr_to_bytes($memory_limit);
        $current_usage = memory_get_usage(true);
        
        return ($current_usage / $memory_limit_bytes) > $threshold;
    }

    private function process_css_document($css_document) {
        try {
            // Convert CSS document to string
            $css = $css_document->render();
            
            // Use autoprefixer
            $autoprefixer = new \Padaliyajay\PHPAutoprefixer\Autoprefixer($css);
            $css = $autoprefixer->compile();
            
            // Minify the result
            return $this->minify_css($css);
        } catch (Exception $e) {
            error_log('CSS Optimizer Error in processing: ' . $e->getMessage());
            return '';
        }
    }

    private function wp_convert_hr_to_bytes($size) {
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

    private function analyze_and_optimize_css($css, $html_content) {
        // Limit the size of CSS to process
        if (strlen($css) > 1000000) { // 1MB limit
            error_log('CSS Optimizer: CSS file too large, skipping optimization');
            return $css;
        }

        // Parse CSS into rules
        preg_match_all('/([^{]+)\{([^}]+)\}/s', $css, $matches);
        
        $optimized = '';
        if (!empty($matches[0])) {
            // Process rules in smaller chunks
            $chunk_size = 100; // Process 100 rules at a time
            $total_rules = count($matches[0]);
            
            for ($i = 0; $i < $total_rules; $i += $chunk_size) {
                $chunk = array_slice($matches[0], $i, $chunk_size);
                $selectors_chunk = array_slice($matches[1], $i, $chunk_size);
                $properties_chunk = array_slice($matches[2], $i, $chunk_size);
                
                foreach ($chunk as $j => $rule) {
                    $selectors = $selectors_chunk[$j];
                    $properties = $properties_chunk[$j];
                    
                    // Skip if it's a media query
                    if (strpos($selectors, '@media') === 0) {
                        if ($this->options['preserve_media_queries']) {
                            $optimized .= $rule;
                        }
                        continue;
                    }
                    
                    // Process selectors
                    $selector_array = array_map('trim', explode(',', $selectors));
                    $keep_rule = false;
                    
                    foreach ($selector_array as $selector) {
                        if ($this->is_excluded_selector($selector) || 
                            $this->is_selector_used($selector, $html_content)) {
                            $keep_rule = true;
                            break;
                        }
                    }
                    
                    if ($keep_rule) {
                        $optimized .= trim($selectors) . '{' . $properties . '}';
                    }
                }
                
                // Clean up chunk memory
                unset($chunk);
                unset($selectors_chunk);
                unset($properties_chunk);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }
        
        return $this->minify_css($optimized);
    }

    private function is_selector_used($selector, $html_content) {
        // Clean selector for testing
        $selector = trim($selector);
        
        // Always keep essential selectors
        if (in_array($selector, ['body', 'html', '*'])) {
            return true;
        }
        
        // Convert CSS selector to a simplified form for testing
        $test_selector = preg_replace('/:[^,]*/', '', $selector); // Remove pseudo-classes
        $test_selector = preg_replace('/#([a-zA-Z0-9_-]+)/', '[id="$1"]', $test_selector);
        $test_selector = preg_replace('/\.([a-zA-Z0-9_-]+)/', '[class*="$1"]', $test_selector);
        
        // Use DOMDocument for reliable HTML parsing
        $dom = new DOMDocument();
        @$dom->loadHTML($html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);
        
        try {
            $elements = $xpath->query($test_selector);
            return $elements && $elements->length > 0;
        } catch (Exception $e) {
            // If selector is too complex, keep it to be safe
            return true;
        }
    }

    private function is_excluded_selector($selector) {
        if (empty($this->options['excluded_classes'])) {
            return false;
        }
        
        foreach ($this->options['excluded_classes'] as $excluded) {
            if (strpos($selector, $excluded) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function filter_device_specific_css($css) {
        if ($this->is_mobile) {
            // Remove desktop-only media queries
            $css = preg_replace('/@media\s*\(\s*min-width\s*:.*?\{.*?\}/s', '', $css);
        } else {
            // Remove mobile-only media queries
            $css = preg_replace('/@media\s*\(\s*max-width\s*:.*?\{.*?\}/s', '', $css);
        }
        return $css;
    }

    private function clean_php_warnings($css) {
        // Remove PHP warnings and notices
        $css = preg_replace('/<br\s*\/?>\s*<b>Warning<\/b>:.*?<br\s*\/?>/is', '', $css);
        $css = preg_replace('/<br\s*\/?>\s*<b>Notice<\/b>:.*?<br\s*\/?>/is', '', $css);
        
        // Remove any remaining HTML tags
        $css = strip_tags($css);
        
        // Clean up any empty rules
        $css = preg_replace('/[^{}]+{\s*}/m', '', $css);
        
        // Remove multiple empty lines
        $css = preg_replace("/[\r\n]+/", "\n", $css);
        
        return trim($css);
    }

    private function should_process_style($handle, $wp_styles) {
        if (!isset($wp_styles->registered[$handle]) || empty($wp_styles->registered[$handle]->src)) {
            return false;
        }

        // Check if the style is related to Code Block Pro
        if (strpos($handle, 'code-block-pro') !== false || 
            strpos($handle, 'kevinbatdorf') !== false || 
            strpos($handle, 'shiki') !== false) {
            return false;
        }

        return !$this->should_skip($handle);
    }

    private function should_skip($handle) {
        $skip_handles = [
            'admin-bar', 
            'dashicons',
            'code-block-pro',
            'wp-block-kevinbatdorf-code-block-pro',
            'shiki'
        ];
        
        if ($this->options['exclude_font_awesome']) {
            $font_awesome_handles = ['font-awesome', 'fontawesome', 'fa', 'font-awesome-official'];
            $skip_handles = array_merge($skip_handles, $font_awesome_handles);
        }
        
        // Check if handle contains any of the skip patterns
        foreach ($skip_handles as $skip_handle) {
            if (strpos($handle, $skip_handle) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function get_css_content($handle, $wp_styles) {
        if (!isset($wp_styles->registered[$handle])) {
            error_log("CSS Optimizer: Style handle '$handle' not found");
            return false;
        }
        
        $style = $wp_styles->registered[$handle];
        if (!isset($style->src)) {
            error_log("CSS Optimizer: No source found for style '$handle'");
            return false;
        }
        
        $src = $this->normalize_url($style->src);
        if (empty($src)) {
            return false;
        }
        
        $css_file = $this->get_local_css_path($src);
        if ($css_file && is_file($css_file)) {
            $content = @file_get_contents($css_file);
            if ($content === false) {
                error_log("CSS Optimizer: Failed to read file: $css_file");
                return false;
            }
            return $content;
        }
        
        return $this->fetch_remote_css($src);
    }

    private function normalize_url($src) {
        if (strpos($src, '//') === 0) {
            return 'https:' . $src;
        } elseif (strpos($src, '/') === 0) {
            return site_url($src);
        }
        return $src;
    }

    private function get_local_css_path($src) {
        $parsed_url = parse_url($src);
        $path = isset($parsed_url['path']) ? ltrim($parsed_url['path'], '/') : '';
        
        $possible_paths = [
            ABSPATH . $path,
            WP_CONTENT_DIR . '/' . str_replace('wp-content/', '', $path),
            get_stylesheet_directory() . '/' . basename($path)
        ];
        
        foreach ($possible_paths as $test_path) {
            $test_path = wp_normalize_path($test_path);
            if (file_exists($test_path) && is_file($test_path)) {
                return $test_path;
            }
        }
        
        return false;
    }

    private function fetch_remote_css($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $response = wp_remote_get($url);
        return !is_wp_error($response) ? wp_remote_retrieve_body($response) : false;
    }

    private function optimize_css($css) {
        if ($this->options['preserve_media_queries']) {
            preg_match_all('/@media[^{]+\{([^}]+)\}/s', $css, $media_queries);
            $media_blocks = isset($media_queries[0]) ? $media_queries[0] : [];
        }

        preg_match_all('/([^{]+)\{([^}]+)\}/s', $css, $matches);
        
        $optimized = '';
        if (!empty($matches[0])) {
            foreach ($matches[0] as $i => $rule) {
                $selectors = $matches[1][$i];
                
                if (strpos($selectors, '@media') === 0) continue;
                
                $optimized_properties = $this->optimize_properties($matches[2][$i]);
                if (!empty($optimized_properties)) {
                    $optimized .= trim($selectors) . '{' . $optimized_properties . '}';
                }
            }
        }

        if ($this->options['preserve_media_queries'] && !empty($media_blocks)) {
            $optimized .= "\n" . implode("\n", $media_blocks);
        }

        return $this->minify_css($optimized);
    }

    private function optimize_properties($properties) {
        $props = array_filter(array_map('trim', explode(';', $properties)));
        $unique_props = [];
        
        foreach ($props as $prop) {
            if (empty($prop)) continue;
            
            $parts = explode(':', $prop, 2);
            if (count($parts) !== 2) continue;
            
            $unique_props[trim($parts[0])] = $prop;
        }

        return implode(';', $unique_props) . ';';
    }

    private function minify_css($css) {
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        $css = str_replace([': ', "\r\n", "\r", "\n", "\t", '{ ', ' {', '} ', ' }', ';}'], [':', '', '', '', '', '{', '{', '}', '}', '}'], $css);
        return trim(preg_replace('/\s+/', ' ', $css));
    }

    private function fix_font_paths($css, $base_url) {
        return preg_replace_callback(
            '/url\([\'"]?(?!data:)([^\'")]+)[\'"]?\)/i',
            function($matches) use ($base_url) {
                $url = $matches[1];
                if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
                    $url = trailingslashit($base_url) . ltrim($url, '/');
                }
                return 'url("' . $url . '")';
            },
            $css
        );
    }
}
