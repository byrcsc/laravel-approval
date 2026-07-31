<x-mail::message>
{{ __('approval::approval.approved.body', ['subject' => $subject]) }}

{{ __('approval::approval.reference', ['reference' => $reference]) }}
</x-mail::message>
