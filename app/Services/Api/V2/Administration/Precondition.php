<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Administration;

use App\Http\Api\V2\ProblemException;
use App\Models\User;
use Illuminate\Http\Request;

/** ETag and If-Match handling of the administration resources, and who is acting. */
final class Precondition
{
    /** The version tag of a resource: a short hash of its JSON form. */
    public static function etag(mixed $resource): string
    {
        return '"' . substr(sha1(json_encode($resource, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 16) . '"';
    }

    /** 428 when the header is missing, 412 when it does not match the current resource. */
    public static function assertMatches(Request $request, mixed $current): void
    {
        $sent = (string) $request->headers->get('If-Match', '');

        if ($sent === '') {
            throw ProblemException::preconditionRequired();
        }

        if (! hash_equals(self::etag($current), $sent)) {
            throw ProblemException::preconditionFailed();
        }
    }

    public static function actor(Request $request): string
    {
        $user = $request->user('web');

        return $user instanceof User ? $user->email : 'unknown';
    }

    public static function actorId(Request $request): ?int
    {
        $user = $request->user('web');

        return $user instanceof User ? (int) $user->getKey() : null;
    }
}
