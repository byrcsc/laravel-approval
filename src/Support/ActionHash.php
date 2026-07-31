<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

/**
 * The action history's tamper-evidence: each action's hash covers its own
 * content and the hash of the action before it, per request.
 *
 * This makes an edit, deletion, reordering, or insertion detectable by
 * `approval:verify`. It is not a signature: the hash is unkeyed and stored
 * alongside the data it covers, so anyone able to write to the database directly
 * can recompute a consistent chain. The guarantee holds against accidental and
 * application-level modification, not against a database administrator.
 *
 * The same computation runs twice - in `RecordAction` when the action is
 * written, and in `approval:verify` from what the database returns - so every
 * value here is defined in terms that survive the round trip. Metadata is
 * normalized through JSON with object keys sorted, because MySQL's binary JSON
 * form reorders keys, and timestamps are hashed at the second precision the
 * `timestamp` column keeps.
 */
final class ActionHash
{
    public static function compute(AuditHashPayload $payload): string
    {
        return hash('sha256', json_encode(
            $payload->canonicalValues(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
