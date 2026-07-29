<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

final class InvalidWorkflowDefinitionException extends ApprovalException
{
    public static function because(string $message): self
    {
        return new self("Invalid approval workflow definition: {$message}");
    }
}
