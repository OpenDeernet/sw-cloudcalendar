.DEFAULT_GOAL := build

NPM ?= npm
ADMIN_DIR := apps/admin
DEPS_STAMP := $(ADMIN_DIR)/node_modules/.make-install

# Usage: make release VERSION=1.2.3 (publishes the current branch and release tag to origin).
.PHONY: release
release: export SWC_RELEASE_VERSION = $(VERSION)
release:
	bash bin/release.sh

.PHONY: build
build: $(DEPS_STAMP)
	cd $(ADMIN_DIR) && $(NPM) run build

$(DEPS_STAMP): $(ADMIN_DIR)/package.json $(ADMIN_DIR)/package-lock.json
	cd $(ADMIN_DIR) && $(NPM) ci --include=dev
	touch "$@"
