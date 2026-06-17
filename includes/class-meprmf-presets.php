<?php
/**
 * Site-wide saved filter presets (wp_options).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * CRUD and AJAX for filter presets per list screen.
 */
class Meprmf_Presets
{

    /** @var string Option key for all presets grouped by screen storage id. */
    const OPTION_KEY = 'meprmf_filter_presets';

    /** @var int Default maximum presets per screen. */
    const DEFAULT_MAX_PER_SCREEN = 25;

    /** @var int Maximum preset name length. */
    const NAME_MAX_LENGTH = 80;

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function init()
    {
        add_action('wp_ajax_meprmf_save_filter_preset', [ __CLASS__, 'ajax_save_filter_preset' ]);
        add_action('wp_ajax_meprmf_delete_filter_preset', [ __CLASS__, 'ajax_delete_filter_preset' ]);
    }

    /**
     * Presets for one list screen, sorted by name.
     *
     * @param string $storage_id Screen storage id from Meprmf_Screen_Context.
     * @return array<int, array{id: string, name: string, params: array<string, string>, updated: int}>
     */
    public static function get_presets_for_screen($storage_id)
    {
        $storage_id = self::sanitize_storage_id($storage_id);
        if ('' === $storage_id) {
            return [];
        }

        $all    = self::get_all_presets();
        $slice  = isset($all[ $storage_id ]) && is_array($all[ $storage_id ]) ? $all[ $storage_id ] : [];
        $out    = [];

        foreach ($slice as $preset) {
            if (! is_array($preset)) {
                continue;
            }
            $normalized = self::normalize_preset_row($preset);
            if (null === $normalized) {
                continue;
            }
            $out[] = $normalized;
        }

        usort(
            $out,
            static function ($a, $b) {
                return strcasecmp((string) $a['name'], (string) $b['name']);
            }
        );

        /**
         * Filter saved presets before they are exposed to the admin UI.
         *
         * @since 2.0.0
         * @param array<int, array<string, mixed>> $out         Preset rows.
         * @param string                            $storage_id Screen storage id.
         */
        return apply_filters('meprmf_filter_presets', $out, $storage_id);
    }

    /**
     * Save or update a preset by unique name on one screen.
     *
     * @param string               $storage_id   Screen storage id.
     * @param string               $name         Preset display name.
     * @param array<string, mixed> $params       Raw param map from request.
     * @param array<int, string>   $known_params Whitelist of allowed param keys.
     * @return array{success: bool, code?: string, preset?: array<string, mixed>}
     */
    public static function save_preset($storage_id, $name, array $params, array $known_params)
    {
        $storage_id = self::sanitize_storage_id($storage_id);
        if ('' === $storage_id) {
            return [ 'success' => false, 'code' => 'invalid_screen' ];
        }

        $name = self::sanitize_preset_name($name);
        if ('' === $name) {
            return [ 'success' => false, 'code' => 'empty_name' ];
        }

        $clean = self::sanitize_preset_params($params, $known_params);
        if (empty($clean)) {
            return [ 'success' => false, 'code' => 'empty_params' ];
        }

        $all   = self::get_all_presets();
        $slice = isset($all[ $storage_id ]) && is_array($all[ $storage_id ]) ? $all[ $storage_id ] : [];

        $updated_at = time();
        $found      = false;
        $saved      = null;

        foreach ($slice as $i => $preset) {
            if (! is_array($preset) || empty($preset['name'])) {
                continue;
            }
            if (0 !== strcasecmp((string) $preset['name'], $name)) {
                continue;
            }

            $id = isset($preset['id']) ? self::sanitize_preset_id((string) $preset['id']) : '';
            if ('' === $id) {
                $id = self::generate_preset_id();
            }

            $slice[ $i ] = [
                'id'      => $id,
                'name'    => $name,
                'params'  => $clean,
                'updated' => $updated_at,
            ];
            $saved  = $slice[ $i ];
            $found  = true;
            break;
        }

        if (! $found) {
            $max = self::max_presets_per_screen();
            if (count($slice) >= $max) {
                return [ 'success' => false, 'code' => 'limit_reached' ];
            }

            $saved = [
                'id'      => self::generate_preset_id(),
                'name'    => $name,
                'params'  => $clean,
                'updated' => $updated_at,
            ];
            $slice[] = $saved;
        }

        $all[ $storage_id ] = array_values($slice);
        self::update_all_presets($all);

        return [
            'success' => true,
            'preset'  => $saved,
        ];
    }

    /**
     * Delete one preset by id on a screen.
     *
     * @param string $storage_id Screen storage id.
     * @param string $preset_id  Preset id.
     * @return array{success: bool, code?: string}
     */
    public static function delete_preset($storage_id, $preset_id)
    {
        $storage_id = self::sanitize_storage_id($storage_id);
        $preset_id  = self::sanitize_preset_id($preset_id);
        if ('' === $storage_id || '' === $preset_id) {
            return [ 'success' => false, 'code' => 'invalid_input' ];
        }

        $all   = self::get_all_presets();
        $slice = isset($all[ $storage_id ]) && is_array($all[ $storage_id ]) ? $all[ $storage_id ] : [];
        $next  = [];

        $removed = false;
        foreach ($slice as $preset) {
            if (! is_array($preset)) {
                continue;
            }
            $id = isset($preset['id']) ? self::sanitize_preset_id((string) $preset['id']) : '';
            if ($id === $preset_id) {
                $removed = true;
                continue;
            }
            $next[] = $preset;
        }

        if (! $removed) {
            return [ 'success' => false, 'code' => 'not_found' ];
        }

        $all[ $storage_id ] = array_values($next);
        self::update_all_presets($all);

        return [ 'success' => true ];
    }

    /**
     * Whitelist and sanitize preset param map.
     *
     * @param array<string, mixed> $params       Raw params.
     * @param array<int, string>   $known_params Allowed keys.
     * @return array<string, string>
     */
    public static function sanitize_preset_params(array $params, array $known_params)
    {
        $allowed = [];
        foreach ($known_params as $key) {
            $key = Meprmf_Util::sanitize_param((string) $key);
            if ('' !== $key) {
                $allowed[ $key ] = true;
            }
        }

        $out = [];
        foreach ($params as $key => $value) {
            $key = Meprmf_Util::sanitize_param((string) $key);
            if ('' === $key || ! isset($allowed[ $key ])) {
                continue;
            }
            if (! is_scalar($value)) {
                continue;
            }
            $val = sanitize_text_field((string) $value);
            if ('' === $val) {
                continue;
            }
            $out[ $key ] = $val;
        }

        return $out;
    }

    /**
     * Save preset from AJAX.
     *
     * @return void
     */
    public static function ajax_save_filter_preset()
    {
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            wp_send_json_error([ 'message' => 'forbidden', 'code' => 'forbidden' ], 403);
        }

        check_ajax_referer('meprmf_filter_presets', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $storage_id = isset($_POST['screen']) ? sanitize_text_field(wp_unslash((string) $_POST['screen'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $name = isset($_POST['name']) ? (string) wp_unslash($_POST['name']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $params_raw = isset($_POST['params']) ? wp_unslash($_POST['params']) : '';

        $known = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (! empty($_POST['known_params']) && is_string($_POST['known_params'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $decoded_known = json_decode(wp_unslash($_POST['known_params']), true);
            if (is_array($decoded_known)) {
                foreach ($decoded_known as $p) {
                    if (is_string($p) && '' !== $p) {
                        $known[] = $p;
                    }
                }
            }
        }

        $params = [];
        if (is_string($params_raw) && '' !== $params_raw) {
            $decoded = json_decode($params_raw, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        } elseif (is_array($params_raw)) {
            $params = $params_raw;
        }

        $result = self::save_preset($storage_id, $name, $params, $known);
        if (empty($result['success'])) {
            wp_send_json_error(
                [
                    'message' => self::error_message_for_code(isset($result['code']) ? (string) $result['code'] : 'unknown'),
                    'code'    => isset($result['code']) ? (string) $result['code'] : 'unknown',
                ],
                400
            );
        }

        wp_send_json_success(
            [
                'preset'  => $result['preset'],
                'presets' => array_values(self::get_presets_for_screen($storage_id)),
            ]
        );
    }

    /**
     * Delete preset from AJAX.
     *
     * @return void
     */
    public static function ajax_delete_filter_preset()
    {
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            wp_send_json_error([ 'message' => 'forbidden', 'code' => 'forbidden' ], 403);
        }

        check_ajax_referer('meprmf_filter_presets', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $storage_id = isset($_POST['screen']) ? sanitize_text_field(wp_unslash((string) $_POST['screen'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $preset_id = isset($_POST['id']) ? sanitize_text_field(wp_unslash((string) $_POST['id'])) : '';

        $result = self::delete_preset($storage_id, $preset_id);
        if (empty($result['success'])) {
            wp_send_json_error(
                [
                    'message' => self::error_message_for_code(isset($result['code']) ? (string) $result['code'] : 'unknown'),
                    'code'    => isset($result['code']) ? (string) $result['code'] : 'unknown',
                ],
                400
            );
        }

        wp_send_json_success(
            [
                'presets' => array_values(self::get_presets_for_screen($storage_id)),
            ]
        );
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function get_all_presets()
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (! is_array($stored)) {
            return [];
        }

        return $stored;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $all All presets.
     * @return void
     */
    private static function update_all_presets(array $all)
    {
        update_option(self::OPTION_KEY, $all, false);
    }

    /**
     * @param array<string, mixed> $preset Raw row.
     * @return array{id: string, name: string, params: array<string, string>, updated: int}|null
     */
    private static function normalize_preset_row(array $preset)
    {
        $id = isset($preset['id']) ? self::sanitize_preset_id((string) $preset['id']) : '';
        if ('' === $id) {
            return null;
        }

        $name = isset($preset['name']) ? self::sanitize_preset_name((string) $preset['name']) : '';
        if ('' === $name) {
            return null;
        }

        $params = [];
        if (! empty($preset['params']) && is_array($preset['params'])) {
            foreach ($preset['params'] as $key => $value) {
                $key = Meprmf_Util::sanitize_param((string) $key);
                if ('' === $key || ! is_scalar($value)) {
                    continue;
                }
                $val = sanitize_text_field((string) $value);
                if ('' !== $val) {
                    $params[ $key ] = $val;
                }
            }
        }

        if (empty($params)) {
            return null;
        }

        $updated = isset($preset['updated']) ? (int) $preset['updated'] : 0;

        return [
            'id'      => $id,
            'name'    => $name,
            'params'  => $params,
            'updated' => $updated,
        ];
    }

    /**
     * @param string $storage_id Raw storage id.
     * @return string
     */
    private static function sanitize_storage_id($storage_id)
    {
        $storage_id = strtolower(trim((string) $storage_id));
        $storage_id = preg_replace('/[^a-z0-9_]/', '', $storage_id);
        return is_string($storage_id) ? $storage_id : '';
    }

    /**
     * @param string $name Raw name.
     * @return string
     */
    private static function sanitize_preset_name($name)
    {
        $name = sanitize_text_field((string) $name);
        if ('' === $name) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, self::NAME_MAX_LENGTH);
        }

        return substr($name, 0, self::NAME_MAX_LENGTH);
    }

    /**
     * @param string $id Raw id.
     * @return string
     */
    private static function sanitize_preset_id($id)
    {
        $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $id));
        return is_string($id) ? $id : '';
    }

    /**
     * @return string
     */
    private static function generate_preset_id()
    {
        if (function_exists('wp_generate_password')) {
            return 'p_' . wp_generate_password(12, false, false);
        }

        return 'p_' . bin2hex(random_bytes(6));
    }

    /**
     * @return int
     */
    private static function max_presets_per_screen()
    {
        /**
         * Maximum saved filter presets per list screen.
         *
         * @since 2.0.0
         * @param int $max Default 25.
         */
        return max(1, (int) apply_filters('meprmf_max_filter_presets_per_screen', self::DEFAULT_MAX_PER_SCREEN));
    }

    /**
     * @param string $code Error code.
     * @return string
     */
    private static function error_message_for_code($code)
    {
        switch ($code) {
            case 'empty_name':
                return __('Enter a preset name.', 'admin-filters-for-memberpress');
            case 'empty_params':
                return __('Apply at least one filter before saving a preset.', 'admin-filters-for-memberpress');
            case 'limit_reached':
                return __('This screen already has the maximum number of saved presets.', 'admin-filters-for-memberpress');
            case 'not_found':
                return __('That preset could not be found.', 'admin-filters-for-memberpress');
            case 'invalid_screen':
            case 'invalid_input':
                return __('Invalid preset request.', 'admin-filters-for-memberpress');
            default:
                return __('Could not save the preset.', 'admin-filters-for-memberpress');
        }
    }
}
