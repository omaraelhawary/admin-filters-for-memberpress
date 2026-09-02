<?php
/**
 * Server-side snapshot of a bulk dry-run match set for one live run.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stores deduped member ids from preview so live batches slice a fixed set.
 */
class Meprmf_Bulk_Snapshot
{

    /** @var int Seconds before an unused snapshot expires. */
    const TTL = 3600;

    /** @var string Transient name prefix. */
    const TRANSIENT_PREFIX = 'meprmf_bulk_';

    /**
     * Persist a dry-run match set for the current user.
     *
     * @param array<string, mixed> $data Snapshot payload.
     * @return string Opaque run token, or empty when storage failed.
     */
    public static function store(array $data)
    {
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return '';
        }

        $token = wp_generate_password(32, false, false);
        $key   = self::transient_key($user_id, $token);

        if (false === set_transient($key, $data, self::TTL)) {
            return '';
        }

        return $token;
    }

    /**
     * Load a snapshot for the current user and run token.
     *
     * @param string $token Run token from the dry-run response.
     * @return array<string, mixed>|null
     */
    public static function load($token)
    {
        $token = sanitize_key((string) $token);
        if ('' === $token) {
            return null;
        }

        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return null;
        }

        $data = get_transient(self::transient_key($user_id, $token));

        return is_array($data) ? $data : null;
    }

    /**
     * Stable hash of the filter query string the match set was built from.
     *
     * @param array<string, mixed> $request Request params (typically $_GET).
     * @return string
     */
    public static function query_fingerprint(array $request)
    {
        $request = self::normalize_request($request);
        ksort($request);

        return hash('sha256', (string) wp_json_encode($request));
    }

    /**
     * Whether the posted meta key and value match the snapshot.
     *
     * @param array<string, mixed> $snapshot   Stored snapshot.
     * @param string               $meta_key   Posted meta key.
     * @param string               $meta_value Posted meta value.
     * @return bool
     */
    public static function meta_matches(array $snapshot, $meta_key, $meta_value)
    {
        return isset($snapshot['meta_key'], $snapshot['meta_value'])
            && (string) $snapshot['meta_key'] === (string) $meta_key
            && (string) $snapshot['meta_value'] === (string) $meta_value;
    }

    /**
     * Whether the current request carries the same filter query as the snapshot.
     *
     * @param array<string, mixed> $snapshot Stored snapshot.
     * @param array<string, mixed> $request  Request params (typically $_GET).
     * @return bool
     */
    public static function query_matches(array $snapshot, array $request)
    {
        if (! isset($snapshot['query_fingerprint'])) {
            return false;
        }

        return hash_equals(
            (string) $snapshot['query_fingerprint'],
            self::query_fingerprint($request)
        );
    }

    /**
     * @param int    $user_id User id.
     * @param string $token   Run token.
     * @return string
     */
    private static function transient_key($user_id, $token)
    {
        return self::TRANSIENT_PREFIX . (int) $user_id . '_' . sanitize_key((string) $token);
    }

    /**
     * Scalar-only request params so the fingerprint is stable across reads.
     *
     * @param array<string, mixed> $request Request params.
     * @return array<string, string>
     */
    private static function normalize_request(array $request)
    {
        $normalized = [];

        foreach ($request as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $normalized[ (string) $key ] = (string) $value;
        }

        return $normalized;
    }
}
