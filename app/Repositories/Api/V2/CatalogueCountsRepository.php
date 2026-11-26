<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** How many movies and performers the catalogue holds, for the public summary of the top bar. */
final class CatalogueCountsRepository
{
    public function movies(): int
    {
        if (Schema::hasTable('rm_movies')) {
            return DB::table('rm_movies')->where('delisted', false)->count();
        }

        return DB::table('movies')->whereNull('delisted_at')->count();
    }

    public function performers(): int
    {
        return DB::table(Schema::hasTable('rm_performers') ? 'rm_performers' : 'performers')->count();
    }
}
