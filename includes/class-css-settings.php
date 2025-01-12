<?php
/**
 * Settings page functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class CSSSettings {
    private $options;
    
    public function __construct($options) {
        $this->options = $options;
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    public function add_admin_menu() {
        add_options_page(
            'CSS Optimizer',
            'CSS Optimizer',
            'manage_options',
            'css-optimizer-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['submit'])) {
            $this->save_settings();
        }

        $this->render_settings_form();
    }

    private function save_settings() {
        check_admin_referer('css_optimizer_settings');
        
        $this->options['enabled'] = isset($_POST['enabled']);
        $this->options['preserve_media_queries'] = isset($_POST['preserve_media_queries']);
        $this->options['exclude_font_awesome'] = isset($_POST['exclude_font_awesome']);
        $this->options['debug_mode'] = isset($_POST['debug_mode']);
        $this->options['device_specific'] = isset($_POST['device_specific']) ? sanitize_text_field($_POST['device_specific']) : 'unified';
        
        // Ensure proper array handling for excluded URLs and classes
        $excluded_urls = isset($_POST['excluded_urls']) ? $_POST['excluded_urls'] : '';
        $excluded_classes = isset($_POST['excluded_classes']) ? $_POST['excluded_classes'] : '';
        
        $this->options['excluded_urls'] = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($excluded_urls))));
        $this->options['excluded_classes'] = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($excluded_classes))));
        $this->options['custom_css'] = wp_strip_all_tags($_POST['custom_css']);
        
        update_option('css_optimizer_options', $this->options);
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }

    private function render_settings_form() {
        // Ensure arrays are properly handled
        $excluded_classes = is_array($this->options['excluded_classes']) ? $this->options['excluded_classes'] : [];
        $excluded_urls = is_array($this->options['excluded_urls']) ? $this->options['excluded_urls'] : [];
        $device_specific = isset($this->options['device_specific']) ? $this->options['device_specific'] : 'unified';
        
        ?>
        <div class="wrap">
            <h1>CSS Optimizer Settings</h1>
            <form method="post">
                <?php wp_nonce_field('css_optimizer_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Optimization</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" <?php checked($this->options['enabled']); ?>>
                                Enable CSS optimization
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Device-Specific Optimization</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Device-Specific Optimization</legend>
                                <label>
                                    <input type="radio" name="device_specific" value="unified" <?php checked($device_specific, 'unified'); ?>>
                                    Unified CSS (Optimize for all devices together)
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="device_specific" value="separate" <?php checked($device_specific, 'separate'); ?>>
                                    Separate CSS (Optimize differently for mobile and desktop)
                                </label>
                                <p class="description">Choose how CSS should be optimized for different devices.</p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="debug_mode" <?php checked($this->options['debug_mode']); ?>>
                                Enable debug logging
                            </label>
                            <p class="description">Logs detailed information about CSS optimization process.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Font Awesome</th>
                        <td>
                            <label>
                                <input type="checkbox" name="exclude_font_awesome" <?php checked($this->options['exclude_font_awesome']); ?>>
                                Exclude Font Awesome from optimization
                            </label>
                            <p class="description">Check this to preserve all Font Awesome styles (recommended)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Preserve Media Queries</th>
                        <td>
                            <label>
                                <input type="checkbox" name="preserve_media_queries" <?php checked($this->options['preserve_media_queries']); ?>>
                                Keep responsive design rules
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Custom CSS</th>
                        <td>
                            <textarea name="custom_css" rows="10" cols="50" class="large-text code"><?php echo esc_textarea($this->options['custom_css']); ?></textarea>
                            <p class="description">Add your custom CSS code here. It will be added to all pages.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Excluded Classes</th>
                        <td>
                            <textarea name="excluded_classes" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $this->options['excluded_classes'])); ?></textarea>
                            <p class="description">Enter one CSS class per line. Any rules containing these classes will be preserved.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Excluded URLs</th>
                        <td>
                            <textarea name="excluded_urls" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $this->options['excluded_urls'])); ?></textarea>
                            <p class="description">Enter one URL pattern per line. Wildcards (*) are supported.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}