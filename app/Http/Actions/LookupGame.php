<?php

namespace App\Http\Actions;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LookupGame
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'query' => ['required', 'string', 'max:255'],
        ]);

        $query = trim($request->input('query'));

        $gameQuery = Game::with(['user:id,name,email', 'team:id,name']);

        if (Str::isUuid($query)) {
            $game = $gameQuery->find($query);
        } else {
            $userId = User::query()
                ->where('email', $query)
                ->orWhere('name', $query)
                ->value('id');

            $game = $userId ? $gameQuery->where('user_id', $userId)->first() : null;
        }

        if (! $game) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'game_id' => $game->id,
            'game_mode' => $game->game_mode,
            'season' => $game->season,
            'current_date' => $game->current_date?->format('Y-m-d'),
            'user_name' => $game->user->name,
            'user_email' => $game->user->email,
            'team_name' => $game->team?->name,
            'setup_completed' => $game->setup_completed_at !== null,
        ]);
    }
}
