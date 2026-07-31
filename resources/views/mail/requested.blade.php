<x-mail::message>
{{ __('approval::approval.requested.body', ['subject' => $subject]) }}

{{ __('approval::approval.reference', ['reference' => $reference]) }}
</x-mail::message>
