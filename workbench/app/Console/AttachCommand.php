<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Data\AttachmentEvidence;
use ByRcsc\LaravelApproval\Exceptions\ApprovalException;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Storage;

/**
 * Puts a file in evidence on a request.
 *
 * The division of labour is the interesting part. The application stores the
 * file and computes its checksum; the package records the reference and writes
 * the checksum into an audit line, which puts the file's content - not merely
 * its name - under the hash chain. The package never opens a disk, so
 * `approval:verify` can prove the attachment row matches what was recorded and
 * cannot prove the bytes are still those bytes.
 *
 * Attaching to a closed request is allowed and normal: supporting documents
 * arrive at submission, and a signed decision sheet arrives after the decision
 * that closed it.
 */
final class AttachCommand extends Command
{
    protected $signature = 'demo:attach
        {request : A request ULID or id}
        {--as= : Who is attaching it}
        {--name=quotation.txt : The file name to write}
        {--contents= : What to put in the file}';

    protected $description = 'Write a file to the evidence disk and attach it to a request';

    public function handle(): int
    {
        $identifier = $this->argument('request');
        $request = $this->request(is_scalar($identifier) ? (string) $identifier : '');

        if ($request === null) {
            return self::FAILURE;
        }

        $uploader = $this->person($this->text('as'));

        if ($uploader === null) {
            return self::FAILURE;
        }

        $name = $this->text('name') ?? 'quotation.txt';
        $contents = $this->text('contents')
            ?? sprintf("Supporting document for %s.\n", $this->reference($request));
        $path = sprintf('%s/%s', $this->reference($request), $name);

        // The application's job: store the bytes, and know their digest.
        $disk = Storage::disk('evidence');
        $disk->put($path, $contents);

        try {
            $attachment = Approval::attach(new AttachmentEvidence(
                request: $request,
                disk: 'evidence',
                path: $path,
                checksum: hash('sha256', $contents),
                name: $name,
                mimeType: 'text/plain',
                size: strlen($contents),
                metadata: ['captured_by' => 'demo:attach'],
                uploader: $uploader,
            ));
        } catch (ApprovalException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Attached, and recorded in the audit trail.');
        $this->components->twoColumnDetail('path', 'evidence://'.$path);
        $this->components->twoColumnDetail('checksum', (string) $attachment->getAttributeValue('checksum'));

        $this->newLine();
        $this->line("  Next: <fg=cyan>vendor/bin/testbench approval:verify {$this->reference($request)}</>");

        return self::SUCCESS;
    }
}
