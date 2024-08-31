--
-- PostgreSQL database dump
--

-- Dumped from database version 14.10 (Ubuntu 14.10-0ubuntu0.22.04.1)
-- Dumped by pg_dump version 14.10 (Ubuntu 14.10-0ubuntu0.22.04.1)

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
    
    -- Finally, delete the blog entry in blogstats
    DELETE FROM blogstats WHERE blog_uuid = target_blog_uuid;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RAISE NOTICE 'Deleted % blogstats entry.', deleted_count;
END;
$$;


--
-- Name: get_deletability(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_deletability() RETURNS TABLE(blog_uuid character varying, blog_name character varying, indexed_post_count integer, time_last_indexed timestamp without time zone, post_ratio double precision, staleness double precision, score double precision)
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
        time_last_indexed,
        indexed_post_count::FLOAT / (SELECT total FROM total_posts) AS post_ratio,
        (((EXTRACT(EPOCH FROM time_last_indexed) - (SELECT min_date FROM date_range))) /
        NULLIF(((SELECT max_date FROM date_range) - (SELECT min_date FROM date_range)), 1.0)) AS recency
    FROM blogstats
)
SELECT
    blog_uuid,
    blog_name,
    indexed_post_count,
    time_last_indexed,
    post_ratio,
    1-recency as staleness,
    post_ratio * (POWER(1-recency, 2.71828182846)) AS score
FROM scored_blogstats ORDER BY score desc;
$$;


--
-- Name: english_hunspell; Type: TEXT SEARCH DICTIONARY; Schema: public; Owner: -
--

CREATE TEXT SEARCH DICTIONARY public.english_hunspell (
    TEMPLATE = pg_catalog.ispell,
    dictfile = 'en_us', afffile = 'en_us', stopwords = 'english' );


--
-- Name: en_us; Type: TEXT SEARCH CONFIGURATION; Schema: public; Owner: -
--

CREATE TEXT SEARCH CONFIGURATION public.en_us (
    PARSER = pg_catalog."default" );

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR asciiword WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR word WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR numword WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR email WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR url WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR host WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR sfloat WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR version WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR hword_numpart WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR hword_part WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR hword_asciipart WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR numhword WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR asciihword WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR hword WITH public.english_hunspell;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR url_path WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR file WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR "float" WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
    ADD MAPPING FOR "int" WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.en_us
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
-- Name: hunspell_english; Type: TEXT SEARCH CONFIGURATION; Schema: public; Owner: -
--

CREATE TEXT SEARCH CONFIGURATION public.hunspell_english (
    PARSER = pg_catalog."default" );

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR asciiword WITH english_stem;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR word WITH english_stem;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR numword WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR email WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR url WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR host WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR sfloat WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR version WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR hword_numpart WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR hword_part WITH english_stem;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR hword_asciipart WITH english_stem;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR numhword WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR asciihword WITH english_stem;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR hword WITH english_stem;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR url_path WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR file WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR "float" WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR "int" WITH simple;

ALTER TEXT SEARCH CONFIGURATION public.hunspell_english
    ADD MAPPING FOR uint WITH simple;


SET default_tablespace = '';

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
-- Name: archiver_leases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.archiver_leases (
    leader_uuid uuid NOT NULL,
    blog_uuid character varying(64),
    lease_expires_on timestamp without time zone DEFAULT (now() + '00:00:05'::interval)
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
    serverside_posts_reported integer DEFAULT 0
);


--
-- Name: clip_embeddings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clip_embeddings (
    clip_id integer NOT NULL,
    image_id integer NOT NULL,
    embedding public.vector(768),
    magnitude double precision
);


--
-- Name: COLUMN clip_embeddings.magnitude; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.clip_embeddings.magnitude IS 'for experimental distance metric calcs and  potentially more efficient cosine sim metrics';


--
-- Name: clip_embeddings_clip_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.clip_embeddings_clip_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: clip_embeddings_clip_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.clip_embeddings_clip_id_seq OWNED BY public.clip_embeddings.clip_id;


--
-- Name: images; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.images (
    image_id integer NOT NULL,
    img_url character varying(500),
    clip_processed boolean DEFAULT false,
    date_encountered timestamp without time zone DEFAULT now(),
    caption_vec tsvector,
    alt_text_vec tsvector,
    clip_attempted timestamp without time zone
);


--
-- Name: COLUMN images.clip_attempted; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.images.clip_attempted IS 'time we last tried to get clip embeddings';


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
-- Name: images_image_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.images_image_id_seq OWNED BY public.images.image_id;


--
-- Name: images_posts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.images_posts (
    post_id bigint NOT NULL,
    image_id integer NOT NULL,
    id integer NOT NULL
);


--
-- Name: images_posts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.images_posts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: images_posts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.images_posts_id_seq OWNED BY public.images_posts.id;


--
-- Name: posts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.posts (
    post_id bigint NOT NULL,
    blog_uuid character varying(64),
    simple_ts_vector tsvector,
    english_ts_vector tsvector,
    tag_text text,
    post_date timestamp without time zone,
    post_url text,
    archived_date timestamp without time zone DEFAULT now(),
    reblog_key character varying(255),
    slug character varying(512),
    has_text public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_ask public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_link public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_images public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_video public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_audio public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    has_chat public.has_content DEFAULT 'FALSE'::public.has_content NOT NULL,
    blocks json,
    html text DEFAULT '""'::text NOT NULL
);


--
-- Name: COLUMN posts.simple_ts_vector; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.posts.simple_ts_vector IS 'contains "tags, self, trail, images" as A, B, C, D';


--
-- Name: COLUMN posts.english_ts_vector; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.posts.english_ts_vector IS 'contains "tags, self, trail, images" as A, B, C, D';


--
-- Name: COLUMN posts.html; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.posts.html IS 'raw post html';


--
-- Name: posts_tags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.posts_tags (
    post_id bigint NOT NULL,
    tag_id integer NOT NULL,
    blog_uuid character varying(64) NOT NULL
);


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
-- Name: active_queries search_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_queries ALTER COLUMN search_id SET DEFAULT nextval('public.active_queries_search_id_seq'::regclass);


--
-- Name: clip_embeddings clip_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clip_embeddings ALTER COLUMN clip_id SET DEFAULT nextval('public.clip_embeddings_clip_id_seq'::regclass);


--
-- Name: images image_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.images ALTER COLUMN image_id SET DEFAULT nextval('public.images_image_id_seq'::regclass);


--
-- Name: images_posts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.images_posts ALTER COLUMN id SET DEFAULT nextval('public.images_posts_id_seq'::regclass);


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
-- Name: clip_embeddings clip_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clip_embeddings
    ADD CONSTRAINT clip_embeddings_pkey PRIMARY KEY (clip_id);


--
-- Name: active_queries constraint_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.active_queries
    ADD CONSTRAINT constraint_name UNIQUE (query_text, query_params, blog_uuid);


--
-- Name: images images_img_url_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.images
    ADD CONSTRAINT images_img_url_key UNIQUE (img_url);


--
-- Name: images images_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.images
    ADD CONSTRAINT images_pkey PRIMARY KEY (image_id);


--
-- Name: images_posts images_posts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.images_posts
    ADD CONSTRAINT images_posts_pkey PRIMARY KEY (id);


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
-- Name: blog_uuid_1691466808180_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blog_uuid_1691466808180_index ON public.posts USING hash (blog_uuid);


--
-- Name: blog_uuid_1691467458827_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blog_uuid_1691467458827_index ON public.posts USING btree (blog_uuid);


--
-- Name: blog_uuid_post_id_1691305120584_index; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX blog_uuid_post_id_1691305120584_index ON public.posts USING btree (blog_uuid, post_id);


--
-- Name: clip_embeddings_image_id_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX clip_embeddings_image_id_key ON public.clip_embeddings USING btree (image_id);


--
-- Name: idx_blog_uuid_post_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_blog_uuid_post_date ON public.posts USING btree (blog_uuid, post_date);


--
-- Name: idx_e_vec; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_e_vec ON public.posts USING gin (english_ts_vector);


--
-- Name: idx_posts_tags_blog_uuid; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_posts_tags_blog_uuid ON public.posts_tags USING btree (blog_uuid);


--
-- Name: idxsh_vec; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idxsh_vec ON public.posts USING gin (simple_ts_vector);


--
-- Name: image_id_1691302539918_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX image_id_1691302539918_index ON public.clip_embeddings USING hash (image_id);


--
-- Name: image_id_1691304841740_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX image_id_1691304841740_index ON public.images_posts USING btree (image_id);


--
-- Name: image_id_post_id_1691304160774_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX image_id_post_id_1691304160774_index ON public.images_posts USING btree (image_id, post_id);


--
-- Name: post_date_1691466215192_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX post_date_1691466215192_index ON public.posts USING btree (post_date);


--
-- Name: post_id_1691300947621_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX post_id_1691300947621_index ON public.posts_tags USING btree (post_id);


--
-- Name: post_id_1691304847089_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX post_id_1691304847089_index ON public.images_posts USING btree (post_id);


--
-- Name: post_id_blog_uuid_1691301036952_index; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX post_id_blog_uuid_1691301036952_index ON public.posts USING btree (post_id, blog_uuid);


--
-- Name: tag_id_1691505411923_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tag_id_1691505411923_index ON public.posts_tags USING btree (tag_id);


--
-- Name: tag_id_post_id_1691492482825_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tag_id_post_id_1691492482825_index ON public.posts_tags USING btree (tag_id, post_id);


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
-- Name: clip_embeddings clip_embeddings_image_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clip_embeddings
    ADD CONSTRAINT clip_embeddings_image_id_fkey FOREIGN KEY (image_id) REFERENCES public.images(image_id);


--
-- Name: images_posts images_posts_image_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.images_posts
    ADD CONSTRAINT images_posts_image_id_fkey FOREIGN KEY (image_id) REFERENCES public.images(image_id);


--
-- Name: images_posts images_posts_post_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.images_posts
    ADD CONSTRAINT images_posts_post_id_fkey FOREIGN KEY (post_id) REFERENCES public.posts(post_id);


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
-- Name: FUNCTION vector_in(cstring, oid, integer); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_in(cstring, oid, integer) TO siikrweb;


--
-- Name: FUNCTION vector_out(public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_out(public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_recv(internal, oid, integer); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_recv(internal, oid, integer) TO siikrweb;


--
-- Name: FUNCTION vector_send(public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_send(public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_typmod_in(cstring[]); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_typmod_in(cstring[]) TO siikrweb;


--
-- Name: FUNCTION array_to_vector(real[], integer, boolean); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.array_to_vector(real[], integer, boolean) TO siikrweb;


--
-- Name: FUNCTION array_to_vector(double precision[], integer, boolean); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.array_to_vector(double precision[], integer, boolean) TO siikrweb;


--
-- Name: FUNCTION array_to_vector(integer[], integer, boolean); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.array_to_vector(integer[], integer, boolean) TO siikrweb;


--
-- Name: FUNCTION array_to_vector(numeric[], integer, boolean); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.array_to_vector(numeric[], integer, boolean) TO siikrweb;


--
-- Name: FUNCTION vector_to_float4(public.vector, integer, boolean); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_to_float4(public.vector, integer, boolean) TO siikrweb;


--
-- Name: FUNCTION vector(public.vector, integer, boolean); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector(public.vector, integer, boolean) TO siikrweb;


--
-- Name: FUNCTION cosine_distance(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.cosine_distance(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION inner_product(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.inner_product(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION ivfflathandler(internal); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.ivfflathandler(internal) TO siikrweb;


--
-- Name: FUNCTION l2_distance(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.l2_distance(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_accum(double precision[], public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_accum(double precision[], public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_add(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_add(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_avg(double precision[]); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_avg(double precision[]) TO siikrweb;


--
-- Name: FUNCTION vector_cmp(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_cmp(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_combine(double precision[], double precision[]); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_combine(double precision[], double precision[]) TO siikrweb;


--
-- Name: FUNCTION vector_dims(public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_dims(public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_eq(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_eq(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_ge(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_ge(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_gt(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_gt(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_l2_squared_distance(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_l2_squared_distance(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_le(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_le(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_lt(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_lt(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_ne(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_ne(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_negative_inner_product(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_negative_inner_product(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_norm(public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_norm(public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_spherical_distance(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_spherical_distance(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION vector_sub(public.vector, public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.vector_sub(public.vector, public.vector) TO siikrweb;


--
-- Name: FUNCTION avg(public.vector); Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON FUNCTION public.avg(public.vector) TO siikrweb;


--
-- Name: TABLE active_queries; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.active_queries TO siikrweb;


--
-- Name: SEQUENCE active_queries_search_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.active_queries_search_id_seq TO siikrweb;


--
-- Name: TABLE archiver_leases; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.archiver_leases TO siikrweb;


--
-- Name: TABLE blogstats; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.blogstats TO siikrweb;


--
-- Name: TABLE clip_embeddings; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.clip_embeddings TO siikrweb;


--
-- Name: SEQUENCE clip_embeddings_clip_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.clip_embeddings_clip_id_seq TO siikrweb;


--
-- Name: TABLE images; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.images TO siikrweb;


--
-- Name: SEQUENCE images_image_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.images_image_id_seq TO siikrweb;


--
-- Name: TABLE images_posts; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.images_posts TO siikrweb;


--
-- Name: SEQUENCE images_posts_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.images_posts_id_seq TO siikrweb;


--
-- Name: TABLE posts; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.posts TO siikrweb;


--
-- Name: TABLE posts_tags; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.posts_tags TO siikrweb;


--
-- Name: TABLE tags; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON TABLE public.tags TO siikrweb;


--
-- Name: SEQUENCE tags_tag_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT ALL ON SEQUENCE public.tags_tag_id_seq TO siikrweb;

--
-- PostgreSQL database dump complete
--

