FROM nginx:alpine

# Copiar configuración predeterminada
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80 7000

CMD ["nginx", "-g", "daemon off;"]
