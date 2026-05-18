@php
    /** @var App\Models\AcademyPlayer $prospect */
    /** @var App\Models\Game $game */
    $reasonTone = 'bg-accent-green/10 text-accent-green border-accent-green/20';
    $reasonLabel = __('planner.reason_academy_promoted');
    $nextAge = (int) $prospect->date_of_birth->diffInYears($game->getSeasonEndDate()->copy()->addDay());
@endphp

{{-- Mobile row --}}
<div class="md:hidden px-4 py-3 border-b border-border-default last:border-b-0 cursor-pointer"
     @click="$dispatch('show-player-detail', '{{ route('game.academy.detail', [$game->id, $prospect->id]) }}')">
    <div class="flex items-center gap-3">
        <x-player-avatar :name="$prospect->name"
                         :position-group="\App\Support\PositionMapper::getPositionGroup($prospect->position)"
                         :position-abbrev="\App\Support\PositionMapper::toAbbreviation($prospect->position)"
                         size="sm" />
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1.5 flex-wrap">
                <span class="text-sm font-medium text-text-primary truncate">{{ $prospect->name }}</span>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full border text-[10px] font-medium {{ $reasonTone }}">
                    {{ $reasonLabel }}
                </span>
            </div>
            <div class="flex items-center gap-2 mt-1 min-w-0 text-[10px] text-text-faint">
                <div class="flex items-center gap-0.5 shrink-0">
                    <x-position-badge :position="$prospect->position" size="sm" />
                </div>
                <span class="tabular-nums shrink-0">{{ $nextAge }}</span>
            </div>
        </div>
        <div class="shrink-0 w-[130px]">
            <x-potential-bar
                :current-ability="$prospect->overall_score"
                :potential-low="$prospect->potential_low"
                :potential-high="$prospect->potential_high"
                size="sm" />
        </div>
    </div>
</div>

{{-- Desktop row --}}
<div class="hidden md:grid grid-cols-[40px_1fr_140px_48px_72px_180px_48px] gap-3 items-center px-4 py-2.5 border-b border-border-default last:border-b-0 hover:bg-surface-700/30 transition-colors cursor-pointer"
     @click="$dispatch('show-player-detail', '{{ route('game.academy.detail', [$game->id, $prospect->id]) }}')">

    {{-- Position badge --}}
    <div class="flex justify-center">
        <x-position-badge :position="$prospect->position" size="sm" :tooltip="\App\Support\PositionMapper::toDisplayName($prospect->position)" class="cursor-help" />
    </div>

    {{-- Name + nationality + reason badge --}}
    <div class="flex items-center gap-2 min-w-0">
        @if($prospect->nationality_flag)
            <img src="{{ Storage::disk('assets')->url('flags/' . $prospect->nationality_flag['code'] . '.svg') }}"
                 class="w-4 h-3 rounded-xs shadow-xs shrink-0"
                 title="{{ $prospect->nationality_flag['name'] }}"
                 alt="">
        @endif
        <span class="text-sm font-medium text-text-primary truncate">{{ $prospect->name }}</span>
        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full border text-[10px] font-medium whitespace-nowrap shrink-0 {{ $reasonTone }}">
            {{ $reasonLabel }}
        </span>
    </div>

    {{-- Action column (academy promotees have no role/action chrome) --}}
    <div></div>

    {{-- Age (next season) --}}
    <span class="text-xs text-text-secondary text-center tabular-nums">{{ $nextAge }}</span>

    {{-- Contract (academy players have none yet) --}}
    <span class="text-[11px] text-center tabular-nums text-text-muted">—</span>

    {{-- Quality + potential bar --}}
    <div>
        <x-potential-bar
            :current-ability="$prospect->overall_score"
            :potential-low="$prospect->potential_low"
            :potential-high="$prospect->potential_high"
            size="sm" />
    </div>

    {{-- Role icon column (empty for academy) --}}
    <div></div>
</div>
