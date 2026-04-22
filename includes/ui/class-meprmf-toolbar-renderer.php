<?php
/**
 * Renders filter controls in MemberPress list table toolbars.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Toolbar UI for meta filters.
 */
class Meprmf_Toolbar_Renderer
{

    /**
     * Output one filter control (select or search input).
     *
     * @param array<string, mixed> $field   Field definition.
     * @param bool                 $compact When true, show visible label and wrap in a grid cell.
     * @return void
     */
    public static function render_single_filter_control(array $field, $compact)
    {
        $param = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
        if ('' === $param) {
            return;
        }

        $label   = isset($field['label']) ? (string) $field['label'] : '';
        $current = Meprmf_Util::get_request_value($param);

        if ($compact) {
            echo '<div class="meprmf-meta-filters__cell">';
            echo '<label class="meprmf-meta-filters__cell-label" for="' . esc_attr($param) . '">' . esc_html($label) . '</label>';
        } else {
            echo '<label class="screen-reader-text" for="' . esc_attr($param) . '">' . esc_html($label) . '</label>';
        }

        if ('country' === $field['type']) {
            $countries = MeprUtils::countries(true);
            echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '" name="' . esc_attr($param) . '">';
            echo '<option value="">' . esc_html__('— Country —', 'memberpress-members-meta-filters') . '</option>';
            foreach ($countries as $code => $name) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($code),
                    selected($current, (string) $code, false),
                    esc_html($name)
                );
            }
            echo '</select>';
        } elseif ('checkbox' === $field['type']) {
            echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '" name="' . esc_attr($param) . '">';
            echo '<option value="">' . esc_html(sprintf('— %s —', $label)) . '</option>';
            printf(
                '<option value="1" %s>%s</option>',
                selected($current, '1', false),
                esc_html__('Checked', 'memberpress-members-meta-filters')
            );
            echo '</select>';
        } elseif ('select' === $field['type'] && ! empty($field['options']) && is_array($field['options'])) {
            echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '" name="' . esc_attr($param) . '">';
            echo '<option value="">' . esc_html(sprintf('— %s —', $label)) . '</option>';
            foreach ($field['options'] as $value => $opt_label) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr((string) $value),
                    selected($current, (string) $value, false),
                    esc_html((string) $opt_label)
                );
            }
            echo '</select>';
        } else {
            $placeholder = $compact
                ? __('Contains…', 'memberpress-members-meta-filters')
                : $label;
            printf(
                '<input type="search" class="mepr_filter_field regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" />',
                esc_attr($param),
                esc_attr($current),
                esc_attr($placeholder)
            );
        }

        if ($compact) {
            echo '</div>';
        }
    }

    /**
     * Renders extra filter controls (reuses MemberPress `.mepr_filter_field` + Go button behavior).
     *
     * @param string $search_term Unused.
     * @param int    $perpage     Unused.
     * @return void
     */
    public static function render($search_term, $perpage)
    {
        if (! Meprmf_Screen::is_members_admin_list_request() || ! Meprmf_Capabilities::current_user_can_filter()) {
            return;
        }

        $valid = Meprmf_Filter_Registry::get_normalized_fields_for_members();
        if (empty($valid)) {
            return;
        }

        $count     = count($valid);
        $threshold = (int) apply_filters('meprmf_compact_filters_threshold', 6);
        $compact   = $count >= $threshold;

        if ($compact) {
            echo '<div class="mepr-filter-by meprmf-meta-filters meprmf-meta-filters--compact">';
            echo '<details class="meprmf-meta-filters__details" open>';
            printf(
                '<summary class="meprmf-meta-filters__summary"><span class="meprmf-meta-filters__summary-text">%s</span> <span class="meprmf-meta-filters__count">(%d)</span></summary>',
                esc_html__('Member filters', 'memberpress-members-meta-filters'),
                (int) $count
            );
            echo '<div class="meprmf-meta-filters__grid">';
            foreach ($valid as $field) {
                self::render_single_filter_control($field, true);
            }
            echo '</div></details></div>';
            return;
        }

        echo '<span class="mepr-filter-by meprmf-meta-filters">';
        foreach ($valid as $field) {
            self::render_single_filter_control($field, false);
        }
        echo '</span>';
    }
}
