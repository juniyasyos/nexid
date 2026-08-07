<?php

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Models\Application;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\JWTTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UnitKerjaPushService
{
    public function push(Application $application, ?int $unitKerjaId = null): array
    {
        // Skip disabled applications
        if (!$application->enabled) {
            Log::info('iam.push_unit_kerja_skipped', [
                'app_key' => $application->app_key,
                'application_id' => $application->id,
                'reason' => 'Application is disabled',
            ]);

            return [
                'success' => false,
                'error' => 'Application is disabled and cannot receive push updates.',
            ];
        }

        $payload = $this->buildPayload($application, $unitKerjaId);
        $pushUrl = $this->buildPushUrl($application, $application->app_key);

        Log::info('iam.push_unit_kerja_request', [
            'app_key' => $application->app_key,
            'application_id' => $application->id,
            'push_url' => $pushUrl,
            'unit_kerja_id' => $unitKerjaId,
            'unit_count' => count($payload['data']['units']),
            'user_count' => count($payload['data']['users']),
            'relation_count' => count($payload['data']['user_unit_kerja']),
        ]);

        $jsonBody = json_encode($payload);

        try {
            if (! config('iam.backchannel_verify', true)) {
                $response = Http::timeout(50)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->withBody($jsonBody, 'application/json')
                    ->post($pushUrl);
            } elseif (config('iam.backchannel_method', 'jwt') === 'jwt') {
                $token = app(JWTTokenService::class)->generateBackchannelToken($application);
                $response = Http::withToken($token)
                    ->timeout(50)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->withBody($jsonBody, 'application/json')
                    ->post($pushUrl);
            } else {
                $secret = $this->resolveBackchannelSecret($application);
                $signature = hash_hmac('sha256', $jsonBody, $secret);
                $header = setting('sso.backchannel.signature_header', 'IAM-Signature');

                $response = Http::withHeaders([
                    $header => $signature,
                    'X-IAM-App-Key' => $application->app_key,
                    'Content-Type' => 'application/json',
                ])
                    ->timeout(50)
                    ->withBody($jsonBody, 'application/json')
                    ->post($pushUrl);
            }

            if (! $response->successful()) {
                Log::warning('iam.push_unit_kerja_failed', [
                    'app_key' => $application->app_key,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => "Client returned status {$response->status()}",
                    'response' => $response->body(),
                ];
            }

            $responseData = $response->json() ?? [];

            Log::info('iam.push_unit_kerja_response', [
                'app_key' => $application->app_key,
                'status' => $response->status(),
                'response' => $responseData,
            ]);

            return array_merge(['success' => true, 'message' => 'Push Unit Kerja completed.'], $responseData);
        } catch (\Exception $e) {
            Log::error('iam.push_unit_kerja_exception', [
                'app_key' => $application->app_key,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function buildPayload(Application $application, ?int $unitKerjaId = null): array
    {
        $unitsQuery = UnitKerja::query()->whereNull('deleted_at');
        $relationsQuery = \Illuminate\Support\Facades\DB::table('user_unit_kerja')
            ->join('users', 'user_unit_kerja.user_id', '=', 'users.id')
            ->join('unit_kerja', 'user_unit_kerja.unit_kerja_id', '=', 'unit_kerja.id');

        if ($unitKerjaId !== null) {
            $unitsQuery->where('id', $unitKerjaId);
            $relationsQuery->where('user_unit_kerja.unit_kerja_id', $unitKerjaId);
        }

        $units = $unitsQuery->get(['id', 'unit_name', 'description', 'slug', 'created_at', 'updated_at'])->toArray();

        // Include deleted units if push_deleted_records is enabled
        $deletedUnits = [];
        if (setting('iam.push_deleted_records', true) && $this->modelSupportsSoftDeletes(new UnitKerja())) {
            $deletedUnitsQuery = UnitKerja::onlyTrashed();

            if ($unitKerjaId !== null) {
                $deletedUnitsQuery->where('id', $unitKerjaId);
            }

            $deletedUnits = $deletedUnitsQuery
                ->get(['id', 'unit_name', 'description', 'slug', 'created_at', 'updated_at', 'deleted_at'])
                ->toArray();
        }

        $rawUserIds = $relationsQuery->pluck('user_id')->unique()->toArray();
        
        $userIds = empty($rawUserIds) ? [] : User::whereIn('id', $rawUserIds)
            ->where(function ($q) use ($application) {
                $q->whereHas('applicationRoles', function ($q2) use ($application) {
                    $q2->where('iam_roles.application_id', $application->id);
                })
                ->orWhereHas('accessProfiles.roles', function ($q3) use ($application) {
                    $q3->where('iam_roles.application_id', $application->id);
                });
            })
            ->pluck('id')
            ->toArray();

        if (!empty($userIds)) {
            $relationsQuery->whereIn('user_unit_kerja.user_id', $userIds);
        } else {
            $relationsQuery->where('user_unit_kerja.user_id', -1);
        }

        $selectColumns = ['id', 'nip', 'email', 'name', 'status', 'created_at', 'updated_at'];
        if (Schema::hasColumn((new User())->getTable(), 'iam_id')) {
            $selectColumns[] = 'iam_id';
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get($selectColumns)
            ->map(function (User $user) {
                if (! isset($user->iam_id)) {
                    $user->iam_id = $user->id;
                }

                return $user;
            })
            ->toArray();

        $relations = $relationsQuery
            ->select(
                'user_unit_kerja.user_id',
                'user_unit_kerja.unit_kerja_id',
                'user_unit_kerja.created_at as attached_at',
                'user_unit_kerja.updated_at as attached_updated_at',
                'users.nip as user_nip',
                'users.email as user_email',
                'unit_kerja.slug as unit_slug'
            )
            ->get()
            ->toArray();

        // Include deleted relations (force delete tracking)
        $deletedRelations = [];
        if (setting('iam.push_deleted_records', true) && $this->modelSupportsSoftDeletes(new UnitKerja())) {
            // Query for deleted unit kerja's past relations
            $deletedUnitKerjaIds = UnitKerja::onlyTrashed()
                ->when($unitKerjaId !== null, fn($q) => $q->where('id', $unitKerjaId))
                ->pluck('id')
                ->toArray();

            if (! empty($deletedUnitKerjaIds)) {
                // Note: pivot table doesn't have soft delete, but we track via the deleted unit kerja
                // This signals client to detach users from deleted unit kerja
                $deletedRelations = \Illuminate\Support\Facades\DB::table('user_unit_kerja')
                    ->whereIn('unit_kerja_id', $deletedUnitKerjaIds)
                    ->select(
                        'user_unit_kerja.user_id',
                        'user_unit_kerja.unit_kerja_id'
                    )
                    ->join('users', 'user_unit_kerja.user_id', '=', 'users.id', 'left')
                    ->addSelect('users.nip as user_nip', 'users.email as user_email')
                    ->get()
                    ->toArray();
            }
        }

        return [
            'data' => [
                'units' => $units,
                'deleted_units' => $deletedUnits,
                'users' => $users,
                'user_unit_kerja' => $relations,
                'deleted_user_unit_kerja' => $deletedRelations,
            ],
        ];
    }

    protected function buildPushUrl(Application $application, string $appKey): string
    {
        $base = $this->getBackchannelUrl($application);

        if (! $base) {
            throw new \InvalidArgumentException('Application has no callback/backchannel URL configured for unit kerja push.');
        }

        return $base . '/api/manage-unit-kerja/api/iam/push-unit-kerja?app_key=' . urlencode($appKey);
    }

    protected function getBackchannelUrl(Application $application): ?string
    {
        $backchannel = $application->backchannel_url ?: $application->callback_url;

        if (! $backchannel) {
            return null;
        }

        $parsed = parse_url($backchannel);

        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            return null;
        }

        $base = $parsed['scheme'] . '://' . $parsed['host'];

        if (! empty($parsed['port'])) {
            $base .= ':' . $parsed['port'];
        }

        return rtrim($base, '/');
    }

    protected function resolveBackchannelSecret(Application $application): string
    {
        $secret = config('iam.sso_secret', config('sso.secret', env('SSO_SECRET', ''))) ?: $application->secret;

        if (is_string($secret) && str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            $secret = $decoded !== false ? $decoded : $secret;
        }

        return $secret;
    }

    /**
     * Check if a model uses SoftDeletes trait.
     */
    protected function modelSupportsSoftDeletes($model): bool
    {
        return in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($model::class),
            true
        );
    }
}
