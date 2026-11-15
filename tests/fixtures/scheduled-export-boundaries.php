<?php
/** Isolated static boundaries; the rest of the scheduler and preset code is real. */
class Meprmf_Bulk_Match_Set
{
    public static function fetch($ctx, $params, $search = '', $limit = 0)
    {
        $GLOBALS['export_fetches'][] = [ $ctx->get_storage_id(), $params, $search, $limit,
            get_current_user_id(), Meprmf_Screen::detect()->get_storage_id(),
            Meprmf_Util::get_request_value('mpf_country') ];
        if (! empty($GLOBALS['export_fetch_throw'])) { throw new RuntimeException('Sensitive query failure'); }
        $rows = $GLOBALS['export_rows'] ?? [ (object) [ 'ID' => 12, 'email' => 'member@example.test' ] ];
        return [ 'rows' => count($rows), 'results' => $rows, 'user_ids' => [] ];
    }
    public static function has_active_predicates() { return $GLOBALS['export_predicates'] ?? true; }
}
class Meprmf_Filter_Registry
{
    public static function get_normalized_fields_for_context($ctx) { return [ [ 'param' => 'mpf_country' ] ]; }
}
class ScheduledExportDb
{
    public $options = 'wp_options';
    public function prepare($sql, ...$args) { return $args; }
    public function query($args)
    {
        if (($GLOBALS['meprmf_test_options'][$args[0]] ?? null) === $args[1]) {
            unset($GLOBALS['meprmf_test_options'][$args[0]]);
            return 1;
        }
        return 0;
    }
}
