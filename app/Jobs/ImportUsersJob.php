<?php

namespace App\Jobs;

use App\Events\UserImported;
use App\Models\SpecialUser;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class ImportUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const API_ENDPOINT = 'https://randomuser.me/api/?results=';
    private const BATCH_SIZE = 1000;
    private const SLEEP_TIME = 1;

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

        $results = $this->updateOrAddUsers($usersFromApi);
        $this->logResults($results);

        $this->recordJobResults($results);
    }

    private function logResults(array $results): void
    {
        Log::info('ImportUsersJob results:', $results);
    }

    private function recordJobResults(array $results): void
    {
        DB::table('job_results')->insert([
            'type' => 'user_import',
            'status' => 'completed',
            'data' => json_encode([
                'total' => SpecialUser::count(),
                'added' => $results['totalInserts'],
                'updated' => $results['totalUpdates']
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
        $processedUsers = $this->processAndFilterUsers($usersFromApi);

        $totalInserts = 0;
        $totalUpdates = 0;

        foreach (array_chunk($processedUsers, 500) as $chunk) {
            $existingChunkUsers = $this->fetchExistingUsersByEmail(array_column($chunk, 'email'));

            $result = $this->partitionUsersBasedOnExistence($chunk, $existingChunkUsers);
            $toInsert = $result['toInsert'];
            $toUpdate = $result['toUpdate'];

            $totalUpdates += $this->handleUserUpdates($toUpdate);
            $totalInserts += $this->handleUserInsertions($toInsert);
        }

        Log::info('Returning from updateOrAddUsers', ['totalInserts' => $totalInserts, 'totalUpdates' => $totalUpdates]);

        return [
            'totalInserts' => $totalInserts,
            'totalUpdates' => $totalUpdates
        ];
    }

    private function processAndFilterUsers(array $usersFromApi): array
    {
        $processedUsers = array_map([$this, 'extractUserData'], $usersFromApi);

        $filteredUsers = array_filter($processedUsers);

        $latestUsers = [];

        foreach ($filteredUsers as $user) {
            $latestUsers[$user['email']] = $user;
        }

        return array_values($latestUsers);
    }

    private function fetchExistingUsersByEmail(array $emails): Collection
    {
        return SpecialUser::whereIn('email', $emails)->pluck('email')->flip();
    }

    private function handleUserUpdates(array $toUpdate): int
    {
        $this->bulkUpdate($toUpdate);
        return count($toUpdate);
    }

    private function handleUserInsertions(array $toInsert): int
    {
        $this->bulkInsert($toInsert);
        return count($toInsert);
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
}
