<?php
/**
 * Passthrough filter fields (native GET params handled by core or MemberPress add-ons).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Addon and native MemberPress list-table filters (no plugin SQL).
 */
class Meprmf_Addon_Provider
{

    /** @var array<string, array<int, array<string, mixed>>> */
    private static $cached_fields = [];

    /**
     * Clear in-request cache.
     *
     * @return void
     */
    public static function clear_cache()
    {
        self::$cached_fields = [];
    }

    /**
     * Passthrough field definitions for one list screen.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return array<int, array<string, mixed>>
     */
    public static function get_passthrough_fields_for_context(Meprmf_Screen_Context $ctx)
    {
        if (! $ctx->supports_meta_filters_list()) {
            return [];
        }

        $cache_key = $ctx->get_page();
        if (isset(self::$cached_fields[ $cache_key ])) {
            return self::$cached_fields[ $cache_key ];
        }

        $fields = [];

        if ($ctx->is_members()) {
            $fields = self::build_members_addon_fields();
            /**
             * Passthrough addon filter fields on the Members list.
             *
             * @since 2.0.0
             * @param array<int, array<string, mixed>> $fields Field rows; params use native addon keys.
             * @param Meprmf_Screen_Context              $ctx    Screen context.
             */
            $fields = apply_filters('meprmf_members_addon_filters_fields', $fields, $ctx);
        } elseif ($ctx->is_transactions()) {
            $fields = self::build_transactions_passthrough_fields();
        }

        self::$cached_fields[ $cache_key ] = $fields;

        return $fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function build_members_addon_fields()
    {
        $fields = [];

        if (self::is_courses_active()) {
            $courses = self::fetch_cpt_options('mpcs-course');
            if (! empty($courses)) {
                $fields[] = [
                    'param'  => 'course',
                    'label'  => __('Course', 'admin-filters-for-memberpress'),
                    'type'   => 'select',
                    'source' => 'native',
                    'options' => $courses,
                ];
            }
        }

        if (self::is_circles_active()) {
            $circles = self::fetch_cpt_options('mp-circle');
            if (! empty($circles)) {
                $fields[] = [
                    'param'  => 'circle_id',
                    'label'  => __('Circle', 'admin-filters-for-memberpress'),
                    'type'   => 'select',
                    'source' => 'native',
                    'options' => $circles,
                ];
            }
        }

        if (self::is_directory_active()) {
            $directories = self::fetch_cpt_options('mpdir-directory');
            if (! empty($directories)) {
                $fields[] = [
                    'param'  => 'directory',
                    'label'  => __('Directory', 'admin-filters-for-memberpress'),
                    'type'   => 'select',
                    'source' => 'native',
                    'options' => $directories,
                ];
            }
        }

        return $fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function build_transactions_passthrough_fields()
    {
        $fields = [];

        $coupons = self::fetch_coupon_options();
        if (! empty($coupons)) {
            $fields[] = [
                'param'  => 'coupon_id',
                'label'  => __('Coupon', 'admin-filters-for-memberpress'),
                'type'   => 'select',
                'source' => 'native',
                'options' => $coupons,
            ];
        }

        if (class_exists('memberpress\\gifting\\controllers\\App')) {
            $fields[] = [
                'param'  => 'type',
                'label'  => __('Gift type', 'admin-filters-for-memberpress'),
                'type'   => 'select',
                'source' => 'native',
                'options' => [
                    'purchased' => __('Gifts purchased', 'admin-filters-for-memberpress'),
                    'claimed'   => __('Gifts claimed', 'admin-filters-for-memberpress'),
                ],
            ];
        }

        return $fields;
    }

    /**
     * @param string $post_type Post type slug.
     * @return array<int, string>
     */
    private static function fetch_cpt_options($post_type)
    {
        $post_type = sanitize_key((string) $post_type);
        if ('' === $post_type) {
            return [];
        }

        $options = [];

        if (class_exists('MeprCptModel') && 'mpcs-course' !== $post_type) {
            // Circles / Directory use standard CPT posts; MeprCptModel is MemberPress-specific.
        }

        if (! function_exists('get_posts')) {
            return $options;
        }

        $posts = get_posts(
            [
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]
        );

        if (! is_array($posts)) {
            return $options;
        }

        foreach ($posts as $post) {
            if (! is_object($post) || empty($post->ID)) {
                continue;
            }
            $title = isset($post->post_title) ? (string) $post->post_title : '';
            if ('' === $title) {
                continue;
            }
            $options[ (int) $post->ID ] = $title;
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function fetch_coupon_options()
    {
        $options = [];

        if (! class_exists('MeprCptModel')) {
            return $options;
        }

        $coupons = MeprCptModel::all(
            'MeprCoupon',
            false,
            [
                'orderby' => 'title',
                'order'   => 'ASC',
            ]
        );

        if (! is_array($coupons)) {
            return $options;
        }

        foreach ($coupons as $coupon) {
            if (empty($coupon->ID) || ! isset($coupon->post_title)) {
                continue;
            }
            $options[ (int) $coupon->ID ] = (string) $coupon->post_title;
        }

        return $options;
    }

    /**
     * @return bool
     */
    private static function is_courses_active()
    {
        return post_type_exists('mpcs-course');
    }

    /**
     * @return bool
     */
    private static function is_circles_active()
    {
        return post_type_exists('mp-circle');
    }

    /**
     * @return bool
     */
    private static function is_directory_active()
    {
        return post_type_exists('mpdir-directory');
    }
}
