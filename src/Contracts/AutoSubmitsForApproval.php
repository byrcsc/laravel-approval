<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Contracts;

use ByRcsc\LaravelApproval\Data\AutoSubmission;

interface AutoSubmitsForApproval
{
    public function approvalAutoSubmission(): AutoSubmission;
}
