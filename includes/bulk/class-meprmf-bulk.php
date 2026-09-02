<?php
/**
 * Bulk actions on the currently filtered list: AJAX entry point and request guards.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set user meta on every member in the filtered set.
 */
class Meprmf_Bulk
{

    /** @var string Nonce action for every bulk request. */
    const NONCE_ACTION = 'meprmf_bulk_actions';

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function init()
    {
        add_action('wp_ajax_meprmf_bulk_set_meta', [ __CLASS__, 'ajax_bulk_set_meta' ]);
    }

    /**
     * Guards that can be answered from the request alone, before any query runs.
     *
     * Split out from {@see ajax_bulk_set_meta()} so the "refuse an unfiltered set" rule is
     * testable without a WordPress request or a MemberPress install.
     *
     * @param array<string, mixed>       $request Request params (typically $_GET).
     * @param Meprmf_Screen_Context|null $ctx     Screen context, or null when the request is not on a list screen.
     * @return array{success: bool, code?: string}
     */
    public static function precheck_request(array $request, $ctx)
    {
        if (! $ctx instanceof Meprmf_Screen_Context || ! $ctx->supports_meta_filters_list()) {
            return [
                'success' => false,
                'code'    => 'invalid_screen',
            ];
        }

        // MemberPress maps `search-field` to a column through each list table's own
        // db_search_cols map, which this request does not build. Passing the search term with
        // an unmapped field would widen the set past what the admin is looking at, so refuse.
        if (isset($request['search-field'])) {
            $field = is_scalar($request['search-field']) ? strtolower(trim((string) $request['search-field'])) : '';
            if ('' !== $field && 'any' !== $field) {
                return [
                    'success' => false,
                    'code'    => 'unsupported_search_field',
                ];
            }
        }

        if (! Meprmf_Presets::request_asks_for_filters($request, Meprmf_Presets::request_filter_params($ctx))) {
            return [
                'success' => false,
                'code'    => 'unfiltered',
            ];
        }

        return [ 'success' => true ];
    }

    /**
     * Preview or run a set-user-meta pass over the filtered set.
     *
     * @return void
     */
    public static function ajax_bulk_set_meta()
    {
        if (! Meprmf_Capabilities::current_user_can_bulk_actions()) {
            self::send_error('forbidden', 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        /*
         * Left slashed on purpose. This array is handed to MemberPress's own list_table()
         * methods as their $params, which is exactly what MemberPress passes them from its CSV
         * export ($_REQUEST, slashed), and they unslash what they read. The guards below only
         * test presence, so slashes make no difference to them.
         */
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; values sanitized where used.
        $request = is_array($_GET) ? $_GET : [];

        $ctx     = Meprmf_Screen::detect();
        $precheck = self::precheck_request($request, $ctx);
        if (empty($precheck['success'])) {
            self::send_error((string) $precheck['code'], 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; validated in Meprmf_Bulk_Set_Meta.
        $meta_key = isset($_POST['meta_key']) ? (string) wp_unslash($_POST['meta_key']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; validated in Meprmf_Bulk_Set_Meta.
        $meta_value = isset($_POST['meta_value']) ? wp_unslash($_POST['meta_value']) : '';

        $validated = Meprmf_Bulk_Set_Meta::validate($meta_key, $meta_value);
        if (empty($validated['success'])) {
            self::send_error((string) $validated['code'], 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $dry_run = ! empty($_POST['dry_run']);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $run_token = isset($_POST['run_token']) ? sanitize_key((string) wp_unslash($_POST['run_token'])) : '';

        if ($dry_run) {
            $match = self::fetch_match_set($ctx, $request);
            if (null === $match) {
                self::send_error('invalid_screen', 400);
            }

            $run_token = Meprmf_Bulk_Snapshot::store(
                [
                    'user_ids'          => $match['user_ids'],
                    'rows'              => (int) $match['rows'],
                    'members'           => count($match['user_ids']),
                    'meta_key'          => (string) $validated['key'],
                    'meta_value'        => (string) $validated['value'],
                    'query_fingerprint' => Meprmf_Bulk_Snapshot::query_fingerprint($request),
                ]
            );
            if ('' === $run_token) {
                self::send_error('snapshot_failed', 500);
            }

            $payload = [
                'rows'     => (int) $match['rows'],
                'members'  => count($match['user_ids']),
                'key'      => (string) $validated['key'],
                'value'    => (string) $validated['value'],
                'dryRun'   => true,
                'preview'  => Meprmf_Bulk_Runner::preview($match['user_ids']),
                'runToken' => $run_token,
            ];
            wp_send_json_success($payload);
        }

        if ('' === $run_token) {
            self::send_error('missing_run_token', 400);
        }

        $snapshot = Meprmf_Bulk_Snapshot::load($run_token);
        if (null === $snapshot || ! isset($snapshot['user_ids']) || ! is_array($snapshot['user_ids'])) {
            self::send_error('stale_run_token', 400);
        }

        if (! Meprmf_Bulk_Snapshot::meta_matches($snapshot, (string) $validated['key'], (string) $validated['value'])) {
            self::send_error('snapshot_mismatch', 400);
        }

        if (! Meprmf_Bulk_Snapshot::query_matches($snapshot, $request)) {
            self::send_error('snapshot_mismatch', 400);
        }

        $match = [
            'rows'     => isset($snapshot['rows']) ? (int) $snapshot['rows'] : count($snapshot['user_ids']),
            'user_ids' => array_values(array_map('intval', $snapshot['user_ids'])),
        ];

        $payload = [
            'rows'    => (int) $match['rows'],
            'members' => count($match['user_ids']),
            'key'     => (string) $validated['key'],
            'value'   => (string) $validated['value'],
        ];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $batch_size  = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $batch_index = isset($_POST['batch_index']) ? (int) $_POST['batch_index'] : 0;

        $result = Meprmf_Bulk_Runner::run_batch(
            $match['user_ids'],
            (string) $validated['key'],
            (string) $validated['value'],
            $batch_size,
            $batch_index
        );

        $payload['dryRun']     = false;
        $payload['processed']  = $result['processed'];
        $payload['succeeded']  = $result['succeeded'];
        $payload['batches']    = $result['batches'];
        $payload['batchIndex'] = $result['batch_index'];
        $payload['done']       = $result['done'];
        $payload['failedAt']   = $result['failed_at'];
        $payload['batchSize']  = Meprmf_Bulk_Runner::batch_size($batch_size);

        wp_send_json_success($payload);
    }

    /**
     * Fetch the filtered match set and refuse when no Admin Filters predicate ran.
     *
     * @param Meprmf_Screen_Context $ctx     Screen context.
     * @param array<string, mixed>   $request Request params.
     * @return array{rows: int, user_ids: array<int, int>}|null
     */
    private static function fetch_match_set(Meprmf_Screen_Context $ctx, array $request)
    {
        // Escaped exactly as MeprMembersTable / MeprSubscriptionsTable / MeprTransactionsTable
        // escape it before handing it to the same method. MeprDb::list_table() prepares the term
        // again, so this is one escape more than the term needs, and matching it is the point:
        // a term containing a quote must select the same rows the admin is looking at.
        $search = isset($request['search']) && is_scalar($request['search'])
            ? esc_sql(sanitize_text_field((string) wp_unslash($request['search'])))
            : '';

        $match = Meprmf_Bulk_Match_Set::fetch($ctx, $request, $search);
        if (null === $match) {
            return null;
        }

        if (! Meprmf_Bulk_Match_Set::has_active_predicates()) {
            self::send_error('no_predicates', 400);
        }

        return $match;
    }

    /**
     * @param string $code   Error code.
     * @param int    $status HTTP status.
     * @return void
     */
    private static function send_error($code, $status)
    {
        wp_send_json_error(
            [
                'message' => self::error_message_for_code($code),
                'code'    => $code,
            ],
            $status
        );
    }

    /**
     * @param string $code Error code.
     * @return string
     */
    private static function error_message_for_code($code)
    {
        switch ($code) {
            case 'forbidden':
                return __('You are not allowed to run bulk actions.', 'admin-filters-for-memberpress');
            case 'unfiltered':
                return __('Filter the list first. A bulk action will not run against every member.', 'admin-filters-for-memberpress');
            case 'no_predicates':
                return __('At least one Admin Filters predicate must be active. A search term or a native MemberPress toolbar filter on its own is not enough.', 'admin-filters-for-memberpress');
            case 'unsupported_search_field':
                return __('Bulk actions cannot run while the search is restricted to one field. Search across any field, or clear the search.', 'admin-filters-for-memberpress');
            case 'empty_key':
                return __('Enter a meta key.', 'admin-filters-for-memberpress');
            case 'blocked_key':
                return __('That meta key is reserved by WordPress, MemberPress, or this plugin, so it cannot be written in bulk.', 'admin-filters-for-memberpress');
            case 'empty_value':
                return __('Enter a meta value.', 'admin-filters-for-memberpress');
            case 'unsupported_value':
                return __('Only text values can be written in bulk.', 'admin-filters-for-memberpress');
            case 'invalid_screen':
                return __('Bulk actions do not run on this screen.', 'admin-filters-for-memberpress');
            case 'missing_run_token':
                return __('Preview the set before running.', 'admin-filters-for-memberpress');
            case 'stale_run_token':
                return __('The preview expired. Preview the set again before running.', 'admin-filters-for-memberpress');
            case 'snapshot_mismatch':
                return __('The meta key, value, or filters no longer match the preview. Preview again before running.', 'admin-filters-for-memberpress');
            case 'snapshot_failed':
                return __('Could not store the preview. Please try again.', 'admin-filters-for-memberpress');
            default:
                return __('Could not run the bulk action.', 'admin-filters-for-memberpress');
        }
    }
}
