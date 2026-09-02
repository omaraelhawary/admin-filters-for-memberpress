<?php
/**
 * Batched writer for bulk actions on a filtered set.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Runs one bulk set-user-meta pass over a deduped member id list.
 */
class Meprmf_Bulk_Runner
{

    /** @var int Members written per batch when nothing else is configured. */
    const DEFAULT_BATCH_SIZE = 50;

    /** @var int Member ids listed back by a dry run. */
    const DRY_RUN_PREVIEW_SIZE = 20;

    /**
     * Batch size for a live run.
     *
     * @param int $requested Batch size from the request; 0 or less falls back to the default.
     * @return int
     */
    public static function batch_size($requested = 0)
    {
        $requested = (int) $requested;
        if ($requested < 1) {
            $requested = self::DEFAULT_BATCH_SIZE;
        }

        /**
         * Members written per batch in a bulk run.
         *
         * @since 2.3.0
         * @param int $size Requested size, or {@see DEFAULT_BATCH_SIZE} when none was asked for.
         */
        return max(1, (int) apply_filters('meprmf_bulk_batch_size', $requested));
    }

    /**
     * Preview: the first member ids a live run would write to, with nothing written.
     *
     * @param array<int, int> $user_ids Deduped member ids.
     * @return array<int, int>
     */
    public static function preview(array $user_ids)
    {
        return array_slice(array_values($user_ids), 0, self::DRY_RUN_PREVIEW_SIZE);
    }

    /**
     * Write one meta key/value to every member in the list, in batches.
     *
     * Stops at the first write that fails, so a run that dies halfway reports how far it got
     * rather than leaving the caller to guess. A value already stored on a member counts as
     * succeeded and is not rewritten.
     *
     * ponytail: the whole id list is processed in this one request, so a very large filtered
     * set is bounded by PHP's execution time. Resume-by-offset would need the id list to be
     * snapshotted server-side, because re-running the filter after a write can drop the rows
     * that were just written.
     *
     * @param array<int, int> $user_ids   Deduped member ids.
     * @param string          $meta_key   Validated meta key.
     * @param string          $meta_value Validated meta value.
     * @param int             $batch_size Members per batch; 0 uses the default.
     * @return array{processed: int, succeeded: int, failed_at: int|null, batches: int}
     */
    public static function run(array $user_ids, $meta_key, $meta_value, $batch_size = 0)
    {
        $user_ids   = array_values($user_ids);
        $batch_size = self::batch_size($batch_size);

        $processed = 0;
        $succeeded = 0;
        $batches   = 0;
        $failed_at = null;

        foreach (array_chunk($user_ids, $batch_size) as $batch) {
            $batches++;
            foreach ($batch as $user_id) {
                $user_id = (int) $user_id;
                $processed++;

                if (self::already_stored($user_id, $meta_key, $meta_value)) {
                    $succeeded++;
                    continue;
                }

                if (false === update_user_meta($user_id, $meta_key, $meta_value)) {
                    $failed_at = $user_id;
                    break 2;
                }

                $succeeded++;
            }
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed_at' => $failed_at,
            'batches'   => $batches,
        ];
    }

    /**
     * Write one batch from a deduped member id list.
     *
     * Used by the live-run AJAX path so the client can request one chunk at a time and show
     * progress between requests. The caller passes the snapshotted id list from dry run; this
     * method only processes the chunk at {@see $batch_index}.
     *
     * @param array<int, int> $user_ids    Deduped member ids.
     * @param string          $meta_key    Validated meta key.
     * @param string          $meta_value  Validated meta value.
     * @param int             $batch_size  Members per batch; 0 uses the default.
     * @param int             $batch_index Zero-based chunk index.
     * @return array{processed: int, succeeded: int, failed_at: int|null, batches: int, batch_index: int, done: bool}
     */
    public static function run_batch(array $user_ids, $meta_key, $meta_value, $batch_size = 0, $batch_index = 0)
    {
        $user_ids    = array_values($user_ids);
        $batch_size  = self::batch_size($batch_size);
        $batch_index = max(0, (int) $batch_index);
        $chunks      = array_chunk($user_ids, $batch_size);
        $total       = count($chunks);

        if (0 === $total || $batch_index >= $total) {
            return [
                'processed'   => 0,
                'succeeded'   => 0,
                'failed_at'   => null,
                'batches'     => $total,
                'batch_index' => $batch_index,
                'done'        => true,
            ];
        }

        $processed = 0;
        $succeeded = 0;
        $failed_at = null;

        foreach ($chunks[ $batch_index ] as $user_id) {
            $user_id = (int) $user_id;
            $processed++;

            if (self::already_stored($user_id, $meta_key, $meta_value)) {
                $succeeded++;
                continue;
            }

            if (false === update_user_meta($user_id, $meta_key, $meta_value)) {
                $failed_at = $user_id;
                break;
            }

            $succeeded++;
        }

        return [
            'processed'   => $processed,
            'succeeded'   => $succeeded,
            'failed_at'   => $failed_at,
            'batches'     => $total,
            'batch_index' => $batch_index,
            'done'        => null === $failed_at && ( $batch_index >= $total - 1 ),
        ];
    }

    /**
     * Whether the member already has this exact value stored.
     *
     * update_user_meta() returns false both for a failed write and for a value that did not
     * change, so the no-op has to be recognized before the write or it reads as a hard error.
     * The array form is used because the single form cannot tell "stored as empty" from
     * "never set".
     *
     * @param int    $user_id    Member id.
     * @param string $meta_key   Meta key.
     * @param string $meta_value Meta value.
     * @return bool
     */
    private static function already_stored($user_id, $meta_key, $meta_value)
    {
        $current = get_user_meta($user_id, $meta_key, false);

        return is_array($current)
            && isset($current[0])
            && is_scalar($current[0])
            && (string) $current[0] === (string) $meta_value;
    }
}
