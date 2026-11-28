<?php

declare(strict_types=1);

namespace App\Services\Api\V2;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\UserRepository;
use Illuminate\Support\Facades\Auth;

/** User accounts for the administrator. */
final class UserDirectory
{
    public function __construct(private readonly UserRepository $users) {}

    /** @return list<UserView> */
    public function all(): array
    {
        return array_map(UserView::from(...), $this->users->all());
    }

    public function create(string $name, string $email, string $password, string $role): UserView
    {
        $email = mb_strtolower($email);

        if ($this->users->emailExists($email)) {
            throw ProblemException::conflict('email_taken', 'A user with this email already exists.');
        }

        return UserView::from($this->users->create($name, $email, $password, $role));
    }

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes): UserView
    {
        $user = $this->users->find($id) ?? throw ProblemException::notFound('user_not_found', 'No such user.');

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

        $this->users->delete($id);
    }
}
