<?php
/**
 * Screen context: page slug and SQL fragment for the user id column.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Immutable context for one MemberPress list-table screen.
 */
class Meprmf_Screen_Context
{

    /** @var string Admin ?page= value. */
    private $page;

    /** @var string SQL expression for user id, e.g. u.ID, tr.user_id. */
    private $user_id_column_sql;

    /**
     * @param string $page                 Admin page slug.
     * @param string $user_id_column_sql   SQL fragment for the user id column.
     */
    public function __construct($page, $user_id_column_sql)
    {
        $this->page                 = $page;
        $this->user_id_column_sql = $user_id_column_sql;
    }

    /**
     * @return string
     */
    public function get_page()
    {
        return $this->page;
    }

    /**
     * @return string
     */
    public function get_user_id_column_sql()
    {
        return $this->user_id_column_sql;
    }

    /**
     * WP_Screen id for this admin list (MemberPress submenu pattern).
     *
     * @return string
     */
    public function get_wp_screen_id()
    {
        return 'memberpress_page_' . $this->page;
    }

    /**
     * Stable id for HTML / localStorage (alphanumeric + underscores).
     *
     * @return string
     */
    public function get_storage_id()
    {
        $id = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->page));
        return is_string($id) ? trim($id, '_') : 'screen';
    }

    /**
     * @return bool
     */
    public function is_members()
    {
        return 'memberpress-members' === $this->page;
    }

    /**
     * Recurring subscriptions list (mepr_subscriptions AS sub).
     *
     * @return bool
     */
    public function is_subscriptions_recurring()
    {
        return 'memberpress-subscriptions' === $this->page;
    }

    /**
     * Lifetime / non-recurring subscriptions list (mepr_transactions AS txn).
     *
     * @return bool
     */
    public function is_lifetimes()
    {
        return 'memberpress-lifetimes' === $this->page;
    }

    /**
     * Transactions list (mepr_transactions AS tr).
     *
     * @return bool
     */
    public function is_transactions()
    {
        return 'memberpress-trans' === $this->page;
    }

    /**
     * Screens that support the usermeta EXISTS filters in this plugin.
     *
     * @return bool
     */
    public function supports_meta_filters_list()
    {
        return $this->is_members()
            || $this->is_subscriptions_recurring()
            || $this->is_lifetimes()
            || $this->is_transactions();
    }

    /**
     * Whether MemberPress table filters (membership, access, dates) apply on this screen.
     *
     * @return bool
     */
    public function supports_core_filters()
    {
        return $this->supports_meta_filters_list();
    }

    /**
     * GET param prefix for core filters on this screen (mpm_, mpmt_, mpms_, mpml_).
     *
     * @return string Empty when unsupported.
     */
    public function get_core_filter_param_prefix()
    {
        if ($this->is_members()) {
            return 'mpm_';
        }
        if ($this->is_transactions()) {
            return 'mpmt_';
        }
        if ($this->is_subscriptions_recurring()) {
            return 'mpms_';
        }
        if ($this->is_lifetimes()) {
            return 'mpml_';
        }

        return '';
    }

    /**
     * Primary list-table row alias for row-scoped predicates (not Members).
     *
     * @return string e.g. tr, sub, txn, or empty on Members.
     */
    public function get_primary_row_alias()
    {
        if ($this->is_transactions()) {
            return 'tr';
        }
        if ($this->is_subscriptions_recurring()) {
            return 'sub';
        }
        if ($this->is_lifetimes()) {
            return 'txn';
        }

        return '';
    }
}
