<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Models\ApprovalDelegation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Ends a delegation now. Undecided assignments it routed go back to the
 * delegator straight away; decided ones stay as they were decided.
 */
final class RevokeDelegation
{
    public function __construct(private readonly SweepDelegations $sweep) {}

    public function __invoke(ApprovalDelegation $delegation): ApprovalDelegation
    {
        if ($delegation->getAttributeValue('revoked_at') !== null) {
            throw DelegationException::alreadyRevoked();
        }

        $delegation->forceFill(['revoked_at' => Date::now()])->save();

        $delegator = $delegation->delegator;

        if ($delegator instanceof Model) {
            ($this->sweep)($delegator);
        }

        return $delegation;
    }
}
