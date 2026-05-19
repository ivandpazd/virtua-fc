@props(['bestGoalkeepers', 'playerTeamId'])

<x-section-card :title="__('game.best_goalkeepers')" class="self-start">
    @if($bestGoalkeepers->isEmpty())
        <div class="px-4 py-6 text-center">
            <p class="text-sm text-text-muted">{{ __('game.no_goalkeepers_qualified_yet') }}</p>
        </div>
    @else
        <div class="divide-y divide-border-default">
            @foreach($bestGoalkeepers as $index => $gk)
                @php
                    $gkTeam = $gk->scorer_team ?? $gk->team;
                    $isPlayerTeam = $gkTeam?->id === $playerTeamId;
                @endphp
                <div class="flex items-center gap-2.5 px-4 py-2 text-sm {{ $isPlayerTeam ? 'bg-accent-blue/[0.06] border-l-2 border-l-accent-blue' : '' }}">
                    <span class="w-5 text-[11px] font-heading font-semibold text-text-muted shrink-0">{{ $index + 1 }}</span>
                    <x-team-crest :team="$gkTeam" class="w-4 h-4 shrink-0" title="{{ $gkTeam?->name }}" />
                    <span class="flex-1 truncate text-xs {{ $isPlayerTeam ? 'font-medium text-text-primary' : 'text-text-body' }}">{{ $gk->name }}</span>
                    <span class="text-[11px] font-semibold text-text-primary shrink-0">{{ $gk->goals_per_match }}</span>
                </div>
            @endforeach
        </div>
    @endif
</x-section-card>
