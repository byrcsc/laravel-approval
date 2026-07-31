<x-mail::message>
{{ __('approval::approval.assigned.body', ['subject' => $subject]) }}

{{ __('approval::approval.reference', ['reference' => $reference]) }}
</x-mail::message>
