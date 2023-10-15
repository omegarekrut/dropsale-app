<?php

namespace App\Jobs;

use App\Events\UserImported;
use App\Models\SpecialUser;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\JsonResponse;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const API_ENDPOINT = 'https://randomuser.me/api/?results=';
    private const BATCH_SIZE = 1000;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $usersFromApi = $this->fetchUsersInBatches(5000);
        [$addedCount, $updatedCount] = $this->updateOrAddUsers($usersFromApi);

        DB::table('job_results')->insert([
            'type' => 'user_import',
            'status' => 'completed',
            'data' => json_encode([
                'total' => SpecialUser::count(),
                'added' => $addedCount,
                'updated' => $updatedCount
            ])
        ]);
    }

    private function fetchUsersInBatches(int $totalUsers): array
    {
        $allUsers = [];
        $numberOfBatches = ceil($totalUsers / self::BATCH_SIZE);

        $client = new Client();

        for ($i = 0; $i < $numberOfBatches; $i++) {
            try {
                $response = $client->get(self::API_ENDPOINT . self::BATCH_SIZE);

                if ($response->getStatusCode() == 200) {
                    $body = $response->getBody();
                    $data = json_decode($body, true);
                    $allUsers = array_merge($allUsers, $data['results'] ?? []);
                }
            } catch (\Exception $e) {
                Log::error('Error fetching users: ' . $e->getMessage());
            }

            sleep(1);
        }

        return $allUsers;
    }

    private function updateOrAddUsers(array $usersFromApi): array
    {
        $processedUsers = array_map([$this, 'extractUserData'], $usersFromApi);
        $filteredUsers = array_filter($processedUsers);

        $latestUsers = [];

        foreach ($filteredUsers as $user) {
            $latestUsers[$user['email']] = $user;
        }

        $filteredUsers = array_values($latestUsers);

        $totalInserts = 0;
        $totalUpdates = 0;

        foreach (array_chunk($filteredUsers, 500) as $chunk) {
            $existingChunkUsers = SpecialUser::whereIn('email', array_column($chunk, 'email'))
                ->pluck('email')
                ->flip();

            $toInsert = [];
            $toUpdate = [];

            foreach ($chunk as $userData) {
                if (isset($existingChunkUsers[$userData['email']])) {
                    $toUpdate[] = $userData;
                } else {
                    $toInsert[] = $userData;
                }
            }

            if ($toUpdate) {
                $this->bulkUpdate($toUpdate);
                $totalUpdates += count($toUpdate);
            }

            if ($toInsert) {
                $this->bulkInsert($toInsert);
                $totalInserts += count($toInsert);
            }
        }

        return [$totalInserts, $totalUpdates];
    }

    private function bulkUpdate(array $toUpdate): void
    {
        $chunkedUpdates = array_chunk($toUpdate, 1000);

        foreach ($chunkedUpdates as $chunk) {
            list($updatesSQL, $bindings) = $this->prepareUpdateSQLAndBindings($chunk);
            DB::statement("
                INSERT INTO special_users (email, first_name, last_name, age)
                VALUES {$updatesSQL}
                ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), age = VALUES(age)", $bindings);
        }
    }

    private function bulkInsert(array $toInsert): void
    {
        SpecialUser::insert($toInsert);
    }


    private function prepareUpdateSQLAndBindings(array $chunk): array
    {
        $updatesSQL = [];
        $bindings = [];

        foreach ($chunk as $userData) {
            $updatesSQL[] = "(?, ?, ?, ?)";
            $bindings = array_merge($bindings, array_values($userData));
        }

        return [join(", ", $updatesSQL), $bindings];
    }

    private function extractUserData(array $user): ?array
    {
        $requiredKeys = ['name.first', 'name.last', 'email', 'dob.age'];
        foreach ($requiredKeys as $key) {
            if (blank(data_get($user, $key))) return null;
        }

        return [
            'first_name' => $user['name']['first'],
            'last_name' => $user['name']['last'],
            'email' => $user['email'],
            'age' => (int) $user['dob']['age'],
        ];
    }

    private function partitionUsersBasedOnExistence(array $users, $existingUsers): array
    {
        return array_reduce($users, function ($carry, $user) use ($existingUsers) {
            if (isset($existingUsers[$user['email']])) {
                $carry['toUpdate'][] = $user;
            } else {
                $carry['toInsert'][] = $user;
            }
            return $carry;
        }, ['toInsert' => [], 'toUpdate' => []]);
    }
}
