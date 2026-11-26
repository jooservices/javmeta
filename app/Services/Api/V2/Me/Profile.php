<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

use App\Http\Api\V2\ProblemException;
use App\Models\User;
use App\Repositories\Api\V2\MeSettingsRepository;
use App\Services\Api\V2\UserView;
use Illuminate\Support\Facades\Hash;

/** The signed-in user's own account: profile, counters, name and password. */
final class Profile
{
    public function __construct(
        private readonly Marks $marks,
        private readonly Follows $follows,
        private readonly SavedSearches $searches,
        private readonly MeSettingsRepository $settings,
    ) {}

    /** @return array{user: array<string, mixed>, counters: array<string, int>} */
    public function show(int $userId): array
    {
        $user = UserView::from($this->find($userId));

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'created_at' => $user->createdAt],
            'counters' => [
                'marks' => $this->marks->count($userId),
                'follows' => $this->follows->count($userId),
                'saved_searches' => $this->searches->count($userId),
                'download_requests' => $this->settings->downloadRequests($userId),
            ],
        ];
    }

    public function update(int $userId, ?string $name, ?string $currentPassword, ?string $newPassword): UserView
    {
        $user = $this->find($userId);

        if ($name === null && $newPassword === null) {
            throw ProblemException::invalid('Nothing to change.');
        }

        if ($newPassword !== null && ($currentPassword === null || $currentPassword === '')) {
            throw ProblemException::invalid('The current password is required.', [['field' => 'current_password', 'code' => 'required']]);
        }

        if ($newPassword !== null && ! Hash::check((string) $currentPassword, $user->password)) {
            throw ProblemException::invalid('The current password is wrong.', [['field' => 'current_password', 'code' => 'incorrect']]);
        }

        $user->fill(array_filter(['name' => $name === null ? null : trim($name), 'password' => $newPassword], static fn(?string $value): bool => $value !== null))->save();

        return UserView::from($user->refresh());
    }

    private function find(int $userId): User
    {
        return User::query()->find($userId) ?? throw ProblemException::notFound('user_not_found', 'No such user.');
    }
}
