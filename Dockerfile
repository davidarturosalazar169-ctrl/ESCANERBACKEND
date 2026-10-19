
# syntax=docker/dockerfile:1.4

FROM php:8.4-fpm-bookworm

# Instalar dependencias necesarias para SQLite
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    curl \
    ca-certificates \
    gnupg \
    libpng-dev \
    libxml2-dev \
    libsqlite3-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_sqlite bcmath gd zip \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && node --version \
    && npm --version \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer globalmente
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar el directorio de trabajo
WORKDIR /var/www/html

# Copiar los archivos del proyecto
COPY . .

# Instalar las dependencias de producción de Laravel
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Instalar Playwright y Chromium para el escáner dinámico
RUN npm ci --no-audit --no-fund \
    && npx playwright install --with-deps chromium

# Limpiar las cachés de configuración, rutas, vistas y eventos de Laravel
RUN php artisan config:clear \
    && php artisan route:clear \
    && php artisan view:clear \
    && php artisan event:clear

# Asignar permisos sin modificar el contenido de SQLite
RUN chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    /var/www/html/database

# Configuración de Nginx
COPY <<-"EOF" /etc/nginx/nginx.conf
user www-data;
worker_processes auto;
pid /run/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    sendfile on;
    keepalive_timeout 65;

    # Solo permitir los orígenes definidos
    map $http_origin $cors_origin {
        default "";
        "http://localhost:5173" $http_origin;
        "http://localhost:5174" $http_origin;
        "http://localhost:5178" $http_origin;
        "http://localhost:5179" $http_origin;
        "https://localhost" $http_origin;
        "http://localhost" $http_origin;
        "capacitor://localhost" $http_origin;
        "https://escanerbackend.onrender.com" $http_origin;
    }

    server {
        listen 80;
        server_name localhost;
        root /var/www/html/public;

        index index.php index.html;
        charset utf-8;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location = /favicon.ico {
            access_log off;
            log_not_found off;
        }

        location = /robots.txt {
            access_log off;
            log_not_found off;
        }

        error_page 404 /index.php;

        location ~ \.php$ {
            # Cabeceras CORS para respuestas normales
            add_header Access-Control-Allow-Origin $cors_origin always;
            add_header Access-Control-Allow-Credentials "true" always;
            add_header Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS" always;
            add_header Access-Control-Allow-Headers "Origin, Content-Type, Accept, Authorization, X-Requested-With" always;

            # Responder directamente a las solicitudes preflight
            if ($request_method = OPTIONS) {
                return 204;
            }

            fastcgi_pass 127.0.0.1:9000;
            fastcgi_pass_header Set-Cookie;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            include fastcgi_params;
        }

        location ~ /\.ht {
            deny all;
        }
    }
}
EOF

# Configuración de Supervisor
COPY <<-"EOF" /etc/supervisor/conf.d/supervisord.conf
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/run/supervisord.pid

[program:php-fpm]
command=php-fpm
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g "daemon off;"
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
EOF

EXPOSE 80

COPY docker/start.sh /usr/local/bin/start-escaner
RUN chmod +x /usr/local/bin/start-escaner

CMD ["/usr/local/bin/start-escaner"]
