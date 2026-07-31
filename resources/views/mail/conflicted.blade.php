<x-mail::message>
{{ __('approval::approval.conflicted.body', ['subject' => $subject]) }}

{{ __('approval::approval.conflicted.unresolved') }}

{{ __('approval::approval.reference', ['reference' => $reference]) }}
</x-mail::message>
