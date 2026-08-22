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

    /** @var string Visible to every admin on the screen (the pre-2.2 behaviour). */
    const VISIBILITY_SHARED = 'shared';

    /** @var string Visible only to the admin who saved it. */
    const VISIBILITY_PRIVATE = 'private';

    /** @var string User meta key prefix holding one default view id per screen. */
    const DEFAULT_VIEW_META_PREFIX = 'meprmf_default_view_';

    /**
     * GET param that suppresses the default view.
     *
     * Without it a default view could not be cleared: clearing every filter produces the
     * clean URL that applies the default again.
     */
    const SUPPRESS_PARAM = 'meprmf_view';

    /** @var string Value of {@see SUPPRESS_PARAM} meaning "no view, show everything". */
    const SUPPRESS_VALUE = 'none';

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function init()
    {
        add_action('wp_ajax_meprmf_save_filter_preset', [ __CLASS__, 'ajax_save_filter_preset' ]);
        add_action('wp_ajax_meprmf_delete_filter_preset', [ __CLASS__, 'ajax_delete_filter_preset' ]);
        add_action('wp_ajax_meprmf_set_default_view', [ __CLASS__, 'ajax_set_default_view' ]);
        add_action('admin_init', [ __CLASS__, 'maybe_apply_default_view' ]);
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

        $user_id = self::current_user_id();

        foreach ($slice as $preset) {
            if (! is_array($preset)) {
                continue;
            }
            $normalized = self::normalize_preset_row($preset);
            if (null === $normalized) {
                continue;
            }
            // Filtered here rather than in the UI: the `meprmf_filter_presets` payload, the
            // AJAX responses and the localized script all read through this one method, so a
            // private view of another admin's is never handed out in the first place.
            if (! self::user_can_see($normalized, $user_id)) {
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
    public static function save_preset($storage_id, $name, array $params, array $known_params, $visibility = self::VISIBILITY_SHARED)
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

        $visibility = self::VISIBILITY_PRIVATE === $visibility
            ? self::VISIBILITY_PRIVATE
            : self::VISIBILITY_SHARED;
        $user_id    = self::current_user_id();

        // A private view needs an owner to be private *to*; without a logged-in user the only
        // honest answer is shared.
        if ($user_id < 1) {
            $visibility = self::VISIBILITY_SHARED;
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

            $row_owner      = isset($preset['owner']) ? max(0, (int) $preset['owner']) : 0;
            $row_visibility = ( $row_owner > 0 && isset($preset['visibility']) && self::VISIBILITY_PRIVATE === $preset['visibility'] )
                ? self::VISIBILITY_PRIVATE
                : self::VISIBILITY_SHARED;

            // Another admin's private view is invisible, so its name is not taken as far as
            // this admin is concerned: fall through and save a separate row.
            if (self::VISIBILITY_PRIVATE === $row_visibility && $row_owner !== $user_id) {
                continue;
            }

            // Overwriting a shared view that belongs to someone else is the concurrent
            // last-write-wins this change exists to stop. Refuse it by name instead.
            if ($row_owner > 0 && $row_owner !== $user_id && ! self::user_can_manage_others_views()) {
                return [ 'success' => false, 'code' => 'name_taken' ];
            }

            $id = isset($preset['id']) ? self::sanitize_preset_id((string) $preset['id']) : '';
            if ('' === $id) {
                $id = self::generate_preset_id();
            }

            $slice[ $i ] = [
                'id'         => $id,
                'name'       => $name,
                'params'     => $clean,
                'updated'    => $updated_at,
                // An ownerless pre-2.2 row is claimed by whoever next saves over it; a row
                // that already has an owner keeps it, so saving never transfers a view.
                'owner'      => $row_owner > 0 ? $row_owner : $user_id,
                'visibility' => $visibility,
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
                'id'         => self::generate_preset_id(),
                'name'       => $name,
                'params'     => $clean,
                'updated'    => $updated_at,
                'owner'      => $user_id,
                'visibility' => $visibility,
            ];
            $slice[] = $saved;
        }

        $all[ $storage_id ] = array_values($slice);
        self::update_all_presets($all);

        if (null !== $saved) {
            $normalized = self::normalize_preset_row($saved);
            if (null !== $normalized) {
                $saved = $normalized;
            }
        }

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

        $user_id = self::current_user_id();
        $removed = false;
        foreach ($slice as $preset) {
            if (! is_array($preset)) {
                continue;
            }
            $id = isset($preset['id']) ? self::sanitize_preset_id((string) $preset['id']) : '';
            if ($id === $preset_id) {
                $normalized = self::normalize_preset_row($preset);
                if (null !== $normalized) {
                    // Another admin's private view is not theirs to know about, let alone
                    // delete, so it reads as missing rather than as refused.
                    if (! self::user_can_see($normalized, $user_id)) {
                        return [ 'success' => false, 'code' => 'not_found' ];
                    }
                    if (! self::user_can_delete($normalized, $user_id)) {
                        return [ 'success' => false, 'code' => 'not_owner' ];
                    }
                }
                $removed = true;
                continue;
            }
            $next[] = $preset;
        }

        if (! $removed) {
            return [ 'success' => false, 'code' => 'not_found' ];
        }

        // A view being deleted must stop being anybody's default, or every admin who chose it
        // gets a screen that silently filters on a view that no longer exists.
        self::forget_default_view_everywhere($storage_id, $preset_id);

        $all[ $storage_id ] = array_values($next);
        self::update_all_presets($all);

        return [ 'success' => true ];
    }

    /**
     * Allowed filter param keys for one list screen (from the filter registry).
     *
     * @param string $storage_id Screen storage id.
     * @return array<int, string>
     */
    public static function get_known_params_for_storage_id($storage_id)
    {
        $storage_id = self::sanitize_storage_id($storage_id);
        if ('' === $storage_id) {
            return [];
        }

        $ctx = Meprmf_Screen::context_for_storage_id($storage_id);
        if (null === $ctx) {
            return [];
        }

        $fields = Meprmf_Filter_Registry::get_normalized_fields_for_context($ctx);
        // Presets must carry each field's operator and relative-window params too, or a saved
        // "is not empty" reloads as a plain contains-match against an empty value.
        $params = [ Meprmf_Util::MATCH_MODE_PARAM ];
        foreach ($fields as $field) {
            foreach (Meprmf_Util::collect_field_request_params($field) as $param) {
                if ('' !== $param) {
                    $params[] = $param;
                }
            }
        }

        return array_values(array_unique($params));
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in save_preset().
        $name = isset($_POST['name']) ? (string) wp_unslash($_POST['name']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in save_preset().
        $params_raw = isset($_POST['params']) ? wp_unslash($_POST['params']) : '';

        $known = self::get_known_params_for_storage_id($storage_id);
        if (empty($known)) {
            wp_send_json_error(
                [
                    'message' => self::error_message_for_code('invalid_screen'),
                    'code'    => 'invalid_screen',
                ],
                400
            );
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $visibility = isset($_POST['visibility']) ? sanitize_text_field(wp_unslash((string) $_POST['visibility'])) : self::VISIBILITY_SHARED;

        $result = self::save_preset($storage_id, $name, $params, $known, $visibility);
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
                'default' => self::get_default_view_id($storage_id),
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
                'default' => self::get_default_view_id($storage_id),
            ]
        );
    }

    /**
     * Current admin's user id, or 0 outside a user context.
     *
     * @return int
     */
    private static function current_user_id()
    {
        return function_exists('get_current_user_id') ? max(0, (int) get_current_user_id()) : 0;
    }

    /**
     * Whether this admin may act on views that belong to another admin.
     *
     * @return bool
     */
    public static function user_can_manage_others_views()
    {
        $can = function_exists('current_user_can') ? (bool) current_user_can('manage_options') : false;

        /**
         * Whether the current admin may delete or overwrite another admin's shared view.
         *
         * @since 2.2.0
         * @param bool $can Default: whoever can manage options.
         */
        return (bool) apply_filters('meprmf_can_manage_others_views', $can);
    }

    /**
     * Whether one normalized row is visible to a user.
     *
     * @param array<string, mixed> $preset  Normalized row.
     * @param int                  $user_id User id.
     * @return bool
     */
    private static function user_can_see(array $preset, $user_id)
    {
        if (self::VISIBILITY_PRIVATE !== ( isset($preset['visibility']) ? $preset['visibility'] : '' )) {
            return true;
        }

        return (int) $user_id > 0 && (int) $preset['owner'] === (int) $user_id;
    }

    /**
     * Whether one normalized row may be deleted by a user.
     *
     * @param array<string, mixed> $preset  Normalized row.
     * @param int                  $user_id User id.
     * @return bool
     */
    private static function user_can_delete(array $preset, $user_id)
    {
        $owner = isset($preset['owner']) ? (int) $preset['owner'] : 0;

        // Pre-2.2 rows have no owner, so nobody can be locked out of the views they already had.
        if ($owner < 1 || $owner === (int) $user_id) {
            return true;
        }

        return self::user_can_manage_others_views();
    }

    /**
     * Select label for a view: the name, marked when it is not shared.
     *
     * Built server-side so the PHP `<option>` list and the JS re-render after a save cannot
     * drift apart.
     *
     * @param string $name       View name.
     * @param string $visibility Visibility.
     * @return string
     */
    private static function view_label($name, $visibility)
    {
        if (self::VISIBILITY_PRIVATE !== $visibility) {
            return $name;
        }

        /* translators: %s: saved view name. */
        return sprintf(__('%s (private)', 'admin-filters-for-memberpress'), $name);
    }

    /**
     * User meta key holding one screen's default view id.
     *
     * @param string $storage_id Screen storage id.
     * @return string Empty when the screen is unknown.
     */
    private static function default_view_meta_key($storage_id)
    {
        $storage_id = self::sanitize_storage_id($storage_id);

        return '' === $storage_id ? '' : self::DEFAULT_VIEW_META_PREFIX . $storage_id;
    }

    /**
     * The view a screen opens with for one admin, when it still exists and is visible.
     *
     * @param string $storage_id Screen storage id.
     * @param int    $user_id    User id, or 0 for the current user.
     * @return string Preset id, or empty.
     */
    public static function get_default_view_id($storage_id, $user_id = 0)
    {
        $key = self::default_view_meta_key($storage_id);
        if ('' === $key) {
            return '';
        }

        $user_id = (int) $user_id > 0 ? (int) $user_id : self::current_user_id();
        if ($user_id < 1) {
            return '';
        }

        $stored = self::sanitize_preset_id((string) get_user_meta($user_id, $key, true));
        if ('' === $stored) {
            return '';
        }

        foreach (self::get_presets_for_screen($storage_id) as $preset) {
            if ((string) $preset['id'] === $stored) {
                return $stored;
            }
        }

        return '';
    }

    /**
     * Mark (or, with an empty id, clear) one screen's default view for the current admin.
     *
     * @param string $storage_id Screen storage id.
     * @param string $preset_id  Preset id, or empty to clear.
     * @return array{success: bool, code?: string, default?: string}
     */
    public static function set_default_view($storage_id, $preset_id)
    {
        $key = self::default_view_meta_key($storage_id);
        if ('' === $key) {
            return [ 'success' => false, 'code' => 'invalid_screen' ];
        }

        $user_id = self::current_user_id();
        if ($user_id < 1) {
            return [ 'success' => false, 'code' => 'forbidden' ];
        }

        $preset_id = self::sanitize_preset_id((string) $preset_id);

        if ('' === $preset_id) {
            delete_user_meta($user_id, $key);

            return [ 'success' => true, 'default' => '' ];
        }

        $exists = false;
        foreach (self::get_presets_for_screen($storage_id) as $preset) {
            if ((string) $preset['id'] === $preset_id) {
                $exists = true;
                break;
            }
        }
        if (! $exists) {
            return [ 'success' => false, 'code' => 'not_found' ];
        }

        update_user_meta($user_id, $key, $preset_id);

        return [ 'success' => true, 'default' => $preset_id ];
    }

    /**
     * Drop a deleted view from every admin's default, not only the deleter's.
     *
     * @param string $storage_id Screen storage id.
     * @param string $preset_id  Preset id.
     * @return void
     */
    private static function forget_default_view_everywhere($storage_id, $preset_id)
    {
        $key = self::default_view_meta_key($storage_id);
        if ('' === $key || '' === $preset_id || ! function_exists('delete_metadata')) {
            return;
        }

        delete_metadata('user', 0, $key, $preset_id, true);
    }

    /**
     * Apply an admin's default view when they open a list screen with nothing applied.
     *
     * Redirects rather than injecting values into the request, so the URL keeps telling the
     * truth about what the list is filtered by -- which is what the chips, the card and every
     * bookmark in this plugin read from.
     *
     * @return void
     */
    public static function maybe_apply_default_view()
    {
        if (! function_exists('wp_doing_ajax') || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (empty($_SERVER['REQUEST_METHOD']) || 'GET' !== strtoupper((string) $_SERVER['REQUEST_METHOD'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list screen params.
        if (isset($_GET[ self::SUPPRESS_PARAM ])) {
            return;
        }

        $ctx = Meprmf_Screen::detect();
        if (null === $ctx || ! $ctx->supports_meta_filters_list()) {
            return;
        }
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            return;
        }

        $storage_id = $ctx->get_storage_id();

        /*
         * This order is load-bearing, so do not tidy it into "read the request, then the view".
         * Asking whether a default view exists costs two cached reads (one option, one user
         * meta). Asking whether the request already filters costs the whole normalized field
         * registry -- product, coupon and gateway options plus every Settings -> Fields custom
         * field -- via request_filter_params(). An admin who has never marked a default view is
         * the common case, and they should not pay for that on every list-screen load.
         */
        $default_id = self::get_default_view_id($storage_id);
        if ('' === $default_id) {
            return;
        }

        $params = [];
        foreach (self::get_presets_for_screen($storage_id) as $preset) {
            if ((string) $preset['id'] === $default_id) {
                $params = $preset['params'];
                break;
            }
        }
        if (empty($params)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Presence test only; values sanitized where used.
        $request = is_array($_GET) ? $_GET : [];

        // Any filter already in the URL is an explicit request, and it wins.
        if (self::request_asks_for_filters($request, self::request_filter_params($ctx))) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rebuilt from sanitized keys below.
        $query = is_array($_GET) ? $_GET : [];
        $args  = [];
        foreach ($query as $key => $value) {
            $key = Meprmf_Util::sanitize_param((string) $key);
            if ('' !== $key && is_scalar($value)) {
                $args[ $key ] = sanitize_text_field((string) $value);
            }
        }
        foreach ($params as $key => $value) {
            $args[ $key ] = $value;
        }
        $args[ self::SUPPRESS_PARAM ] = $default_id;
        unset($args['paged']);

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Whether a request already asks for a filter, so a default view must not override it.
     *
     * Split out from {@see maybe_apply_default_view()} because this is the whole of the "an
     * explicit URL always wins" rule, and it is worth testing without a WordPress request.
     *
     * @param array<string, mixed> $request Request params (typically $_GET).
     * @param array<int, string>   $params  Param names that express a filter on this screen.
     * @return bool
     */
    public static function request_asks_for_filters(array $request, array $params)
    {
        foreach ($params as $param) {
            if (! isset($request[ $param ])) {
                continue;
            }
            $value = $request[ $param ];
            if (is_array($value)) {
                return true;
            }
            if (is_scalar($value) && '' !== (string) $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Params whose presence means the request already asks for a filter.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return array<int, string>
     */
    private static function request_filter_params(Meprmf_Screen_Context $ctx)
    {
        $params = self::get_known_params_for_storage_id($ctx->get_storage_id());

        // A native MemberPress toolbar filter counts too: arriving with ?status=complete is
        // just as explicit as arriving with one of ours.
        foreach (Meprmf_Native_Params::for_context($ctx) as $param) {
            $param = Meprmf_Util::sanitize_param((string) $param);
            if ('' !== $param) {
                $params[] = $param;
            }
        }

        return array_values(array_unique($params));
    }

    /**
     * Set or clear the current admin's default view from AJAX.
     *
     * @return void
     */
    public static function ajax_set_default_view()
    {
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            wp_send_json_error([ 'message' => 'forbidden', 'code' => 'forbidden' ], 403);
        }

        check_ajax_referer('meprmf_filter_presets', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $storage_id = isset($_POST['screen']) ? sanitize_text_field(wp_unslash((string) $_POST['screen'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $preset_id = isset($_POST['id']) ? sanitize_text_field(wp_unslash((string) $_POST['id'])) : '';

        $result = self::set_default_view($storage_id, $preset_id);
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
                'default' => isset($result['default']) ? (string) $result['default'] : '',
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
        $owner   = isset($preset['owner']) ? max(0, (int) $preset['owner']) : 0;

        // A row saved before 2.2.0 has no owner and no visibility, so it reads as shared and
        // ownerless -- exactly what it was. Nothing is rewritten on disk; the defaults are the
        // migration, which makes it idempotent and impossible to half-apply.
        $visibility = self::VISIBILITY_SHARED;
        if ($owner > 0 && isset($preset['visibility']) && self::VISIBILITY_PRIVATE === $preset['visibility']) {
            $visibility = self::VISIBILITY_PRIVATE;
        }

        return [
            'id'         => $id,
            'name'       => $name,
            'params'     => $params,
            'updated'    => $updated,
            'owner'      => $owner,
            'visibility' => $visibility,
            'label'      => self::view_label($name, $visibility),
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
        if (! is_string($storage_id) || '' === $storage_id) {
            return '';
        }

        if (null === Meprmf_Screen::context_for_storage_id($storage_id)) {
            return '';
        }

        return $storage_id;
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
            return self::sanitize_preset_id('p_' . wp_generate_password(12, false, false));
        }

        return self::sanitize_preset_id('p_' . bin2hex(random_bytes(6)));
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
            case 'not_owner':
                return __('That saved view belongs to another administrator, so only they can delete it.', 'admin-filters-for-memberpress');
            case 'name_taken':
                return __('Another administrator already has a shared view with that name. Choose a different name.', 'admin-filters-for-memberpress');
            case 'forbidden':
                return __('You are not allowed to change saved views.', 'admin-filters-for-memberpress');
            case 'invalid_screen':
            case 'invalid_input':
                return __('Invalid preset request.', 'admin-filters-for-memberpress');
            default:
                return __('Could not save the preset.', 'admin-filters-for-memberpress');
        }
    }
}
