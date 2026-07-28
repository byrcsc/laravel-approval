<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use Exception;

/**
 * Catch this to handle anything approval-related; catch a subclass to react to
 * a specific failure. Abstract on purpose: every throw site names what broke.
 */
abstract class ApprovalException extends Exception {}
