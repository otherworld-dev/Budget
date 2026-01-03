# Makefile for Nextcloud Budget App

app_name=budget
project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates

# Development
.PHONY: dev
dev:
	npm install
	npm run dev

.PHONY: watch
watch:
	npm run watch

.PHONY: build-js
build-js:
	npm install
	npm run build

# PHP Dependencies
.PHONY: composer
composer:
	composer install --no-dev

.PHONY: composer-dev
composer-dev:
	composer install

# Linting
.PHONY: lint
lint: lint-php lint-js

.PHONY: lint-php
lint-php:
	composer run lint

.PHONY: lint-js
lint-js:
	npm run lint

.PHONY: lint-fix
lint-fix:
	composer run cs:fix
	npm run lint:fix

# Testing
.PHONY: test
test: test-php

.PHONY: test-php
test-php:
	composer run test:unit

# Static Analysis
.PHONY: psalm
psalm:
	composer run psalm

# Clean
.PHONY: clean
clean:
	rm -rf $(build_dir)
	rm -rf node_modules
	rm -rf vendor
	rm -rf js/*
	rm -f composer.lock
	rm -f package-lock.json

# Build for distribution
.PHONY: build
build: clean composer build-js
	mkdir -p $(build_dir)
	rsync -a \
	--exclude=".git" \
	--exclude="build" \
	--exclude="tests" \
	--exclude="node_modules" \
	--exclude="src" \
	--exclude="webpack.config.js" \
	--exclude="package.json" \
	--exclude="package-lock.json" \
	--exclude="composer.json" \
	--exclude="composer.lock" \
	--exclude="Makefile" \
	--exclude=".gitignore" \
	--exclude=".eslintrc.js" \
	--exclude="psalm.xml" \
	--exclude="*.log" \
	./ $(build_dir)/$(app_name)

# Create tarball for app store
.PHONY: appstore
appstore: build
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing app files..."; \
		php ../../occ integrity:sign-app \
			--privateKey=$(cert_dir)/$(app_name).key \
			--certificate=$(cert_dir)/$(app_name).crt \
			--path=$(build_dir)/$(app_name); \
	fi
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(build_dir) $(app_name)
	@echo "App store package created: $(build_dir)/$(app_name).tar.gz"

# Install app to Nextcloud instance (for development)
.PHONY: install
install: build
	rm -rf $(project_dir)
	cp -r $(build_dir)/$(app_name) $(project_dir)

# Enable app in Nextcloud
.PHONY: enable
enable:
	php ../../occ app:enable $(app_name)

# Disable app in Nextcloud
.PHONY: disable
disable:
	php ../../occ app:disable $(app_name)

# Run database migrations
.PHONY: migrate
migrate:
	php ../../occ migrations:execute $(app_name)

# Help
.PHONY: help
help:
	@echo "Nextcloud Budget App - Makefile"
	@echo ""
	@echo "Development:"
	@echo "  make dev          - Build for development"
	@echo "  make watch        - Watch and rebuild on changes"
	@echo "  make composer-dev - Install all composer dependencies"
	@echo ""
	@echo "Testing & Quality:"
	@echo "  make lint         - Run all linters"
	@echo "  make lint-fix     - Fix linting issues"
	@echo "  make test         - Run tests"
	@echo "  make psalm        - Run static analysis"
	@echo ""
	@echo "Building:"
	@echo "  make build        - Build the app"
	@echo "  make appstore     - Create app store package"
	@echo "  make clean        - Clean build artifacts"
	@echo ""
	@echo "Installation:"
	@echo "  make install      - Install to Nextcloud"
	@echo "  make enable       - Enable the app"
	@echo "  make disable      - Disable the app"
	@echo "  make migrate      - Run database migrations"