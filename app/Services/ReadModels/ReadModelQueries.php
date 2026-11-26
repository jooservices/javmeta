<?php

declare(strict_types=1);

namespace App\Services\ReadModels;

/** The SQL that builds the search tables of the UI API from the normalised catalogue tables. */
final class ReadModelQueries
{
    /** One row per movie with genres, canonical performer ids, media counts and sources (grouped sub-selects, no per-row queries). */
    public const MOVIES = <<<'SQL'
WITH g AS (SELECT mg.movie_id, array_agg(DISTINCT gn.label_raw) a FROM movie_genres mg JOIN genres gn ON gn.id = mg.genre_id GROUP BY mg.movie_id),
     pf AS (SELECT mp.movie_id, array_agg(DISTINCT COALESCE(p.canonical_performer_id, p.id)) ids FROM movie_performers mp JOIN performers p ON p.id = mp.performer_id GROUP BY mp.movie_id),
     md AS (SELECT movie_id, count(*) FILTER (WHERE kind='magnet') dl, count(*) FILTER (WHERE kind='hls') hls, count(*) FILTER (WHERE kind='gallery') gal FROM movie_media GROUP BY movie_id),
     sr AS (SELECT movie_id, array_agg(DISTINCT site_slug) a FROM movie_sources GROUP BY movie_id)
SELECT m.id, m.display_code, m.code_normalized, m.title_jp, m.title_en, m.release_date, EXTRACT(year FROM m.release_date)::int, m.runtime_minutes, m.censored,
       m.maker, m.label, m.series, m.community_score, m.completeness_tier, m.cover_url, m.cover_thumb_url, m.crawled_at, m.first_seen_at, m.updated_at,
       COALESCE(m.needs_review,false), (m.delisted_at IS NOT NULL), COALESCE(g.a,'{}'), COALESCE(pf.ids,'{}'), NULL,
       COALESCE(md.dl,0), COALESCE(md.hls,0), COALESCE(md.gal,0), COALESCE(sr.a,'{}'),
       lower(concat_ws(' ', m.display_code, m.title_jp, m.title_en, m.maker, m.series))
FROM movies m LEFT JOIN g ON g.movie_id=m.id LEFT JOIN pf ON pf.movie_id=m.id LEFT JOIN md ON md.movie_id=m.id LEFT JOIN sr ON sr.movie_id=m.id
SQL;

    /** One row per canonical performer: the canonical row overlaid with the first non-null value of its source rows. */
    public const PERFORMERS = <<<'SQL'
WITH src AS (
  SELECT COALESCE(canonical_performer_id, id) cid, id, birth_date, height_cm, bust, waist, hip, cup, blood_type, blood_rh, left(bio_text,1500) bio, debut_date, retired_date, location, attrs, name_kanji, name_kana
  FROM performers),
agg AS (
  SELECT cid,
    (array_agg(birth_date ORDER BY id) FILTER (WHERE birth_date IS NOT NULL))[1] birth_date,
    (array_agg(height_cm ORDER BY id) FILTER (WHERE height_cm IS NOT NULL))[1] height_cm,
    (array_agg(bust ORDER BY id) FILTER (WHERE bust IS NOT NULL))[1] bust,
    (array_agg(waist ORDER BY id) FILTER (WHERE waist IS NOT NULL))[1] waist,
    (array_agg(hip ORDER BY id) FILTER (WHERE hip IS NOT NULL))[1] hip,
    (array_agg(cup ORDER BY id) FILTER (WHERE cup IS NOT NULL AND cup<>''))[1] cup,
    (array_agg(blood_type ORDER BY id) FILTER (WHERE blood_type IS NOT NULL AND blood_type<>''))[1] blood_type,
    (array_agg(blood_rh ORDER BY id) FILTER (WHERE blood_rh IS NOT NULL AND blood_rh<>''))[1] blood_rh,
    (array_agg(bio ORDER BY id) FILTER (WHERE bio IS NOT NULL AND bio<>''))[1] bio,
    (array_agg(debut_date ORDER BY id) FILTER (WHERE debut_date IS NOT NULL))[1] debut_date,
    (array_agg(retired_date ORDER BY id) FILTER (WHERE retired_date IS NOT NULL))[1] retired_date,
    (array_agg(location ORDER BY id) FILTER (WHERE location IS NOT NULL AND location<>''))[1] location,
    (array_agg(name_kanji ORDER BY id) FILTER (WHERE name_kanji IS NOT NULL AND name_kanji<>''))[1] name_kanji,
    (array_agg(name_kana ORDER BY id) FILTER (WHERE name_kana IS NOT NULL AND name_kana<>''))[1] name_kana,
    (array_agg((attrs - 'extra') || jsonb_build_object('extra', (COALESCE(attrs->'extra','{}'::jsonb) - 'filmography' - 'filmography_next_url')) ORDER BY id) FILTER (WHERE jsonb_typeof(attrs) = 'object' AND attrs <> '{}'::jsonb))[1] attrs,
    count(*) sources
  FROM src GROUP BY cid),
cnt AS (SELECT COALESCE(p.canonical_performer_id,p.id) cid, count(DISTINCT mp.movie_id) n FROM movie_performers mp JOIN performers p ON p.id=mp.performer_id GROUP BY 1),
al AS (SELECT COALESCE(p.canonical_performer_id,p.id) cid, array_agg(DISTINCT a.alias) a FROM performer_aliases a JOIN performers p ON p.id=a.performer_id GROUP BY 1),
ph AS (SELECT COALESCE(p.canonical_performer_id,p.id) cid, (array_agg(pm.url ORDER BY pm.id) FILTER (WHERE pm.kind='image'))[1] photo, count(*) FILTER (WHERE pm.kind='gallery') gal FROM performer_media pm JOIN performers p ON p.id=pm.performer_id GROUP BY 1)
SELECT c.id, c.name_romaji, COALESCE(NULLIF(c.name_kanji,''), a.name_kanji), COALESCE(NULLIF(c.name_kana,''), a.name_kana),
  COALESCE(c.birth_date, a.birth_date), COALESCE(c.height_cm, a.height_cm), COALESCE(c.bust, a.bust), COALESCE(c.waist, a.waist), COALESCE(c.hip, a.hip),
  COALESCE(NULLIF(c.cup,''), a.cup), COALESCE(NULLIF(c.blood_type,''), a.blood_type), COALESCE(NULLIF(c.blood_rh,''), a.blood_rh),
  COALESCE(NULLIF(left(c.bio_text,1500),''), a.bio), COALESCE(c.debut_date, a.debut_date), COALESCE(c.retired_date, a.retired_date), COALESCE(NULLIF(c.location,''), a.location, NULLIF(btrim(regexp_replace(substring(COALESCE(NULLIF(left(c.bio_text,1500),''), a.bio) from 'Hometown:\s*(.*)$'), '\s+(Hobby|Special|Height|Cup|Bust|Waist|Hips|Blood|Age|D\.O\.B)[: ].*$', '')), '')),
  COALESCE(a.attrs, '{}'::jsonb), COALESCE(cnt.n,0), COALESCE(al.a,'{}'), ph.photo, COALESCE(ph.gal,0), COALESCE(a.sources,1),
  c.crawled_at, c.first_seen_at, COALESCE(c.needs_review,false),
  lower(concat_ws(' ', c.name_romaji, COALESCE(NULLIF(c.name_kanji,''), a.name_kanji), COALESCE(NULLIF(c.name_kana,''), a.name_kana), array_to_string(al.a,' ')))
FROM performers c LEFT JOIN agg a ON a.cid=c.id LEFT JOIN cnt ON cnt.cid=c.id LEFT JOIN al ON al.cid=c.id LEFT JOIN ph ON ph.cid=c.id
WHERE c.canonical_performer_id IS NULL
SQL;

    public const PERFORMER_PAIRS = [
        "INSERT INTO rm_perf_attr SELECT DISTINCT pid, 'genre', g FROM rm_movies m, unnest(m.performer_ids) pid, unnest(m.genres) g WHERE g <> ''",
        "INSERT INTO rm_perf_attr SELECT DISTINCT pid, 'maker', m.maker FROM rm_movies m, unnest(m.performer_ids) pid WHERE m.maker IS NOT NULL",
        "INSERT INTO rm_perf_attr SELECT DISTINCT pid, 'series', m.series FROM rm_movies m, unnest(m.performer_ids) pid WHERE m.series IS NOT NULL",
        "INSERT INTO rm_perf_attr SELECT DISTINCT pid, 'label', m.label FROM rm_movies m, unnest(m.performer_ids) pid WHERE m.label IS NOT NULL",
    ];
}
