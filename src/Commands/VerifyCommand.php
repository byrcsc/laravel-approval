<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\VerifyAuditTrail;

final class VerifyCommand extends Command
{
    protected $signature = 'approval:verify {request? : Verify one request, by ULID or id}';

    protected $description = 'Walk the audit trail hash chains and report any line or attachment that no longer holds';

    public function handle(VerifyAuditTrail $verify): int
    {
        $identifier = $this->argument('request');
        $only = null;

        if (is_string($identifier) && $identifier !== '') {
            $only = $this->findRequest($identifier);

            if ($only === null) {
                $this->components->error("No approval request matches [{$identifier}].");

                return self::FAILURE;
            }
        }

        $findings = $verify($only);

        if ($findings === []) {
            $this->components->info('Every audit chain holds.');

            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            'Found %d audit %s.',
            count($findings),
            count($findings) === 1 ? 'finding' : 'findings',
        ));

        foreach ($findings as $finding) {
            $this->components->twoColumnDetail(
                "{$finding['request']} {$finding['subject']}",
                $finding['problem'],
            );
        }

        return self::FAILURE;
    }
}
