<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;

/**
 * One open request per record, which is this engine's answer to "two people
 * drafted conflicting edits". Cancel the open one, or wait for it to close.
 */
final class ApprovalInProgressException extends ApprovalException
{
    public function __construct(string $message, public readonly ?ApprovalRequest $openRequest = null)
    {
        parent::__construct($message);
    }

    public static function forApprovable(Model $approvable, ApprovalRequest $open): self
    {
        return new self(
            sprintf(
                '[%s#%s] already has an approval request awaiting a decision (%s). Cancel it before submitting another.',
                $approvable::class,
                self::describeKey($approvable),
                Attributes::string($open, 'ulid'),
            ),
            $open,
        );
    }

    private static function describeKey(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : 'unsaved';
    }
}
