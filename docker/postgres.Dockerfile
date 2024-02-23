FROM postgres:16-bookworm
RUN apt-get update && \
    apt-get -y install hunspell hunspell-en-us postgresql-16-pgvector

CMD ["postgres"]

