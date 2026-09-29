.DEFAULT_GOAL := help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-10s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies
	composer install

cs: ## Fix coding style
	vendor/bin/php-cs-fixer fix

rector: ## Apply Rector refactorings
	vendor/bin/rector process

phpstan: ## Run static analysis
	vendor/bin/phpstan analyse --memory-limit=512M

test: ## Run the test suite
	vendor/bin/phpunit

qa: rector cs phpstan test ## Fix, analyse and test: run before every push

.PHONY: help install cs rector phpstan test qa
