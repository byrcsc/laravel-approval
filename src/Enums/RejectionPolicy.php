<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * Determines when rejection decisions close an approval stage.
 */
enum RejectionPolicy: string
{
    case ApprovalImpossible = 'approval_impossible';
    case AnyRejectionVetoes = 'any_rejection_vetoes';
}
