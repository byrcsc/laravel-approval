<x-mail::message>
{{ __('approval::approval.rejected.body', ['subject' => $subject]) }}

@if ($reason !== null)
{{ __('approval::approval.reason', ['reason' => $reason]) }}

@endif
{{ __('approval::approval.reference', ['reference' => $reference]) }}
</x-mail::message>
