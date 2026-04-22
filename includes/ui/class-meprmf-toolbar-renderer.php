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
     * @param array<string, mixed> $field     Field definition.
     * @param bool                 $compact   When true, show visible label and wrap in a grid cell.
     * @param bool                 $omit_name When true, omit the `name` attribute (floating panel; Apply builds GET in JS).
     * @return void
     */
    public static function render_single_filter_control(array $field, $compact, $omit_name = false)
    {
        $param = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
        if ('' === $param) {
            return;
        }

        $label   = isset($field['label']) ? (string) $field['label'] : '';
        $current = Meprmf_Util::get_request_value($param);

        $name_attr = $omit_name ? '' : ' name="' . esc_attr($param) . '"';
        $data_attr = $omit_name ? ' data-meprmf-param="' . esc_attr($param) . '"' : '';

        if ($compact) {
            echo '<div class="meprmf-meta-filters__cell">';
            echo '<label class="meprmf-meta-filters__cell-label" for="' . esc_attr($param) . '">' . esc_html($label) . '</label>';
        } else {
            echo '<label class="screen-reader-text" for="' . esc_attr($param) . '">' . esc_html($label) . '</label>';
        }

        if ('country' === $field['type']) {
            $countries = MeprUtils::countries(true);
            echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '"' . $name_attr . $data_attr . '>';
            echo '<option value="">' . esc_html__('— Country —', 'admin-filters-for-memberpress') . '</option>';
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
            echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '"' . $name_attr . $data_attr . '>';
            echo '<option value="">' . esc_html(sprintf('— %s —', $label)) . '</option>';
            printf(
                '<option value="1" %s>%s</option>',
                selected($current, '1', false),
                esc_html__('Checked', 'admin-filters-for-memberpress')
            );
            echo '</select>';
        } elseif ('select' === $field['type'] && ! empty($field['options']) && is_array($field['options'])) {
            echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '"' . $name_attr . $data_attr . '>';
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
                ? __('Contains…', 'admin-filters-for-memberpress')
                : $label;
            if ($omit_name) {
                printf(
                    '<input type="search" class="mepr_filter_field regular-text" id="%1$s" data-meprmf-param="%1$s" value="%2$s" placeholder="%3$s" />',
                    esc_attr($param),
                    esc_attr($current),
                    esc_attr($placeholder)
                );
            } else {
                printf(
                    '<input type="search" class="mepr_filter_field regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" />',
                    esc_attr($param),
                    esc_attr($current),
                    esc_attr($placeholder)
                );
            }
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

        if (apply_filters('meprmf_use_floating_members_panel', true)) {
            self::render_members_floating_panel($valid);
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
                esc_html__('Member filters', 'admin-filters-for-memberpress'),
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

    /**
     * Floating filter panel + toggle (Members list, Phase 1 — DESIGN-SCREENS-AND-COMPONENTS.md §11).
     *
     * @param array<int, array<string, mixed>> $valid Normalized field definitions.
     * @return void
     */
    public static function render_members_floating_panel(array $valid)
    {
        $known_params = [];
        foreach ($valid as $field) {
            $p = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
            if ('' !== $p) {
                $known_params[] = $p;
            }
        }

        $active_count = 0;
        foreach ($known_params as $p) {
            if ('' !== Meprmf_Util::get_request_value($p)) {
                ++$active_count;
            }
        }

        $panel_id = 'meprmf-members-filter-panel';

        echo '<div class="mepr-filter-by meprmf-floating-root" data-meprmf-panel-id="' . esc_attr($panel_id) . '">';

        printf(
            '<button type="button" class="button meprmf-toggle-btn" aria-expanded="false" aria-controls="%1$s" id="meprmf-members-filter-toggle">',
            esc_attr($panel_id)
        );
        echo '<span class="meprmf-toggle-btn__icon dashicons dashicons-filter" aria-hidden="true"></span>';
        echo '<span class="meprmf-toggle-btn__label">' . esc_html__('Filters', 'admin-filters-for-memberpress') . '</span>';
        if ($active_count > 0) {
            printf(
                ' <span class="meprmf-toggle-btn__badge" aria-label="%s">%d</span>',
                esc_attr(
                    sprintf(
                        /* translators: %d: number of active filters */
                        _n('%d active filter', '%d active filters', $active_count, 'admin-filters-for-memberpress'),
                        $active_count
                    )
                ),
                (int) $active_count
            );
        } else {
            echo ' <span class="meprmf-toggle-btn__badge" hidden aria-hidden="true">0</span>';
        }
        echo '</button>';

        printf(
            '<div id="%1$s" class="meprmf-filter-panel" role="region" aria-labelledby="meprmf-members-filter-toggle" hidden>',
            esc_attr($panel_id)
        );

        echo '<div class="meprmf-filter-panel__mode meprmf-filter-panel__mode--filter">';
        echo '<p class="meprmf-filter-panel__empty" hidden>';
        echo esc_html__('No filters visible. Click Customize to add some.', 'admin-filters-for-memberpress');
        echo '</p>';

        echo '<div class="meprmf-filter-panel__grid">';
        foreach ($valid as $field) {
            $param = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
            if ('' === $param) {
                continue;
            }
            echo '<div class="meprmf-filter-panel__item" data-meprmf-param="' . esc_attr($param) . '">';
            self::render_single_filter_control($field, true, true);
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="meprmf-filter-panel__actions">';
        printf(
            '<button type="button" class="button button-primary meprmf-filter-panel__apply">%s</button> ',
            esc_html__('Apply filters', 'admin-filters-for-memberpress')
        );
        printf(
            '<button type="button" class="button-link meprmf-filter-panel__clear">%s</button> ',
            esc_html__('Clear', 'admin-filters-for-memberpress')
        );
        printf(
            '<button type="button" class="button-link meprmf-filter-panel__customize">%s</button>',
            esc_html__('Customize', 'admin-filters-for-memberpress')
        );
        echo '</div>';
        echo '</div>';

        echo '<div class="meprmf-filter-panel__mode meprmf-filter-panel__mode--customize" hidden>';
        echo '<div class="meprmf-filter-panel__customize-head">';
        printf(
            '<button type="button" class="button-link meprmf-filter-panel__back">%s</button>',
            esc_html__('← Back', 'admin-filters-for-memberpress')
        );
        echo '<span class="meprmf-filter-panel__customize-title">' . esc_html__('Customize filters', 'admin-filters-for-memberpress') . '</span>';
        echo '</div>';
        echo '<ul class="meprmf-filter-panel__customize-list">';
        foreach ($valid as $field) {
            $param = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
            if ('' === $param) {
                continue;
            }
            $label = isset($field['label']) ? (string) $field['label'] : $param;
            echo '<li><label><input type="checkbox" class="meprmf-filter-panel__vis-cb" value="' . esc_attr($param) . '" checked="checked" /> ';
            echo esc_html($label) . '</label></li>';
        }
        echo '</ul>';
        printf(
            '<p class="meprmf-filter-panel__done-wrap"><button type="button" class="button meprmf-filter-panel__done">%s</button></p>',
            esc_html__('Done', 'admin-filters-for-memberpress')
        );
        echo '</div>';

        echo '</div></div>';
    }
}
