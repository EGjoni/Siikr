ARG POSTGRES_VERSION=14
FROM postgres:$(POSTGRES_VERSION)-bookworm
RUN apt-get update && \
    apt-get -y install hunspell hunspell-en-us postgresql-$(POSTGRES_VERSION)-pgvector
