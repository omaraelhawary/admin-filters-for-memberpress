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
     * @return bool
     */
    public function is_members()
    {
        return 'memberpress-members' === $this->page;
    }
}
