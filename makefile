# Standard Version Section
STANDARD_VERSION_ARGS := --commitUrlFormat "{{host}}/{{owner}}/{{repository}}/commits/{{hash}}" \
                        --compareUrlFormat "{{host}}/{{owner}}/{{repository}}/branches/compare/{{previousTag}}%0D{{currentTag}}"

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