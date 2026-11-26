<?php

declare(strict_types=1);

namespace App\Services\Api\V2;

use App\Http\Api\V2\ProblemException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/** User accounts for the administrator. */
final class UserDirectory
{
    /** @return list<UserView> */
    public function all(): array
    {
        return User::query()->orderBy('id')->get()->map(UserView::from(...))->all();
    }

    public function create(string $name, string $email, string $password, string $role): UserView
    {
        $email = mb_strtolower($email);

        if (User::query()->where('email', $email)->exists()) {
            throw ProblemException::conflict('email_taken', 'A user with this email already exists.');
        }

        return UserView::from(User::query()->create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role]));
    }

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes): UserView
    {
        $user = User::query()->find($id) ?? throw ProblemException::notFound('user_not_found', 'No such user.');

        if ($changes === []) {
            throw ProblemException::invalid('Nothing to change.');
        }

        if (isset($changes['email'])) {
            $changes['email'] = mb_strtolower((string) $changes['email']);
        }

        $user->fill($changes)->save();

        return UserView::from($user->refresh());
    }

    public function delete(int $id): void
    {
        if ((int) Auth::guard('web')->id() === $id) {
            throw ProblemException::conflict('cannot_delete_self', 'You cannot delete your own account.');
        }

        User::query()->whereKey($id)->delete();
    }
}
