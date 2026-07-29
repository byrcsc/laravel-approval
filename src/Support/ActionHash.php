<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

/**
 * The audit trail's tamper-evidence: each line's hash covers its own content
 * and the hash of the line before it, per request.
 *
 * The same computation runs twice - once in `RecordAction` when the line is
 * written, once in `approval:verify` from what the database hands back - so
 * everything here is defined in terms that survive the round trip. Metadata is
 * normalised through JSON with object keys sorted, because MySQL's binary JSON
 * form reorders keys; timestamps are hashed at the second precision the
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
