FROM nginx:1.28-alpine3.23

# Créer les répertoires nécessaires
RUN mkdir -p /var/cache/nginx /var/log/nginx

CMD ["nginx", "-g", "daemon off;"]
