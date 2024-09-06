--
-- PostgreSQL database dump
--

-- Dumped from database version 14.13 (Ubuntu 14.13-0ubuntu0.22.04.1)
-- Dumped by pg_dump version 14.13 (Ubuntu 14.13-0ubuntu0.22.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pg_math; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_math WITH SCHEMA public;


--
-- Name: EXTENSION pg_math; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_math IS 'statistical functions for postgresql';


--
-- Name: pgstattuple; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgstattuple WITH SCHEMA public;


--
-- Name: EXTENSION pgstattuple; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgstattuple IS 'show tuple-level statistics';


--
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION vector IS 'vector data type and ivfflat access method';


--
-- Name: has_content; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.has_content AS ENUM (
    'FALSE',
    'SELF',
    'TRAIL',
    'BOTH'
);


--
-- Name: media_info; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.media_info AS (
	media_url text,
	preview_url text,
	title text,
	description text
);


--
-- Name: calculate_table_column_sizes(text, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.calculate_table_column_sizes(target_table_name text, sample_ratio double precision) RETURNS TABLE(column_name text, column_size text)
    LANGUAGE plpgsql
    AS $$
DECLARE
  col_name text;
  query text;
BEGIN
  -- Initialize the query with SELECT statement to calculate sizes
  query := 'SELECT unnest(array[';

  -- Dynamically retrieve column names and construct the size calculation part of the query
  FOR col_name IN
    SELECT c.column_name FROM information_schema.columns c
    WHERE c.table_name = target_table_name
    AND c.data_type != 'boolean'
    AND c.table_schema = 'public'  -- Adjust schema if needed
  LOOP
    query := query || quote_literal(col_name) || ', ';
  END LOOP;

  -- Remove trailing comma and space from the query and close the array
  query := rtrim(query, ', ') || ']) AS column_name, unnest(array[';

  -- Dynamically construct the size calculation part of the query
  FOR col_name IN
    SELECT c.column_name FROM information_schema.columns c
    WHERE c.table_name = target_table_name
    AND c.data_type != 'boolean'
    AND c.table_schema = 'public'  -- Adjust schema if needed
  LOOP
    query := query || 'pg_size_pretty(SUM(pg_column_size(' || quote_ident(col_name) || ')) / ' || sample_ratio || ' * 100), ';
  END LOOP;

  -- Remove trailing comma and space from the query and close the array
  query := rtrim(query, ', ') || ']) AS column_size FROM ' || quote_ident(target_table_name) || ' TABLESAMPLE SYSTEM (' || (sample_ratio * 100)::text || ')';

  -- Execute the dynamically constructed query
  RETURN QUERY EXECUTE query;
END;
$$;


--
-- Name: clean_up_images(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.clean_up_images() RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    DELETE FROM images
    WHERE NOT EXISTS (
        SELECT 1
        FROM images_posts
        WHERE images_posts.image_id = images.image_id
    );
END;
$$;


--
-- Name: clean_up_tags(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.clean_up_tags() RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
DELETE FROM tags
WHERE NOT EXISTS (
    SELECT 1
    FROM posts_tags
    WHERE posts_tags.tag_id = tags.tag_id
);
END;
$$;


--
-- Name: delete_blog(character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.delete_blog(target_blog_uuid character varying) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    deleted_count INTEGER;
BEGIN
    -- Delete related entries in images_posts and posts_tags by joining with posts
    DELETE FROM images_posts
    USING posts
    WHERE posts.post_id = images_posts.post_id
    AND posts.blog_uuid = target_blog_uuid;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RAISE NOTICE 'Deleted % images_posts entries.', deleted_count;
    
    DELETE FROM posts_tags
    USING posts
    WHERE posts.post_id = posts_tags.post_id
    AND posts.blog_uuid = target_blog_uuid;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RAISE NOTICE 'Deleted % posts_tags entries.', deleted_count;
    
    -- Now delete the posts themselves
    DELETE FROM posts
    WHERE blog_uuid = target_blog_uuid;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RAISE NOTICE 'Deleted % posts entries.', deleted_count;
    
    -- Delete entries in archiver_leases and active_queries
    DELETE FROM archiver_leases WHERE blog_uuid = target_blog_uuid;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RAISE NOTICE 'Deleted % archiver_leases entries.', deleted_count;
    
    DELETE FROM active_queries WHERE blog_uuid = target_blog_uuid;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RAISE NOTICE 'Deleted % active_queries entries.', deleted_count;

    UPDATE wordclouded_blogs SET last_stats_update = NULL WHERE blog_uuid = target_blog_uuid;
    RAISE NOTICE 'Marked wordclouded_blogs last_stats_update as NULL to indicate lazy rewrite';
    
    -- Finally, delete the blog entry in blogstats
    DELETE FROM blogstats WHERE blog_uuid = target_blog_uuid;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RAISE NOTICE 'Deleted % blogstats entry.', deleted_count;
END;
$$;


--
-- Name: get_all_lexeme_stats(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_all_lexeme_stats() RETURNS TABLE(lexeme text, ndoc integer, nentry integer)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    WITH ts_stats AS (
        SELECT
            tsr.word,
            tsr.ndoc as _ndoc,
            tsr.nentry as _nentry
        FROM
            ts_stat('SELECT english_ts_vector FROM posts') as tsr
    ),
    filtered_tokens AS (
        SELECT
            word,
            MAX(parsed_token.tokid) AS max_tokid
        FROM
            ts_stats
        CROSS JOIN LATERAL ts_parse('default', word) AS parsed_token
        GROUP BY
            word
        HAVING
            MAX(CASE WHEN parsed_token.tokid IN (5, 6, 9, 18, 19) THEN 1 ELSE 0 END) = 0
            AND MAX(parsed_token.tokid) IN (1, 17)
    )
    SELECT
        word as lexeme, _ndoc as ndoc, _nentry as nentry
    FROM
        ts_stats
    WHERE
        word IN (SELECT word FROM filtered_tokens);
END;
$$;


--
-- Name: get_blog_lexeme_stats(character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_blog_lexeme_stats(blog_uuid character varying) RETURNS TABLE(lexeme text, ndoc integer, nentry integer)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    WITH ts_stats AS (
        SELECT
            tsr.word,
            tsr.ndoc as _ndoc,
            tsr.nentry as _nentry
        FROM
            ts_stat(format('SELECT english_ts_vector FROM posts WHERE blog_uuid = %L', blog_uuid)) as tsr
    ),
    filtered_tokens AS (
        SELECT
            word,
            MAX(parsed_token.tokid) AS max_tokid
        FROM
            ts_stats
        CROSS JOIN LATERAL ts_parse('default', word) AS parsed_token
        GROUP BY
            word
        HAVING
            MAX(CASE WHEN parsed_token.tokid IN (5, 6, 9, 18, 19) THEN 1 ELSE 0 END) = 0
            AND MAX(parsed_token.tokid) IN (1, 17)
    )
    SELECT
        word as lexeme, _ndoc as ndoc, _nentry as nentry
    FROM
        ts_stats
    WHERE
        word IN (SELECT word FROM filtered_tokens);
END;
$$;


--
-- Name: get_blog_lexeme_stats(character varying, timestamp without time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_blog_lexeme_stats(blog_uuid character varying, before timestamp without time zone DEFAULT now()) RETURNS TABLE(lexeme text, ndoc integer, nentry integer)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    WITH ts_stats AS (
        SELECT
            tsr.word,
            tsr.ndoc as _ndoc,
            tsr.nentry as _nentry
        FROM
            ts_stat(format('SELECT english_ts_vector FROM posts WHERE blog_uuid = %L and archived_date <= %L', blog_uuid, before)) as tsr
    ),
    filtered_tokens AS (
        SELECT
            word,
            MAX(parsed_token.tokid) AS max_tokid
        FROM
            ts_stats
        CROSS JOIN LATERAL ts_parse('default', word) AS parsed_token
        GROUP BY
            word
        HAVING
            MAX(CASE WHEN parsed_token.tokid IN (5, 6, 9, 18, 19) THEN 1 ELSE 0 END) = 0
            AND MAX(parsed_token.tokid) IN (1, 17)
    )
    SELECT
        word as lexeme, _ndoc as ndoc, _nentry as nentry
    FROM
        ts_stats
    WHERE
        word IN (SELECT word FROM filtered_tokens);
END;
$$;


--
-- Name: get_blog_lexeme_stats(character varying, character varying, character varying, timestamp without time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_blog_lexeme_stats(blog_uuid character varying, vec_column character varying, fields character varying, before timestamp without time zone DEFAULT '1971-01-01 12:01:01'::timestamp without time zone) RETURNS TABLE(lexeme text, ndoc integer, nentry integer)
    LANGUAGE plpgsql
    AS $$
BEGIN
	RETURN QUERY
	WITH ts_stats AS (
	    SELECT
		   tsr.word,
		   tsr.ndoc as _ndoc,
		   tsr.nentry as _nentry
	    FROM
		   ts_stat(format('SELECT %L FROM posts WHERE blog_uuid = %L and archived_date >= %L', blog_uuid, before), fields) as tsr
	),
	filtered_tokens AS (
	    SELECT
		   word,
		   MAX(parsed_token.tokid) AS max_tokid
	    FROM
		   ts_stats
	    CROSS JOIN LATERAL ts_parse('default', word) AS parsed_token
	    GROUP BY
		   word
	    HAVING
		   MAX(CASE WHEN parsed_token.tokid IN (5, 6, 9, 18, 19) THEN 1 ELSE 0 END) = 0
		   AND MAX(parsed_token.tokid) IN (1, 17)
	)
	SELECT
	    word as lexeme, _ndoc as ndoc, _nentry as nentry
	FROM
	    ts_stats
	WHERE
	    word IN (SELECT word FROM filtered_tokens);
END; 
$$;


--
-- Name: get_column_sizes(text, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_column_sizes(table_name text, sample_ratio double precision) RETURNS TABLE(column_name text, column_size text)
    LANGUAGE plpgsql
    AS $$
DECLARE
    column_query text := '';
    total_query text;
    result RECORD;
    total_size bigint := 0;
BEGIN
    -- Build a dynamic query for individual column sizes
    FOR column_name IN SELECT column_name FROM information_schema.columns WHERE table_name = table_name LOOP
        column_query := column_query || 'SUM(pg_column_size(' || quote_ident(column_name) || ')) AS ' || quote_ident(column_name) || ', ';
    END LOOP;
    
    -- Trim the trailing comma
    column_query := rtrim(column_query, ', ');

    -- Construct the final query including TABLESAMPLE
    column_query := 'SELECT ' || column_query || ' FROM ' || quote_ident(table_name) || ' TABLESAMPLE SYSTEM (' || (sample_ratio * 100)::text || ')';

    -- Execute the query for column sizes
    FOR result IN EXECUTE column_query LOOP
        -- Loop through all fields in the record and output the sizes
        FOR i IN 1..array_length(array[result.*], 1) LOOP
            column_name := (array_keys(array[result.*]))[i];
            column_size := pg_size_pretty((array[result.*])[i]::bigint);
            total_size := total_size + (array[result.*])[i]::bigint;
            RETURN NEXT;
        END LOOP;
    END LOOP;

    -- After looping through columns, return the total size
    RETURN QUERY SELECT 'Total Size' AS column_name, pg_size_pretty(total_size) AS column_size;
END;
$$;


--
-- Name: get_deletability(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_deletability() RETURNS TABLE(blog_uuid character varying, blog_name character varying, indexed_post_count integer, index_request_count integer, time_last_indexed timestamp without time zone, post_ratio double precision, staleness double precision, score double precision)
    LANGUAGE sql STABLE
    AS $$
WITH total_posts AS (
    SELECT SUM(indexed_post_count) AS total FROM blogstats
), date_range AS (
    SELECT
        EXTRACT(EPOCH FROM MIN(time_last_indexed)) AS min_date,
        EXTRACT(EPOCH FROM MAX(time_last_indexed)) AS max_date
    FROM blogstats
), scored_blogstats AS (
    SELECT
        blog_uuid,
        blog_name,
        indexed_post_count,
        index_request_count,
        time_last_indexed,
        indexed_post_count::FLOAT / (SELECT total::FLOAT FROM total_posts) AS post_ratio,
        (((EXTRACT(EPOCH FROM time_last_indexed) - (SELECT min_date FROM date_range))) /
        NULLIF(((SELECT max_date FROM date_range) - (SELECT min_date FROM date_range)), 1.0)) AS recency
    FROM blogstats
)
SELECT
    blog_uuid,
    blog_name,
    indexed_post_count,
    index_request_count,
    time_last_indexed,
    post_ratio,
    1-recency as staleness,
    (1.0/greatest(1, index_request_count)::FLOAT)*post_ratio * (POWER(1-recency, 2.71828182846)) AS score
FROM scored_blogstats ORDER BY score desc;
$$;


--
-- Name: increment_update_counter(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.increment_update_counter() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Increment the sequence
    PERFORM nextval('update_counter_seq');
    RETURN NEW;
END;
$$;


--
-- Name: manually_get_blog_lexeme_stats(text, character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.manually_get_blog_lexeme_stats(query text, fields character varying) RETURNS TABLE(lexeme text, ndoc integer, nentry integer)
    LANGUAGE plpgsql
    AS $$
BEGIN
	RETURN QUERY
	WITH ts_stats AS (
	    SELECT
		   tsr.word,
		   tsr.ndoc as _ndoc,
		   tsr.nentry as _nentry
	    FROM
		   ts_stat(query, fields) as tsr
	),
	filtered_tokens AS (
	    SELECT
		   word,
		   MAX(parsed_token.tokid) AS max_tokid
	    FROM
		   ts_stats
	    CROSS JOIN LATERAL ts_parse('default', word) AS parsed_token
	    GROUP BY
		   word
	    HAVING
		   MAX(CASE WHEN parsed_token.tokid IN (5, 6, 9, 18, 19) THEN 1 ELSE 0 END) = 0
		   AND MAX(parsed_token.tokid) IN (1, 17)
	)
	SELECT
	    word as lexeme, _ndoc as ndoc, _nentry as nentry
	FROM
	    ts_stats
	WHERE
	    word IN (SELECT word FROM filtered_tokens);
END; 
$$;


--
-- Name: name_of(character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.name_of(blog_uuid character varying) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    blog_name TEXT;
BEGIN
    SELECT bl.blog_name INTO blog_name
    FROM blogstats bl
    WHERE bl.blog_uuid = name_of.blog_uuid;
    
    RETURN blog_name;
END;
$$;


--
-- Name: pg_wal_cycle_all(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.pg_wal_cycle_all() RETURNS integer
    LANGUAGE plpgsql
    AS $$
declare
    wal_count int;
    wal_seg varchar;
begin 
    select count(*) - 1 
    into wal_count 
    from pg_ls_dir('pg_wal');

    for wal in 1..wal_count loop 
        select pg_walfile_name(pg_switch_wal()) into wal_seg;
        raise notice 'segment %', wal_seg;
        checkpoint;
    end loop;
    return wal_count;
end;$$;


--
-- Name: ts_vector_to_text(tsvector); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ts_vector_to_text(tsvec tsvector) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    lexeme_record record;
    result_text text := '';
BEGIN
    FOR lexeme_record IN
        SELECT lexeme FROM ts_stat('SELECT tsvec') -- ts_stat helps to extract lexemes from ts_vector
    LOOP
        result_text := result_text || lexeme_record.lexeme || ' ';
    END LOOP;
    RETURN trim(result_text);
END;
$$;


--
-- Name: uuid_of(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.uuid_of(blog_name text) RETURNS character varying
    LANGUAGE plpgsql
    AS $$
DECLARE
    blog_uuid VARCHAR(64);
BEGIN
    SELECT bl.blog_uuid INTO blog_uuid
    FROM blogstats bl
    WHERE bl.blog_name = uuid_of.blog_name;

    RETURN blog_uuid;
END;
$$;


--
-- Name: english_hunspell; Type: TEXT SEARCH DICTIONARY; Schema: public; Owner: -
--

CREATE TEXT SEARCH DICTIONARY public.english_hunspell (
    TEMPLATE = pg_catalog.ispell,
    dictfile = 'en_us', afffile = 'en_us', stopwords = 'english' );


--
-- Name: en_us_hun_simple; Type: TEXT SEARCH CONFIGURATION; Schema: public; Owner: -
--

CREATE TEXT SEARCH CONFIGURATION public.en_us_hun_simple (
    PARSER = pg_catalog."default" );

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR asciiword WITH public.english_hunspell, simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR word WITH public.english_hunspell, simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR numword WITH public.english_hunspell, simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR email WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR url WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR host WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR sfloat WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR version WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR hword_numpart WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR hword_part WITH public.english_hunspell, simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR hword_asciipart WITH public.english_hunspell, simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR numhword WITH public.english_hunspell, simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR asciihword WITH public.english_hunspell, simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR hword WITH public.english_hunspell, simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR url_path WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR file WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR "float" WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR "int" WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hun_simple
    ADD MAPPING FOR uint WITH simple;


--
-- Name: en_us_hunspell; Type: TEXT SEARCH CONFIGURATION; Schema: public; Owner: -
--

CREATE TEXT SEARCH CONFIGURATION public.en_us_hunspell (
    PARSER = pg_catalog."default" );

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR asciiword WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR word WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR numword WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR email WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR url WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR host WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR sfloat WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR version WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR hword_numpart WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR hword_part WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR hword_asciipart WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR numhword WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR asciihword WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR hword WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR url_path WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR file WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR "float" WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR "int" WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us_hunspell
    ADD MAPPING FOR uint WITH simple;


--
-- Name: english_stem_simple; Type: TEXT SEARCH CONFIGURATION; Schema: public; Owner: -
--

CREATE TEXT SEARCH CONFIGURATION public.english_stem_simple (
    PARSER = pg_catalog."default" );

ALTER TEXT SEARCH CONFIGURATION public.english_stem_simple
    ADD MAPPING FOR asciiword WITH english_stem, simple;

ALTER TEXT SEARCH CONFIGURATION public.english_stem_simple
    ADD MAPPING FOR word WITH english_stem, simple;

ALTER TEXT SEARCH CONFIGURATION public.english_stem_simple
    ADD MAPPING FOR numword WITH english_stem, simple;

ALTER TEXT SEARCH CONFIGURATION public.english_stem_simple
    ADD MAPPING FOR hword_part WITH english_stem, simple;

ALTER TEXT SEARCH CONFIGURATION public.english_stem_simple
    ADD MAPPING FOR hword_asciipart WITH english_stem, simple;

ALTER TEXT SEARCH CONFIGURATION public.english_stem_simple
    ADD MAPPING FOR numhword WITH english_stem, simple;

ALTER TEXT SEARCH CONFIGURATION public.english_stem_simple
    ADD MAPPING FOR asciihword WITH english_stem, simple;

ALTER TEXT SEARCH CONFIGURATION public.english_stem_simple
    ADD MAPPING FOR hword WITH english_stem, simple;


SET default_table_access_method = heap;

--
-- Name: active_queries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.active_queries (
    search_id bigint NOT NULL,
    query_text text,
    query_params text DEFAULT '""'::text,
    blog_uuid character varying(64)
);


--
-- Name: active_queries_search_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.active_queries_search_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: active_queries_search_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.active_queries_search_id_seq OWNED BY public.active_queries.search_id;


--
-- Name: analyzed_blogs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.analyzed_blogs (
    blog_uuid character varying(64),
    last_stat_update timestamp without time zone,
    post_count_at_stat integer,
    unuuid integer NOT NULL,
    self_ndoc_total double precision DEFAULT 0,
    self_nentry_total double precision DEFAULT 0,
    trail_ndoc_total double precision DEFAULT 0
);


--
-- Name: analyzed_blogs_unuuid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.analyzed_blogs_unuuid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: analyzed_blogs_unuuid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.analyzed_blogs_unuuid_seq OWNED BY public.analyzed_blogs.unuuid;


--
-- Name: archiver_leases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.archiver_leases (
    leader_uuid uuid NOT NULL,
    blog_uuid character varying(64)
);


--
-- Name: blog_node_map; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.blog_node_map (
    node_id integer,
    blog_uuid text,
    indexed_posts integer,
    is_indexing boolean,
    success boolean,
    last_index_count_modification_time timestamp without time zone
);


--
-- Name: blogstats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.blogstats (
    blog_uuid character varying(64) NOT NULL,
    blog_name character varying(128),
    most_recent_post_id bigint,
    post_id_last_indexed bigint,
    post_id_last_attempted bigint,
    time_last_indexed timestamp without time zone,
    success boolean,
    indexed_post_count integer DEFAULT 0 NOT NULL,
    is_indexing boolean DEFAULT true,
    index_request_count integer DEFAULT 0,
    serverside_posts_reported integer DEFAULT 0,
    last_stats_update timestamp without time zone,
    post_count_at_stat integer
);


--
-- Name: cached_blog_node_map; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cached_blog_node_map (
    blog_uuid text NOT NULL,
    node_id integer,
    established timestamp without time zone,
    last_interaction timestamp without time zone
);


--
-- Name: debug_active_queries; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.debug_active_queries AS
 SELECT aq.search_id,
    bl.blog_name,
    bl.time_last_indexed,
    bl.success,
    bl.indexed_post_count,
    bl.serverside_posts_reported,
    bl.index_request_count,
    bl.is_indexing
   FROM public.active_queries aq,
    public.blogstats bl
  WHERE ((bl.blog_uuid)::text = (aq.blog_uuid)::text);


--
-- Name: debug_frozen_queries; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.debug_frozen_queries AS
 SELECT aq.query_text,
    bs.blog_name,
    bs.success,
    bs.time_last_indexed,
    bs.index_request_count,
    bs.indexed_post_count,
    bs.serverside_posts_reported,
    bs.most_recent_post_id,
    bs.post_id_last_indexed,
    bs.post_id_last_attempted
   FROM public.active_queries aq,
    public.blogstats bs
  WHERE ((bs.blog_uuid)::text = (aq.blog_uuid)::text);


--
-- Name: delete_me_after; Type: TABLE; Schema: public; Owner: -; 
--

CREATE TABLE public.delete_me_after (
    blog_uuid character varying(64) NOT NULL,
    lexeme_id integer NOT NULL,
    curr_freq_id double precision,
    expect_freq double precision
);


--
-- Name: disk_use_pretty; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.disk_use_pretty AS
 SELECT n.nspname AS schema_name,
    c.relname AS table_name,
    pg_size_pretty(pg_table_size((c.oid)::regclass)) AS table_plus_toast_size,
    pg_size_pretty(pg_indexes_size((c.oid)::regclass)) AS indexes_size,
    pg_size_pretty(pg_total_relation_size((c.oid)::regclass)) AS total_size_including_indexes
   FROM ((pg_class c
     LEFT JOIN pg_namespace n ON ((n.oid = c.relnamespace)))
     LEFT JOIN pg_tablespace t ON ((c.reltablespace = t.oid)))
  WHERE ((c.relkind = 'r'::"char") AND (n.nspname = 'public'::name) AND (t.spcname IS NULL));


--
-- Name: images_image_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.images_image_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: media_posts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_posts (
    post_id bigint,
    media_id integer
);


--
-- Name: images_posts; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.images_posts AS
 SELECT media_posts.media_id AS image_id,
    media_posts.post_id
   FROM public.media_posts;


--
-- Name: images_posts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.images_posts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: index_use; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.index_use AS
 SELECT n.nspname AS schema,
    c.relname AS index_name,
    pg_size_pretty(pg_relation_size((c.oid)::regclass)) AS pretty_size,
    t.relname AS table_name,
    am.amname AS access_method,
    pg_tablespace.spcname AS tablespace,
    pg_roles.rolname AS owner,
    pg_relation_size((c.oid)::regclass) AS ugly_size,
    COALESCE(s.idx_scan, (0)::bigint) AS index_scans,
    COALESCE(s.idx_tup_read, (0)::bigint) AS index_reads,
    COALESCE(s.idx_tup_fetch, (0)::bigint) AS index_fetches
   FROM (((((((pg_class c
     JOIN pg_namespace n ON ((c.relnamespace = n.oid)))
     JOIN pg_index ON ((pg_index.indexrelid = c.oid)))
     JOIN pg_class t ON ((pg_index.indrelid = t.oid)))
     JOIN pg_am am ON ((c.relam = am.oid)))
     LEFT JOIN pg_tablespace ON ((pg_tablespace.oid = c.reltablespace)))
     LEFT JOIN pg_roles ON ((pg_roles.oid = c.relowner)))
     LEFT JOIN pg_stat_user_indexes s ON ((s.indexrelid = c.oid)))
  WHERE ((c.relkind = 'i'::"char") AND (n.nspname <> ALL (ARRAY['pg_catalog'::name, 'information_schema'::name, 'pg_toast'::name])))
  ORDER BY (pg_relation_size((c.oid)::regclass)) DESC;


--
-- Name: index_use_toast; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.index_use_toast AS
 SELECT n.nspname AS schema,
    c.relname AS index_name,
    pg_size_pretty(pg_relation_size((c.oid)::regclass)) AS pretty_size,
    t.relname AS table_name,
    am.amname AS access_method,
    pg_tablespace.spcname AS tablespace,
    pg_roles.rolname AS owner,
    pg_relation_size((c.oid)::regclass) AS ugly_size,
    COALESCE(s.idx_scan, (0)::bigint) AS index_scans,
    COALESCE(s.idx_tup_read, (0)::bigint) AS index_reads,
    COALESCE(s.idx_tup_fetch, (0)::bigint) AS index_fetches
   FROM (((((((pg_class c
     JOIN pg_namespace n ON ((c.relnamespace = n.oid)))
     JOIN pg_index ON ((pg_index.indexrelid = c.oid)))
     JOIN pg_class t ON ((pg_index.indrelid = t.oid)))
     JOIN pg_am am ON ((c.relam = am.oid)))
     LEFT JOIN pg_tablespace ON ((pg_tablespace.oid = c.reltablespace)))
     LEFT JOIN pg_roles ON ((pg_roles.oid = c.relowner)))
     LEFT JOIN pg_stat_user_indexes s ON ((s.indexrelid = c.oid)))
  WHERE ((c.relkind = 'i'::"char") AND (n.nspname <> ALL (ARRAY['pg_catalog'::name, 'information_schema'::name])))
  ORDER BY (pg_relation_size((c.oid)::regclass)) DESC;


--
-- Name: lexeme_blogstats_en_self; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lexeme_blogstats_en_self (
    ndoc integer,
    lexeme_id integer,
    unuuid integer,
    nentry integer DEFAULT 0
);


--
-- Name: lexeme_blogstats_en_trail; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lexeme_blogstats_en_trail (
    ndoc integer,
    lexeme_id integer,
    unuuid integer
);


--
-- Name: lexeme_blogstats_english; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lexeme_blogstats_english (
    blog_uuid character varying(64) NOT NULL,
    ndoc integer,
    nentry bigint,
    lexeme_id integer NOT NULL,
    blog_freq double precision,
    post_freq double precision
);


--
-- Name: COLUMN lexeme_blogstats_english.blog_freq; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.lexeme_blogstats_english.blog_freq IS 'number of times word has appeared in the blog / estimated total number of words in the blog';


--
-- Name: COLUMN lexeme_blogstats_english.post_freq; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.lexeme_blogstats_english.post_freq IS 'number of posts word has appeared in on this blog / total number of posts in the blog';



--
-- Name: lexemes; Type: TABLE; Schema: public; Owner: -; 
--

CREATE TABLE public.lexemes (
    id integer NOT NULL,
    global_ndocs integer,
    global_nentries bigint,
    lexeme character varying(128),
    std_dev_post_freq double precision DEFAULT 0,
    avg_post_freq double precision,
    avg_blog_freq double precision,
    std_dev_blog_freq double precision,
    alpha double precision,
    beta double precision,
    post_freq_total double precision,
    blog_freq_total double precision,
    blogs_considered double precision,
    post_variance_total double precision,
    blog_variance_total double precision
);


--
-- Name: COLUMN lexemes.std_dev_post_freq; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.lexemes.std_dev_post_freq IS 'std dev of post_freq across all users. number of posts word has appeared in on a given blog / total number of posts in that blog';


--
-- Name: COLUMN lexemes.avg_post_freq; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.lexemes.avg_post_freq IS 'std dev of post_freq across all users. number of posts word has appeared in on a given blog / total number of posts in that blog';


--
-- Name: COLUMN lexemes.avg_blog_freq; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.lexemes.avg_blog_freq IS 'avg across all users of number of times word has appeared in the blog / estimated total number of words in the blog';


--
-- Name: COLUMN lexemes.std_dev_blog_freq; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.lexemes.std_dev_blog_freq IS 'std_dev across all blogs of number of times word has appeared in the blog / estimated total number of words in the blog';


--
-- Name: lexemes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.lexemes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: lexemes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.lexemes_id_seq OWNED BY public.lexemes.id;


--
-- Name: media; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media (
    media_id integer DEFAULT nextval('public.images_image_id_seq'::regclass) NOT NULL,
    media_meta public.media_info,
    date_encountered timestamp without time zone DEFAULT now(),
    mtype "char"
);


--
-- Name: peek_bl_stats; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.peek_bl_stats AS
 SELECT blogstats.blog_uuid,
    blogstats.blog_name,
    blogstats.indexed_post_count AS indexed_posts,
    blogstats.serverside_posts_reported AS tumblr_posts_reported,
    blogstats.success,
    blogstats.is_indexing AS indexing,
    blogstats.index_request_count AS idx_req_ct,
    blogstats.time_last_indexed
   FROM public.blogstats;


--
-- Name: posts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.posts (
    post_id bigint NOT NULL,
    blog_uuid character varying(64),
    en_hun_simple tsvector,
    ts_meta tsvector,
    tag_text text,
    post_date timestamp without time zone,
    archived_date timestamp without time zone DEFAULT now(),
    reblog_key character varying(255),
    has_text public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_ask public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_link public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_images public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_video public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_audio public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_chat public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    blocksb jsonb,
    index_version "char" DEFAULT '1'::"char",
    stems_only tsvector,
    is_reblog boolean,
    hit_rate double precision DEFAULT 0,
    deleted boolean DEFAULT false
);


--
-- Name: COLUMN posts.en_hun_simple; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.posts.en_hun_simple IS 'contains "tags, self, trail, images" as A, B, C, D';


--
-- Name: COLUMN posts.ts_meta; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.posts.ts_meta IS 'contains "tags, self, trail, images" as A, B, C, D';


--
-- Name: post_column_sizes; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.post_column_sizes AS
 SELECT pg_size_pretty(sum((pg_column_size(posts.post_id) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS post_id_size,
    pg_size_pretty(sum((pg_column_size(posts.blog_uuid) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS blog_uuid_size,
    pg_size_pretty(sum((pg_column_size(posts.en_hun_simple) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS simple_ts_vector_size,
    pg_size_pretty(sum((pg_column_size(posts.blocksb) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS blocksb_size,
    pg_size_pretty(sum((pg_column_size(posts.ts_meta) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS ts_meta_size,
    pg_size_pretty(sum((pg_column_size(posts.tag_text) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS tag_text_size,
    pg_size_pretty(sum((pg_column_size(posts.archived_date) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS archived_date_size,
    pg_size_pretty(sum((pg_column_size(posts.post_date) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS post_date_size,
    pg_size_pretty(sum((pg_column_size(posts.has_ask) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS has_ask_size,
    pg_size_pretty(sum((pg_column_size(posts.stems_only) * (((100)::double precision / (current_setting('m.r'::text))::double precision))::bigint))) AS stems_only
   FROM public.posts TABLESAMPLE system ((current_setting('m.r'::text))::double precision);


--
-- Name: posts_tags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.posts_tags (
    post_id bigint NOT NULL,
    tag_id integer NOT NULL,
    blog_uuid character varying(64) NOT NULL
);


--
-- Name: selftext_blogstats_english; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.selftext_blogstats_english (
    blog_uuid character varying(64) NOT NULL,
    ndoc integer,
    nentry bigint,
    lexeme_id integer NOT NULL,
    blog_freq double precision,
    post_freq double precision
);


--
-- Name: COLUMN selftext_blogstats_english.blog_freq; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.selftext_blogstats_english.blog_freq IS 'number of times word has appeared in the blog / estimated total number of words in the blog';


--
-- Name: COLUMN selftext_blogstats_english.post_freq; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.selftext_blogstats_english.post_freq IS 'number of posts word has appeared in on this blog / total number of posts in the blog';


--
-- Name: siikr_nodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.siikr_nodes (
    node_id integer NOT NULL,
    node_url text NOT NULL,
    free_space_mb double precision,
    last_pinged timestamp without time zone,
    reliability double precision DEFAULT 10
);


--
-- Name: siikr_nodes_node_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.siikr_nodes_node_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: siikr_nodes_node_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.siikr_nodes_node_id_seq OWNED BY public.siikr_nodes.node_id;


--
-- Name: tags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tags (
    tag_id integer NOT NULL,
    tag_name text,
    tag_simple_ts_vector tsvector
);


--
-- Name: tags_tag_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tags_tag_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tags_tag_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tags_tag_id_seq OWNED BY public.tags.tag_id;


--
-- Name: ugly_diskuse; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.ugly_diskuse AS
 SELECT n.nspname AS schema_name,
    c.relname AS table_name,
    pg_table_size((c.oid)::regclass) AS table_plus_toast_size,
    pg_indexes_size((c.oid)::regclass) AS indexes_size,
    pg_total_relation_size((c.oid)::regclass) AS total_size_including_indexes
   FROM ((pg_class c
     LEFT JOIN pg_namespace n ON ((n.oid = c.relnamespace)))
     LEFT JOIN pg_tablespace t ON ((c.reltablespace = t.oid)))
  WHERE ((c.relkind = 'r'::"char") AND (n.nspname = 'public'::name) AND ((t.spcname IS NULL)));


--
-- Name: update_counter_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.update_counter_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_encountered; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users_encountered (
    blog_name text,
    blog_uuid text,
    resolution_failed boolean
);


--
-- Name: wordclouded_blogs; Type: TABLE; Schema: public; Owner: -; 
--

CREATE TABLE public.wordclouded_blogs (
    blog_uuid character varying(64) NOT NULL,
    last_stats_update timestamp without time zone,
    post_count_at_stat integer
);


--
-- Name: active_queries search_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_queries ALTER COLUMN search_id SET DEFAULT nextval('public.active_queries_search_id_seq'::regclass);


--
-- Name: analyzed_blogs unuuid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analyzed_blogs ALTER COLUMN unuuid SET DEFAULT nextval('public.analyzed_blogs_unuuid_seq'::regclass);


--
-- Name: lexemes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexemes ALTER COLUMN id SET DEFAULT nextval('public.lexemes_id_seq'::regclass);


--
-- Name: siikr_nodes node_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.siikr_nodes ALTER COLUMN node_id SET DEFAULT nextval('public.siikr_nodes_node_id_seq'::regclass);


--
-- Name: tags tag_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tags ALTER COLUMN tag_id SET DEFAULT nextval('public.tags_tag_id_seq'::regclass);

--
-- Name: active_queries active_queries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_queries
    ADD CONSTRAINT active_queries_pkey PRIMARY KEY (search_id);


--
-- Name: analyzed_blogs analyzed_blogs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analyzed_blogs
    ADD CONSTRAINT analyzed_blogs_pkey PRIMARY KEY (unuuid);


--
-- Name: archiver_leases archiver_leases_leader_uuid_blog_uuid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.archiver_leases
    ADD CONSTRAINT archiver_leases_leader_uuid_blog_uuid_key UNIQUE (leader_uuid, blog_uuid);


--
-- Name: archiver_leases archiver_leases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.archiver_leases
    ADD CONSTRAINT archiver_leases_pkey PRIMARY KEY (leader_uuid);


--
-- Name: archiver_leases blog_uuid_cnstrt; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.archiver_leases
    ADD CONSTRAINT blog_uuid_cnstrt UNIQUE (blog_uuid);


--
-- Name: blogstats blogstats_blog_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blogstats
    ADD CONSTRAINT blogstats_blog_name_key UNIQUE (blog_name);


--
-- Name: blogstats blogstats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blogstats
    ADD CONSTRAINT blogstats_pkey PRIMARY KEY (blog_uuid);


--
-- Name: active_queries constraint_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_queries
    ADD CONSTRAINT constraint_name UNIQUE (query_text, query_params, blog_uuid);

--
-- Name: delete_me_after delete_me_after_pkey; Type: CONSTRAINT; Schema: public; Owner: -; 
--

ALTER TABLE ONLY public.delete_me_after
    ADD CONSTRAINT delete_me_after_pkey PRIMARY KEY (blog_uuid, lexeme_id);


--
-- Name: lexeme_blogstats_english lexeme_blogstats_english_pkey; Type: CONSTRAINT; Schema: public; Owner: -;

ALTER TABLE ONLY public.lexeme_blogstats_english
    ADD CONSTRAINT lexeme_blogstats_english_pkey PRIMARY KEY (blog_uuid, lexeme_id);


--
-- Name: lexemes lexeme_unq; Type: CONSTRAINT; Schema: public; Owner: -;
--

ALTER TABLE ONLY public.lexemes
    ADD CONSTRAINT lexeme_unq UNIQUE (lexeme);


--
-- Name: lexemes lexemes_pkey; Type: CONSTRAINT; Schema: public; Owner: -; 
--

ALTER TABLE ONLY public.lexemes
    ADD CONSTRAINT lexemes_pkey PRIMARY KEY (id);


--
-- Name: media_posts media_posts_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_posts
    ADD CONSTRAINT media_posts_unique UNIQUE (post_id, media_id);


--
-- Name: media media_temp_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media
    ADD CONSTRAINT media_temp_pkey PRIMARY KEY (media_id);


--
-- Name: siikr_nodes nodeurl; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.siikr_nodes
    ADD CONSTRAINT nodeurl UNIQUE (node_url);


--
-- Name: posts posts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts
    ADD CONSTRAINT posts_pkey PRIMARY KEY (post_id);


--
-- Name: posts_tags posts_tags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts_tags
    ADD CONSTRAINT posts_tags_pkey PRIMARY KEY (post_id, tag_id, blog_uuid);


--
-- Name: selftext_blogstats_english selftext_blogstats_english_pkey; Type: CONSTRAINT; Schema: public; Owner: -; 
--

ALTER TABLE ONLY public.selftext_blogstats_english
    ADD CONSTRAINT selftext_blogstats_english_pkey PRIMARY KEY (blog_uuid, lexeme_id);


--
-- Name: siikr_nodes siikr_nodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.siikr_nodes
    ADD CONSTRAINT siikr_nodes_pkey PRIMARY KEY (node_id);


--
-- Name: tags tags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tags
    ADD CONSTRAINT tags_pkey PRIMARY KEY (tag_id);


--
-- Name: tags tags_tag_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tags
    ADD CONSTRAINT tags_tag_name_key UNIQUE (tag_name);


--
-- Name: blog_node_map unq_blog_node; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blog_node_map
    ADD CONSTRAINT unq_blog_node UNIQUE (node_id, blog_uuid);


--
-- Name: cached_blog_node_map unq_uuid; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cached_blog_node_map
    ADD CONSTRAINT unq_uuid UNIQUE (blog_uuid);


--
-- Name: lexeme_blogstats_en_self unuui_lexemeid; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexeme_blogstats_en_self
    ADD CONSTRAINT unuui_lexemeid UNIQUE (unuuid, lexeme_id);


--
-- Name: lexeme_blogstats_en_trail unuuid_lexemeid; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexeme_blogstats_en_trail
    ADD CONSTRAINT unuuid_lexemeid UNIQUE (unuuid, lexeme_id);


--
-- Name: wordclouded_blogs wordclouded_blogs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wordclouded_blogs
    ADD CONSTRAINT wordclouded_blogs_pkey PRIMARY KEY (blog_uuid);


--
-- Name: blog_uuid_1691467458827_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blog_uuid_1691467458827_index ON public.posts USING btree (blog_uuid);


--
-- Name: blog_uuid_hash_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blog_uuid_hash_idx ON public.wordclouded_blogs USING hash (blog_uuid);


--
-- Name: blog_uuid_nodecache_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blog_uuid_nodecache_idx ON public.cached_blog_node_map USING hash (blog_uuid);


--
-- Name: cached_blog_node_map_blog_uuid_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cached_blog_node_map_blog_uuid_idx ON public.cached_blog_node_map USING hash (blog_uuid);


--
-- Name: idx_blog_uuid; Type: INDEX; Schema: public; Owner: -;
--

CREATE INDEX idx_blog_uuid ON public.lexeme_blogstats_english USING hash (blog_uuid);


--
-- Name: idx_blog_uuid_post_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_blog_uuid_post_date ON public.posts USING btree (blog_uuid, post_date);


--
-- Name: idx_e_vec; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_e_vec ON public.posts USING gin (ts_meta);


--
-- Name: idx_hash_media_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_hash_media_id ON public.media_posts USING hash (media_id);


--
-- Name: idx_hash_post_id; Type: INDEX; Schema: public; Owner: -;
--

CREATE INDEX idx_hash_post_id ON public.media_posts USING hash (post_id);

--
-- Name: idx_lexeme_id; Type: INDEX; Schema: public; Owner: -; 

CREATE INDEX idx_lexeme_id ON public.lexeme_blogstats_english USING hash (lexeme_id);


--
-- Name: idx_ndentries; Type: INDEX; Schema: public; Owner: -; 
--

CREATE INDEX idx_ndentries ON public.lexeme_blogstats_english USING btree (nentry);


--
-- Name: idx_ndocs; Type: INDEX; Schema: public; Owner: -; 
--

CREATE INDEX idx_ndocs ON public.lexeme_blogstats_english USING btree (ndoc);

--
-- Name: idx_posts_tags_blog_uuid; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_posts_tags_blog_uuid ON public.posts_tags USING btree (blog_uuid);


--
-- Name: idxsh_vec; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idxsh_vec ON public.posts USING gin (en_hun_simple);


--
-- Name: last_interact_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX last_interact_idx ON public.cached_blog_node_map USING btree (last_interaction);


--
-- Name: lbe_en_full_lexeme_id_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lbe_en_full_lexeme_id_hash ON public.lexeme_blogstats_en_trail USING hash (lexeme_id);


--
-- Name: lbe_en_full_unuuid_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lbe_en_full_unuuid_hash ON public.lexeme_blogstats_en_trail USING hash (unuuid);


--
-- Name: lbe_en_self_lexeme_id_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lbe_en_self_lexeme_id_hash ON public.lexeme_blogstats_en_self USING hash (lexeme_id);


--
-- Name: lbe_en_self_unuuid_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lbe_en_self_unuuid_hash ON public.lexeme_blogstats_en_self USING hash (unuuid);

--
-- Name: lexeme_unq_idx; Type: INDEX; Schema: public; Owner: -; 
--

CREATE INDEX lexeme_unq_idx ON public.lexemes USING hash (lexeme);


--
-- Name: media_temp_media_meta_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_temp_media_meta_idx ON public.media USING hash (media_meta);


--
-- Name: post_id_1691300947621_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX post_id_1691300947621_index ON public.posts_tags USING btree (post_id);


--
-- Name: post_id_blog_uuid_1691301036952_index; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX post_id_blog_uuid_1691301036952_index ON public.posts USING btree (post_id, blog_uuid);


--
-- Name: selftext_blogstats_english_blog_uuid_idx; Type: INDEX; Schema: public; Owner: -;
--

CREATE INDEX selftext_blogstats_english_blog_uuid_idx ON public.selftext_blogstats_english USING hash (blog_uuid);


--
-- Name: selftext_blogstats_english_lexeme_id_idx; Type: INDEX; Schema: public; Owner: -;
--

CREATE INDEX selftext_blogstats_english_lexeme_id_idx ON public.selftext_blogstats_english USING hash (lexeme_id);


--
-- Name: selftext_blogstats_english_ndoc_idx; Type: INDEX; Schema: public; Owner: -;
--

CREATE INDEX selftext_blogstats_english_ndoc_idx ON public.selftext_blogstats_english USING btree (ndoc);


--
-- Name: selftext_blogstats_english_nentry_idx; Type: INDEX; Schema: public; Owner: -;
CREATE INDEX selftext_blogstats_english_nentry_idx ON public.selftext_blogstats_english USING btree (nentry);


--
-- Name: tag_id_1691505411923_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tag_id_1691505411923_index ON public.posts_tags USING btree (tag_id);


--
-- Name: tag_id_post_id_1691492482825_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tag_id_post_id_1691492482825_index ON public.posts_tags USING btree (tag_id, post_id);


--
-- Name: lexeme_blogstats_english increment_update_counter_trigger; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER increment_update_counter_trigger AFTER UPDATE ON public.lexeme_blogstats_english FOR EACH ROW EXECUTE FUNCTION public.increment_update_counter();


--
-- Name: active_queries active_queries_blog_uuid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_queries
    ADD CONSTRAINT active_queries_blog_uuid_fkey FOREIGN KEY (blog_uuid) REFERENCES public.blogstats(blog_uuid);


--
-- Name: archiver_leases archiver_leases_blog_uuid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.archiver_leases
    ADD CONSTRAINT archiver_leases_blog_uuid_fkey FOREIGN KEY (blog_uuid) REFERENCES public.blogstats(blog_uuid);


--
-- Name: blog_node_map blog_node_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blog_node_map
    ADD CONSTRAINT blog_node_fk FOREIGN KEY (node_id) REFERENCES public.siikr_nodes(node_id);


--
-- Name: cached_blog_node_map blok_node_cache_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cached_blog_node_map
    ADD CONSTRAINT blok_node_cache_fk FOREIGN KEY (node_id) REFERENCES public.siikr_nodes(node_id);


--
-- Name: lexeme_blogstats_english fk_lexeme; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexeme_blogstats_english
    ADD CONSTRAINT fk_lexeme FOREIGN KEY (lexeme_id) REFERENCES public.lexemes(id) ON DELETE CASCADE;


--
-- Name: lexeme_blogstats_en_trail fk_lexeme_en_full; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexeme_blogstats_en_trail
    ADD CONSTRAINT fk_lexeme_en_full FOREIGN KEY (lexeme_id) REFERENCES public.lexemes(id) ON DELETE CASCADE;


--
-- Name: lexeme_blogstats_en_self fk_lexeme_en_self; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexeme_blogstats_en_self
    ADD CONSTRAINT fk_lexeme_en_self FOREIGN KEY (lexeme_id) REFERENCES public.lexemes(id) ON DELETE CASCADE;


--
-- Name: media_posts fk_media_posts_media; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_posts
    ADD CONSTRAINT fk_media_posts_media FOREIGN KEY (media_id) REFERENCES public.media(media_id);


--
-- Name: media_posts fk_media_posts_post; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_posts
    ADD CONSTRAINT fk_media_posts_post FOREIGN KEY (post_id) REFERENCES public.posts(post_id);


--
-- Name: lexeme_blogstats_english lexeme_blogstats_english_blog_uuid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexeme_blogstats_english
    ADD CONSTRAINT lexeme_blogstats_english_blog_uuid_fkey FOREIGN KEY (blog_uuid) REFERENCES public.wordclouded_blogs(blog_uuid);


--
-- Name: blog_node_map node_blog_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blog_node_map
    ADD CONSTRAINT node_blog_fk FOREIGN KEY (blog_uuid) REFERENCES public.blogstats(blog_uuid);


--
-- Name: posts posts_blog_uuid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts
    ADD CONSTRAINT posts_blog_uuid_fkey FOREIGN KEY (blog_uuid) REFERENCES public.blogstats(blog_uuid);


--
-- Name: posts_tags posts_tags_post_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts_tags
    ADD CONSTRAINT posts_tags_post_id_fkey FOREIGN KEY (post_id) REFERENCES public.posts(post_id);


--
-- Name: posts_tags posts_tags_tag_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts_tags
    ADD CONSTRAINT posts_tags_tag_id_fkey FOREIGN KEY (tag_id) REFERENCES public.tags(tag_id);


--
-- Name: lexeme_blogstats_en_trail unuuid_en_full; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexeme_blogstats_en_trail
    ADD CONSTRAINT unuuid_en_full FOREIGN KEY (unuuid) REFERENCES public.analyzed_blogs(unuuid);


--
-- Name: lexeme_blogstats_en_self unuuid_en_self; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lexeme_blogstats_en_self
    ADD CONSTRAINT unuuid_en_self FOREIGN KEY (unuuid) REFERENCES public.analyzed_blogs(unuuid);


--
-- PostgreSQL database dump complete
--

