<x-mail::message>
{{ __('approval::approval.overdue.body', ['subject' => $subject]) }}

{{ __('approval::approval.reference', ['reference' => $reference]) }}
</x-mail::message>
