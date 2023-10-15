<?php

namespace App\Http\Controllers;

use App\Models\SpecialUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;

class UserController extends Controller
{
    private const API_ENDPOINT = 'https://randomuser.me/api/?results=';
    private const BATCH_SIZE = 1000;

    public function importUsers(): JsonResponse
    {
        $usersFromApi = $this->fetchUsersInBatches(5000);
        [$addedCount, $updatedCount] = $this->updateOrAddUsers($usersFromApi);

        return response()->json([
            'total' => SpecialUser::count(),
            'added' => $addedCount,
            'updated' => $updatedCount
        ]);
    }

    private function fetchUsersInBatches(int $totalUsers): array
    {
        $client = new Client();
        $promises = array_fill(0, ceil($totalUsers / self::BATCH_SIZE), $client->getAsync(self::API_ENDPOINT . self::BATCH_SIZE));
        $responses = Utils::settle($promises)->wait();

        return array_reduce($responses, function ($carry, $response) {
            if ($response['state'] === 'fulfilled') {
                $data = json_decode($response['value']->getBody(), true);
                $carry = array_merge($carry, $data['results'] ?? []);
            }
            return $carry;
        }, []);
    }

    private function updateOrAddUsers(array $usersFromApi): array
    {
        $filteredUsers = array_filter(array_map([$this, 'extractUserData'], $usersFromApi));

        $latestUsers = array_reduce($filteredUsers, function ($carry, $user) {
            $carry[$user['email']] = $user;
            return $carry;
        }, []);

        $filteredUsers = array_values($latestUsers);
        $existingUsers = SpecialUser::whereIn('email', array_column($filteredUsers, 'email'))->pluck('email')->flip();

        $partitionedUsers = $this->partitionUsersBasedOnExistence($filteredUsers, $existingUsers);

        if ($partitionedUsers['toUpdate']) {
            $this->bulkUpdate($partitionedUsers['toUpdate']);
        }

        if ($partitionedUsers['toInsert']) {
            $this->bulkInsert($partitionedUsers['toInsert']);
        }

        return [count($partitionedUsers['toInsert']), count($partitionedUsers['toUpdate'])];
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
