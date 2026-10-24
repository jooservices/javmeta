<?php

declare(strict_types=1);

namespace App\Services\Media;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MediaPolicy
{
    public function assertText(string $text): void
    {
        if (preg_match('/\b(underage|under[ -]?18|under[ -]?age|minors?|child(?:ren)?|schoolgirls?|loli(?:ta)?|shota)\b|未成年|児童|小学生|中学生|幼女/iu', rawurldecode($text)) === 1) {
            throw ValidationException::withMessages(['media' => 'Từ chối: thông tin có dấu hiệu liên quan người chưa thành niên.']);
        }
    }

    public function assertFile(object $file): void
    {
        $this->assertText((string) $file->path . ' ' . (string) $file->code);
        if ($file->movie_id === null) {
            return;
        }
        $movie = DB::table('movies')->where('id', $file->movie_id)->first();
        if ($movie === null) {
            return;
        }
        $this->assertText(implode(' ', [(string) $movie->title_en, (string) $movie->title_jp, (string) $movie->description]));
        $genres = DB::table('movie_genres')->join('genres', 'genres.id', '=', 'movie_genres.genre_id')->where('movie_genres.movie_id', $file->movie_id)->pluck('genres.label_normalized')->all();
        $this->assertText(implode(' ', $genres));
        $date = $movie->release_date === null ? CarbonImmutable::now() : CarbonImmutable::parse($movie->release_date);
        $births = DB::table('movie_performers')->join('performers', 'performers.id', '=', 'movie_performers.performer_id')->where('movie_performers.movie_id', $file->movie_id)->whereNotNull('performers.birth_date')->pluck('performers.birth_date');
        foreach ($births as $birth) {
            if (CarbonImmutable::parse($birth)->addYears(18)->isAfter($date)) {
                throw ValidationException::withMessages(['media' => 'Từ chối: tuổi diễn viên tại ngày phát hành dưới 18.']);
            }
        }
    }
}
