\set ON_ERROR_STOP on
\getenv target_database DB_DATABASE
SELECT format('ALTER DATABASE %I SET timezone TO %L', :'target_database', 'UTC') \gexec
