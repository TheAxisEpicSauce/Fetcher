# Standard Version Section
STANDARD_VERSION_ARGS := --commitUrlFormat "{{host}}/{{owner}}/{{repository}}/commits/{{hash}}" \
                        --compareUrlFormat "{{host}}/{{owner}}/{{repository}}/branches/compare/{{previousTag}}%0D{{currentTag}}"

test-setup:
	docker compose up -d mysql mongodb
	@echo "Waiting for MySQL to be ready..."
	@until docker exec fetcher-mysql mysqladmin ping -u root -pp0epsteen --silent 2>/dev/null; do sleep 1; done
	docker exec -i fetcher-mysql mysql -u root -pp0epsteen -e "DROP DATABASE IF EXISTS db_app; CREATE DATABASE db_app;"
	docker exec -i fetcher-mysql mysql -u root -pp0epsteen db_app < tests/data/mysql.sql

test: test-setup
	docker compose run --rm phpunit tests

bench:
	docker compose run --rm --entrypoint php php tests/benchmark.php $(n)

release:
	standard-version $(STANDARD_VERSION_ARGS)

release-major:
	standard-version $(STANDARD_VERSION_ARGS) --release-as major

release-minor:
	standard-version $(STANDARD_VERSION_ARGS) --release-as minor

release-patch:
	standard-version $(STANDARD_VERSION_ARGS) --release-as patch

release-major-dry:
	standard-version $(STANDARD_VERSION_ARGS) --release-as major --dry-run

release-minor-dry:
	standard-version $(STANDARD_VERSION_ARGS) --release-as minor --dry-run

release-patch-dry:
	standard-version $(STANDARD_VERSION_ARGS) --release-as patch --dry-run