<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApiCreditUsage
{
    /**
     * These are application request units, NOT provider billing credits.
     * Bridge-controlled, successful calls count as one unit per completed request.
     */
    public function connection(string $provider, string $model, string $kind): int
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/iD', $provider) ||
            ! preg_match('/^[a-z0-9][a-z0-9._\/-]{0,189}$/iD', $model) ||
            ! preg_match('/^[a-z0-9_]{1,32}$/iD', $kind)) {
            throw ValidationException::withMessages(['model' => 'Invalid provider model identifier.']);
        }

        $now = now();
        DB::table('api_model_connections')->insertOrIgnore([
            'provider' => strtolower($provider),
            'model_key' => $model,
            'kind' => $kind,
            'last_request_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $query = DB::table('api_model_connections')
            ->where('provider', strtolower($provider))
            ->where('model_key', $model)->where('kind', $kind);
        $query->update(['last_request_at' => $now, 'updated_at' => $now]);

        return (int) $query->value('id');
    }

    public function completed(int $userId, int $connectionId, string $source): void
    {
        // Idempotency ensures retrying a completion never consumes two credits.
        if (! preg_match('/^[a-z0-9:_-]{1,145}$/iD', $source)) {
            throw new \InvalidArgumentException('Invalid usage event identifier.');
        }
        DB::table('api_credit_usage')->insertOrIgnore([
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'source_key' => $source,
            'credits' => 1,
            'created_at' => now(),
        ]);
        DB::table('api_model_connections')->where('id', $connectionId)
            ->update(['last_success_at' => now(), 'updated_at' => now()]);
    }

    public function monthStart(): string
    {
        return now()->startOfMonth()->toDateTimeString();
    }

    public function models(): array
    {
        $from = $this->monthStart();
        $rows = DB::table('api_model_connections as m')
            ->leftJoin('api_credit_usage as u', function ($join) use ($from) {
                $join->on('u.connection_id', '=', 'm.id')
                    ->where('u.created_at', '>=', $from);
            })
            ->select('m.id', 'm.provider', 'm.model_key', 'm.kind', 'm.monthly_credit_limit',
                'm.last_request_at', 'm.last_success_at')
            ->selectRaw('COALESCE(SUM(u.credits),0) as used')
            ->groupBy('m.id', 'm.provider', 'm.model_key', 'm.kind',
                'm.monthly_credit_limit', 'm.last_request_at', 'm.last_success_at')
            ->orderByDesc('used')->orderBy('m.provider')->get();

        return $rows->map(function ($row) {
            $used = (int) $row->used;
            $limit = $row->monthly_credit_limit === null ? null : (int) $row->monthly_credit_limit;

            return [
                'id' => (int) $row->id,
                'provider' => $row->provider,
                'model' => $row->model_key,
                'kind' => $row->kind,
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'percent' => $limit ? min(100, round(100 * $used / $limit, 1)) : 0,
                'last_request_at' => $row->last_request_at,
                'last_success_at' => $row->last_success_at,
            ];
        })->all();
    }

    public function users(): array
    {
        $from = $this->monthStart();

        return DB::table('users as users')
            ->leftJoin('api_credit_usage as u', function ($join) use ($from) {
                $join->on('u.user_id', '=', 'users.id')
                    ->where('u.created_at', '>=', $from);
            })
            ->select('users.id', 'users.name', 'users.email', 'users.monthly_credit_limit')
            ->selectRaw('COALESCE(SUM(u.credits),0) as used')
            ->groupBy('users.id', 'users.name', 'users.email', 'users.monthly_credit_limit')
            ->orderByDesc('used')->limit(20)->get()
            ->map(function ($row) {
                $limit = $row->monthly_credit_limit === null ? null : (int) $row->monthly_credit_limit;

                return ['id' => (int) $row->id, 'name' => $row->name,
                    'email' => $row->email, 'used' => (int) $row->used,
                    'limit' => $limit,
                    'remaining' => $limit === null ? null : max(0, $limit - (int) $row->used)];
            })->all();
    }

    public function userModels(int $user): array
    {
        return DB::table('api_model_connections as m')
            ->leftJoin('api_credit_usage as u', function ($join) use ($user) {
                $join->on('u.connection_id', '=', 'm.id')
                    ->where('u.user_id', $user)
                    ->where('u.created_at', '>=', $this->monthStart());
            })
            ->select('m.provider', 'm.model_key', 'm.kind')
            ->selectRaw('COALESCE(SUM(u.credits),0) as used')
            ->groupBy('m.id', 'm.provider', 'm.model_key', 'm.kind')
            ->orderByDesc('used')->get()->all();
    }
}
