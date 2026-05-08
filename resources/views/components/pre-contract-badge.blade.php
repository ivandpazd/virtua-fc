@props(['status'])
@php
    $isAgreed = $status === 'agreed';
    $label = $isAgreed
        ? __('transfers.pre_contract_agreed_badge')
        : __('transfers.pre_contract_pending_badge');
    $tone = $isAgreed
        ? 'bg-accent-green/10 text-accent-green'
        : 'bg-accent-gold/10 text-accent-gold';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center px-1.5 py-0.5 rounded-sm text-[10px] font-medium {$tone}"]) }}>
    {{ $label }}
</span>
