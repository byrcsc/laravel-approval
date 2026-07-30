<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;

final class DeletingApprovableResolver implements ApproverResolver
{
    /**
     * @param  array<string, mixed>  $config
     * @return list<Model>
     */
    public function resolve(StageSnapshot $stage, Model $approvable, Model $submitter, array $config): iterable
    {
        $approvable->delete();

        return [$submitter];
    }
}
