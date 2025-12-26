.PHONY: help build create start stop restart recreate clean logs show-url

# Couleurs pour les messages
GREEN  := \033[0;32m
YELLOW := \033[0;33m
NC     := \033[0m # No Color

help: ## Affiche cette aide
	@echo "$(GREEN)Commandes disponibles:$(NC)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(YELLOW)%-15s$(NC) %s\n", $$1, $$2}'

build: ## Construit l'image Docker
	@echo "$(GREEN)Construction de l'image Docker...$(NC)"
	docker-compose build

create: build ## Crée et démarre les conteneurs (build + start)
	@echo "$(GREEN)Création des conteneurs...$(NC)"
	docker-compose up -d
	@$(MAKE) show-url

start: ## Démarre les conteneurs
	@echo "$(GREEN)Démarrage des conteneurs...$(NC)"
	docker-compose start
	@$(MAKE) show-url

stop: ## Arrête les conteneurs
	@echo "$(YELLOW)Arrêt des conteneurs...$(NC)"
	docker-compose stop
	@echo "$(GREEN)✓ Conteneurs arrêtés$(NC)"

restart: ## Redémarre les conteneurs
	@echo "$(YELLOW)Redémarrage des conteneurs...$(NC)"
	docker-compose restart
	@$(MAKE) show-url

recreate: ## Recrée les conteneurs (down + up)
	@echo "$(YELLOW)Recréation des conteneurs...$(NC)"
	docker-compose down
	docker-compose up -d
	@$(MAKE) show-url

clean: ## Arrête et supprime les conteneurs, réseaux et volumes
	@echo "$(YELLOW)Nettoyage des conteneurs et volumes...$(NC)"
	docker-compose down -v
	@echo "$(GREEN)✓ Nettoyage terminé$(NC)"

logs: ## Affiche les logs des conteneurs
	docker-compose logs -f

status: ## Affiche le statut des conteneurs
	docker-compose ps

shell: ## Accède au shell du conteneur
	docker-compose exec app sh

show-url: ## Affiche l'URL de l'application
	@echo "$(GREEN)✓ Application disponible sur http://localhost:$$(grep -E '^PORT=' .env 2>/dev/null | cut -d '=' -f2 || echo '8080')/smiirl-json-feed.php$(NC)"

.DEFAULT_GOAL := help
