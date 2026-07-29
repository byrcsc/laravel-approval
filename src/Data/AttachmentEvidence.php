<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Exceptions\AttachmentException;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

final readonly class AttachmentEvidence
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ApprovalRequest $request,
        public string $disk,
        public string $path,
        public string $checksum,
        public ?string $name = null,
        public ?string $mimeType = null,
        public ?int $size = null,
        public array $metadata = [],
        public ?Model $uploader = null,
        public ?ApprovalAction $action = null,
    ) {
        if (trim($disk) === '' || trim($path) === '') {
            throw AttachmentException::blankLocation();
        }

        if (preg_match('/\A[a-f0-9]{64}\z/i', $checksum) !== 1) {
            throw AttachmentException::invalidChecksum();
        }

        if ($size !== null && $size < 0) {
            throw AttachmentException::invalidSize();
        }

        if ($action === null) {
            return;
        }

        $requestKey = $request->getKey();
        $actionRequestKey = $action->getAttributeValue('request_id');

        if (! $action->exists || ! is_scalar($requestKey) || ! is_scalar($actionRequestKey)) {
            throw AttachmentException::actionNotOnRequest($request, $action);
        }

        if ((string) $requestKey !== (string) $actionRequestKey) {
            throw AttachmentException::actionNotOnRequest($request, $action);
        }
    }
}
