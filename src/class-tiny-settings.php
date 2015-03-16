<?php
/*
* Tiny Compress Images - WordPress plugin.
* Copyright (C) 2015 Voormedia B.V.
*
* This program is free software; you can redistribute it and/or modify it
* under the terms of the GNU General Public License as published by the Free
* Software Foundation; either version 2 of the License, or (at your option)
* any later version.
*
* This program is distributed in the hope that it will be useful, but WITHOUT
* ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
* FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
* more details.
*
* You should have received a copy of the GNU General Public License along
* with this program; if not, write to the Free Software Foundation, Inc., 51
* Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/

class Tiny_Settings extends Tiny_WP_Base {
    const PREFIX = 'tinypng_';
    const DUMMY_SIZE = '_tiny_dummy';

    protected static function get_prefixed_name($name) {
        return self::PREFIX . $name;
    }

    private $sizes;
    private $tinify_sizes;

    public function admin_init() {
        $section = self::get_prefixed_name('settings');
        add_settings_section($section, self::translate('PNG and JPEG compression'), $this->get_method('render_section'), 'media');

        $field = self::get_prefixed_name('api_key');
        register_setting('media', $field);
        $name = self::is_network_activated() ? 'Multisite API key' : 'TinyPNG API key';
        add_settings_field($field, self::translate($name), $this->get_method('render_api_key'), 'media', $section, array('label_for' => $field));

        $field = self::get_prefixed_name('sizes');
        register_setting('media', $field);
        add_settings_field($field, self::translate('File compression'), $this->get_method('render_sizes'), 'media', $section);
    }

    public function multisite_init() {
        add_action('network_admin_menu', $this->get_method('register_multisite_settings'));
        add_action('network_admin_edit_save_tinypng_multisite_settings', $this->get_method('save_multisite_settings'), 10, 0);
    }

    public function save_multisite_settings() {
        $options = array('page' => self::get_prefixed_name('multisite_settings'));
        if (array_key_exists(self::get_prefixed_name('api_key'), $_POST)) {
            $key = filter_var($_POST[self::get_prefixed_name('api_key')], FILTER_SANITIZE_STRING);
            update_site_option(self::get_prefixed_name('api_key'), $key);
            $options['updated'] = 'true';
        }
        wp_redirect(add_query_arg($options, network_admin_url('settings.php')));
        exit();
    }

    public function register_multisite_settings() {
        add_submenu_page('settings.php', self::translate('Multisite PNG and JPEG compression'),
            self::translate('PNG and JPEG compression'), 'manage_network_plugins',
            self::get_prefixed_name('multisite_settings'), $this->get_method('render_multisite_settings'));
    }

    public function get_api_key() {
        if (defined('TINY_API_KEY')) {
            return TINY_API_KEY;
        }
        $key = $this->get_multisite_api_key();
        return !empty($key) ? $key : get_option(self::get_prefixed_name('api_key'));
    }

    public function get_multisite_api_key() {
        if (!self::is_network_activated()) {
            return null;
        }
        if (defined('TINY_API_KEY')) {
            return TINY_API_KEY;
        }
        return get_site_option(self::get_prefixed_name('api_key'));
    }

    protected static function get_intermediate_size($size) {
        # Inspired by http://codex.wordpress.org/Function_Reference/get_intermediate_image_sizes
        global $_wp_additional_image_sizes;

        $width  = get_option($size . '_size_w');
        $height = get_option($size . '_size_h');
        if ($width && $height) {
            return array($width, $height);
        }

        if (isset($_wp_additional_image_sizes[$size])) {
            $sizes = $_wp_additional_image_sizes[$size];
            return array(
                isset($sizes['width']) ? $sizes['width'] : null,
                isset($sizes['height']) ? $sizes['height'] : null,
            );
        }
        return array(null, null);
    }

    public function get_sizes() {
        if (is_array($this->sizes)) {
            return $this->sizes;
        }

        $setting = get_option(self::get_prefixed_name('sizes'));

        $size = Tiny_Metadata::ORIGINAL;
        $this->sizes = array($size => array(
            'width' => null, 'height' => null,
            'tinify' => !is_array($setting) || (isset($setting[$size]) && $setting[$size] === 'on'),
        ));

        foreach (get_intermediate_image_sizes() as $size) {
            if ($size === self::DUMMY_SIZE) {
                continue;
            }
            list($width, $height) = self::get_intermediate_size($size);
            if ($width && $height) {
                $this->sizes[$size] = array(
                    'width' => $width, 'height' => $height,
                    'tinify' => !is_array($setting) || (isset($setting[$size]) && $setting[$size] === 'on'),
                );
            }
        }
        return $this->sizes;
    }

    public function get_tinify_sizes() {
        if (is_array($this->tinify_sizes)) {
            return $this->tinify_sizes;
        }

        $this->tinify_sizes = array();
        foreach ($this->get_sizes() as $size => $values) {
            if ($values['tinify']) {
                $this->tinify_sizes[] = $size;
            }
        }
        return $this->tinify_sizes;
    }

    public function render_multisite_settings() {
        echo '<h2>' . self::translate('PNG and JPEG compression') . '</h2>';

        echo '<div class="wrap">';

        $section = self::get_prefixed_name('multisite_settings');
        add_settings_section($section, '', $this->get_method('render_section'), self::get_prefixed_name('multisite_settings'));

        if ( isset( $_GET['updated'] ) ) {
            ?><div id="message" class="updated"><p><?php _e( 'Options saved.' ) ?></p></div><?php
        }

        echo '<form method="post" action="edit.php?action=save_tinypng_multisite_settings">';
        settings_fields(self::get_prefixed_name('multisite_settings'));

        $field = self::get_prefixed_name('multisite_api_key');
        add_settings_field($field, self::translate('Multisite API key'), $this->get_method('render_multisite_api_key'), self::get_prefixed_name('multisite_settings'), $section, array('label_for' => $field));

        do_settings_sections(self::get_prefixed_name('multisite_settings'));

        if (!defined('TINY_API_KEY')) {
            submit_button();
        }
        echo '</form>';
        echo '</div>';
    }

    public function render_multisite_api_key() {
        $field = self::get_prefixed_name('api_key');
        $value = $this->get_multisite_api_key();
        if (defined('TINY_API_KEY')) {
            echo sprintf(self::translate('The API key has been configured in %s'), 'wp-config.php') . '.';
        } else {
            echo '<input type="text" id="' . $field . '" name="' . $field . '" value="' . htmlspecialchars($value) . '" size="40" />';
        }
        self::render_link($value);
    }

    public function render_section() {
    }

    public function render_api_key() {
        $field = self::get_prefixed_name('api_key');
        $key = $this->get_api_key();
        if (defined('TINY_API_KEY')) {
            echo '<p>' . sprintf(self::translate('The API key has been configured in %s'), 'wp-config.php') . '.</p>';
            self::render_link($key);
        } elseif (self::is_network_activated()) {
            $multisite_key = $this->get_multisite_api_key();
            if (empty($multisite_key)) {
                if (empty($key)) {
                    echo '<p>' . self::translate('Your Network Admin has not configured an API key yet') . '.</p>';
                } else {
                    echo '<p>' . self::translate('You have an API key configured') . '. ' . self::translate('Your Network Admin can change the key') . '.</p>';
                }
            } else {
                echo '<p>' . self::translate('The API key has been installed by the Network Admin') . '.</p>';
            }
        } else {
            echo '<input type="text" id="' . $field . '" name="' . $field . '" value="' . htmlspecialchars($key) . '" size="40" />';
            self::render_link($key);
        }
    }

    private function render_link($key) {
        echo "<p>";
        $link = '<a href="https://tinypng.com/developers">' . self::translate_escape('TinyPNG Developer section') . '</a>';
        if (empty($key)) {
            printf(self::translate_escape('Visit %s to get an API key') . '.', $link);
        } else {
            printf(self::translate_escape('Visit %s to view your usage or upgrade your account') . '.', $link);
        }
        echo "</p>";
    }

    public function render_sizes() {
        echo '<p>' . self::translate_escape('You can choose to compress different image sizes created by WordPress here') . '.<br/>';
        echo self::translate_escape('Remember each additional image size will affect your TinyPNG monthly usage') . "!";?>
<input type="hidden" name="<?php echo self::get_prefixed_name('sizes[' . self::DUMMY_SIZE .']'); ?>" value="on"/></p>
<?php
        foreach ($this->get_sizes() as $size => $option) {
            $this->render_size_checkbox($size, $option);
        }
    }

    private function render_size_checkbox($size, $option) {
        $id = self::get_prefixed_name("sizes_$size");
        $field = self::get_prefixed_name("sizes[$size]");
        if ($size === Tiny_Metadata::ORIGINAL) {
            $label = self::translate_escape("original");
        } else {
            $label = $size . " - ${option['width']}x${option['height']}";
        }?>
<p><input type="checkbox" id="<?php echo $id; ?>" name="<?php echo $field ?>" value="on" <?php if ($option['tinify']) { echo ' checked="checked"'; } ?>/>
<label for="<?php echo $id; ?>"><?php echo $label; ?></label></p>
<?php
    }
}
