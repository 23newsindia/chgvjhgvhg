<?php
class CSSQueue {
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'css_optimizer';
    }
    
    public function add_to_queue($url, $css) {
        global $wpdb;
        
        // Only add if not exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE page_url = %s",
            $url
        ));
        
        if (!$exists) {
            $wpdb->insert(
                $this->table_name,
                array(
                    'page_url' => $url,
                    'css_content' => $css,
                    'is_processed' => 0
                ),
                array('%s', '%s', '%d')
            );
        }
    }
    
    public function get_processed_css($url) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT css_content FROM {$this->table_name} 
             WHERE page_url = %s AND is_processed = 1",
            $url
        ));
    }
    
    public function process_queue() {
        global $wpdb;
        
        // Get unprocessed items
        $items = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} 
             WHERE is_processed = 0 
             AND (processing_started IS NULL 
                  OR processing_started < DATE_SUB(NOW(), INTERVAL 1 HOUR))
             LIMIT 1"
        );
        
        foreach ($items as $item) {
            // Mark as processing
            $wpdb->update(
                $this->table_name,
                array('processing_started' => current_time('mysql')),
                array('id' => $item->id)
            );
            
            try {
                // Process CSS in small chunks
                $processed_css = $this->process_css_content($item->css_content);
                
                // Update with processed CSS
                $wpdb->update(
                    $this->table_name,
                    array(
                        'css_content' => $processed_css,
                        'is_processed' => 1,
                        'last_updated' => current_time('mysql')
                    ),
                    array('id' => $item->id)
                );
            } catch (Exception $e) {
                error_log('CSS Processing Error: ' . $e->getMessage());
            }
        }
    }
    
    private function process_css_content($css) {
        // Process in chunks of 50KB
        $chunk_size = 50 * 1024;
        $chunks = str_split($css, $chunk_size);
        $processed = '';
        
        foreach ($chunks as $chunk) {
            if ($this->is_memory_critical()) {
                break;
            }
            
            $processed .= $this->optimize_css_chunk($chunk);
            
            // Clean up after each chunk
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        return $processed;
    }
    
    private function is_memory_critical($threshold = 0.9) {
        $limit = ini_get('memory_limit');
        $limit_bytes = wp_convert_hr_to_bytes($limit);
        $usage = memory_get_usage(true);
        
        return ($usage / $limit_bytes) > $threshold;
    }
    
    private function optimize_css_chunk($css) {
        // Basic CSS optimization
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], ' ', $css);
        
        return trim($css);
    }
}
