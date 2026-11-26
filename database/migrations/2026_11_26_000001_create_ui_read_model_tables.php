<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Search tables of the UI API (v2): denormalised copies of the catalogue that the refresh command rebuilds from the normalised
 * tables every 20 minutes. They are derived data (never edited by hand) and exist only on PostgreSQL (arrays, GIN, trigrams).
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::STATEMENTS as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['rm_perf_attr', 'rm_refresh', 'rm_performers', 'rm_movies'] as $table) {
            DB::unprepared("DROP TABLE IF EXISTS {$table}");
        }
    }

    private const STATEMENTS = [
        <<<'SQL'
        CREATE TABLE public.rm_movies (
            id integer PRIMARY KEY,
            code text NOT NULL,
            code_norm text NOT NULL,
            title_jp text,
            title_en text,
            release_date date,
            year smallint,
            runtime integer,
            censored smallint,
            maker text,
            label text,
            series text,
            score numeric,
            tier smallint,
            cover_url text,
            cover_thumb_url text,
            crawled_at timestamptz,
            first_seen_at timestamptz,
            updated_at timestamptz,
            needs_review boolean NOT NULL DEFAULT false,
            delisted boolean NOT NULL DEFAULT false,
            genres text[] NOT NULL DEFAULT '{}',
            performer_ids integer[] NOT NULL DEFAULT '{}',
            performer_names text,
            dl_count integer NOT NULL DEFAULT 0,
            hls_count integer NOT NULL DEFAULT 0,
            gallery_count integer NOT NULL DEFAULT 0,
            sources text[] NOT NULL DEFAULT '{}',
            search_text text NOT NULL DEFAULT ''
        )
        SQL,
        <<<'SQL'
        CREATE UNIQUE INDEX rm_movies_code_idx ON public.rm_movies (code)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_codenorm_idx ON public.rm_movies (code_norm)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_release_idx ON public.rm_movies (release_date DESC NULLS LAST, id)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_crawled_idx ON public.rm_movies (crawled_at DESC NULLS LAST, id)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_genres_idx ON public.rm_movies USING gin (genres)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_perf_idx ON public.rm_movies USING gin (performer_ids)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_sources_idx ON public.rm_movies USING gin (sources)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_search_idx ON public.rm_movies USING gin (search_text gin_trgm_ops)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_maker_idx ON public.rm_movies (maker)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_series_idx ON public.rm_movies (series)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_label_idx ON public.rm_movies (label)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_movies_year_idx ON public.rm_movies (year)
        SQL,
        <<<'SQL'
        CREATE TABLE public.rm_performers (
            id integer PRIMARY KEY,
            name_romaji text,
            name_kanji text,
            name_kana text,
            birth_date date,
            height_cm smallint,
            bust smallint,
            waist smallint,
            hip smallint,
            cup text,
            blood_type text,
            blood_rh text,
            bio_text text,
            debut_date date,
            retired_date date,
            location text,
            attrs jsonb NOT NULL DEFAULT '{}',
            movie_count integer NOT NULL DEFAULT 0,
            aliases text[] NOT NULL DEFAULT '{}',
            photo_url text,
            photo_count integer NOT NULL DEFAULT 0,
            source_count integer NOT NULL DEFAULT 0,
            crawled_at timestamptz,
            first_seen_at timestamptz,
            needs_review boolean NOT NULL DEFAULT false,
            search_text text NOT NULL DEFAULT ''
        )
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_count_idx ON public.rm_performers (movie_count DESC, id)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_name_idx ON public.rm_performers (name_romaji)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_crawled_idx ON public.rm_performers (crawled_at DESC NULLS LAST)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_debut_idx ON public.rm_performers (first_seen_at DESC NULLS LAST)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_cup_idx ON public.rm_performers (cup)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_blood_idx ON public.rm_performers (blood_type)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_loc_idx ON public.rm_performers (location)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_birth_idx ON public.rm_performers (birth_date)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_search_idx ON public.rm_performers USING gin (search_text gin_trgm_ops)
        SQL,
        <<<'SQL'
        CREATE TABLE public.rm_refresh (
            name text PRIMARY KEY,
            refreshed_at timestamptz NOT NULL,
            row_count integer NOT NULL,
            duration_ms integer NOT NULL
        )
        SQL,
        <<<'SQL'
        CREATE TABLE public.rm_perf_attr (
            pid integer NOT NULL,
            kind varchar(8) NOT NULL,
            name text NOT NULL
        )
        SQL,
        <<<'SQL'
        CREATE INDEX rm_perf_attr_kind_name_idx ON public.rm_perf_attr (kind, name)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_perf_attr_pid_idx ON public.rm_perf_attr (pid)
        SQL,
        <<<'SQL'
        CREATE INDEX rm_performers_lowername_idx ON public.rm_performers (lower(name_romaji))
        SQL,
    ];
};
