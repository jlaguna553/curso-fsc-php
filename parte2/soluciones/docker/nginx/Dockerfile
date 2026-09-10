FROM nginx:1.25-alpine

LABEL description="Nginx reverso proxy para PrestaFlow"

# Copiar configuración personalizada
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# El directorio de logs debe existir
RUN mkdir -p /var/log/nginx

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
