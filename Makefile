DOCKER_REPO?=meyerkev248/siikr

reset:
	$(MAKE) build
	$(MAKE) down
	$(MAKE) up

reset-clean:
	$(MAKE) build
	$(MAKE) clean
	$(MAKE) up

.PHONY: build
build:
	docker-compose build

.PHONY: up
up:
	
	docker-compose up -d

.PHONY: down
down:
	docker-compose down

.PHONY: clean
clean:
	docker-compose down -v

.PHONY: logs
logs:
	docker-compose logs -f

.PHONY: docker-push
docker-push:
	@IMAGES="my-postgres:latest my-php-fpm:latest"; \
	TAG=$$(git rev-parse --short HEAD); \
	if [ -n "$$(git status --porcelain)" ]; then \
		TAG="$$TAG-dirty"; \
	fi; \
	for IMAGE in $$IMAGES; do \
		docker tag $$IMAGE ${DOCKER_REPO}:$$TAG; \
		echo "Pushing $$IMAGE to ${DOCKER_REPO}:$$TAG"; \
		docker push ${DOCKER_REPO}:$$TAG; \
	done


