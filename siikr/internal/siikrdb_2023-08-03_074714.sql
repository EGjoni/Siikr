--
-- PostgreSQL database dump
--

-- Dumped from database version 14.8 (Ubuntu 14.8-0ubuntu0.22.04.1)
-- Dumped by pg_dump version 14.8 (Ubuntu 14.8-0ubuntu0.22.04.1)

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

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: blogstats; Type: TABLE; Schema: public; Owner: siikradmin
--

CREATE TABLE public.blogstats (
    blog_uuid character varying(16) NOT NULL,
    blog_name character varying(120),
    most_recent_post_id bigint,
    last_indexed timestamp without time zone,
    is_indexing timestamp without time zone,
    indexed_post_count integer
);


ALTER TABLE public.blogstats OWNER TO siikradmin;

--
-- Name: posts; Type: TABLE; Schema: public; Owner: siikradmin
--

CREATE TABLE public.posts (
    post_id bigint NOT NULL,
    blog_uuid character varying(255),
    self_text text,
    trail_text text,
    self_simple_ts_vector tsvector,
    self_english_ts_vector tsvector,
    trail_simple_ts_vector tsvector,
    trail_english_ts_vector tsvector
);


ALTER TABLE public.posts OWNER TO siikradmin;

--
-- Name: posts_tags; Type: TABLE; Schema: public; Owner: siikradmin
--

CREATE TABLE public.posts_tags (
    post_id bigint NOT NULL,
    tag_id integer NOT NULL
);


ALTER TABLE public.posts_tags OWNER TO siikradmin;

--
-- Name: tags; Type: TABLE; Schema: public; Owner: siikradmin
--

CREATE TABLE public.tags (
    tag_id integer NOT NULL,
    tag_name character varying(255),
    tag_simple_ts_vector tsvector
);


ALTER TABLE public.tags OWNER TO siikradmin;

--
-- Name: tags_tag_id_seq; Type: SEQUENCE; Schema: public; Owner: siikradmin
--

CREATE SEQUENCE public.tags_tag_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.tags_tag_id_seq OWNER TO siikradmin;

--
-- Name: tags_tag_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: siikradmin
--

ALTER SEQUENCE public.tags_tag_id_seq OWNED BY public.tags.tag_id;


--
-- Name: tags tag_id; Type: DEFAULT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.tags ALTER COLUMN tag_id SET DEFAULT nextval('public.tags_tag_id_seq'::regclass);


--
-- Name: blogstats blogstats_blog_name_key; Type: CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.blogstats
    ADD CONSTRAINT blogstats_blog_name_key UNIQUE (blog_name);


--
-- Name: blogstats blogstats_pkey; Type: CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.blogstats
    ADD CONSTRAINT blogstats_pkey PRIMARY KEY (blog_uuid);


--
-- Name: posts posts_pkey; Type: CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.posts
    ADD CONSTRAINT posts_pkey PRIMARY KEY (post_id);


--
-- Name: posts_tags posts_tags_pkey; Type: CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.posts_tags
    ADD CONSTRAINT posts_tags_pkey PRIMARY KEY (post_id, tag_id);


--
-- Name: tags tags_pkey; Type: CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.tags
    ADD CONSTRAINT tags_pkey PRIMARY KEY (tag_id);


--
-- Name: tags tags_tag_name_key; Type: CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.tags
    ADD CONSTRAINT tags_tag_name_key UNIQUE (tag_name);


--
-- Name: posts posts_blog_uuid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.posts
    ADD CONSTRAINT posts_blog_uuid_fkey FOREIGN KEY (blog_uuid) REFERENCES public.blogstats(blog_uuid);


--
-- Name: posts_tags posts_tags_post_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.posts_tags
    ADD CONSTRAINT posts_tags_post_id_fkey FOREIGN KEY (post_id) REFERENCES public.posts(post_id);


--
-- Name: posts_tags posts_tags_tag_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: siikradmin
--

ALTER TABLE ONLY public.posts_tags
    ADD CONSTRAINT posts_tags_tag_id_fkey FOREIGN KEY (tag_id) REFERENCES public.tags(tag_id);


--
-- PostgreSQL database dump complete
--

