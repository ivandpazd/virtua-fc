@props(['game', 'competition', 'playerTie', 'roundsRemaining', 'finalVenue' => null])

@php
    $currentRoundLabel = $roundsRemaining->first()['label'] ?? '';
@endphp

<x-section-card :title="$competition->name">
    <x-slot name="badge">
        <a href="{{ route('game.competition', [$game->id, $competition->id]) }}" class="text-[10px] text-accent-blue hover:text-blue-400 transition-colors">
            {{ __('game.full_bracket') }} &rarr;
        </a>
    </x-slot>

    <div class="px-4 py-3 space-y-3">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-primary">{{ $currentRoundLabel }}</div>

        <x-cup-tie-card :tie="$playerTie" :player-team-id="$game->team_id" />
    </div>

    @if($roundsRemaining->count() > 1)
        <div class="border-t border-border-default divide-y divide-border-default">
            @foreach($roundsRemaining->skip(1) as $round)
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <span class="text-text-body">{{ $round['label'] }}</span>
                    <span class="text-text-faint">
                        @if($round['isFinal'] && $finalVenue)
                            {{ $finalVenue }}
                        @else
                            {{ __('cup.tbd') }}
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</x-section-card>
