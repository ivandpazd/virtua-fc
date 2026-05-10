@props([
    'match',
    'tournamentMode' => false,
    'resultLabel' => null,
    'resultBg' => null,
    'resultColor' => null,
])

<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
    @if($tournamentMode)
        <span class="text-xs font-medium text-text-secondary">
            {{ __($match->round_name ?? '') }}
        </span>
    @else
        <x-competition-pill :competition="$match->competition" :round-name="$match->round_name" :round-number="$match->round_number" />
    @endif
    <div class="flex items-center gap-2 min-w-0">
        @if($resultLabel)
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider border {{ $resultBg }} {{ $resultColor }}">
                {{ $resultLabel }}
            </span>
        @endif
        <span class="text-xs text-text-muted truncate">
            {{ $match->venueName() ?? '' }} &middot; {{ $match->scheduled_date->locale(app()->getLocale())->translatedFormat('d M Y') }}
        </span>
    </div>
</div>
