\set ON_ERROR_STOP on
-- Run as cluster administrator, connected to postgres. Passwords are environment-only.
\getenv xcrawler_password XCRAWLER_PASSWORD
\getenv xcrawler_ro_password XCRAWLER_RO_PASSWORD
SELECT length(:'xcrawler_password') > 0 AND length(:'xcrawler_ro_password') > 0 AS passwords_present \gset
\if :passwords_present
\else
  \echo 'Both XCRAWLER_PASSWORD and XCRAWLER_RO_PASSWORD must be nonempty.'
  -- ON_ERROR_STOP guarantees a nonzero exit even on older psql clients.
  SELECT 1 / 0;
\endif
SELECT 'CREATE ROLE xcrawler LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'xcrawler') \gexec
SELECT 'CREATE ROLE xcrawler_ro LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'xcrawler_ro') \gexec
ALTER ROLE xcrawler LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'xcrawler_password';
ALTER ROLE xcrawler_ro LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'xcrawler_ro_password';
SELECT 'CREATE DATABASE xcrawler OWNER xcrawler' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'xcrawler') \gexec
ALTER DATABASE xcrawler OWNER TO xcrawler;
REVOKE ALL ON DATABASE xcrawler FROM PUBLIC;
GRANT CONNECT ON DATABASE xcrawler TO xcrawler_ro;
ALTER ROLE xcrawler_ro IN DATABASE xcrawler SET default_transaction_read_only = on;
\connect xcrawler
CREATE EXTENSION IF NOT EXISTS pg_trgm;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO xcrawler;
GRANT USAGE ON SCHEMA public TO xcrawler_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO xcrawler_ro;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO xcrawler_ro;
-- Migrations must run as xcrawler: default privileges belong to the object creator.
ALTER DEFAULT PRIVILEGES FOR ROLE xcrawler IN SCHEMA public GRANT SELECT ON TABLES TO xcrawler_ro;
ALTER DEFAULT PRIVILEGES FOR ROLE xcrawler IN SCHEMA public GRANT SELECT ON SEQUENCES TO xcrawler_ro;
