FROM php:8.2-cli

# Install PostgreSQL PDO driver
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . .

EXPOSE 10000

# Serve from /app root — login.php, logout.php, and dashboard/ all resolve correctly
CMD ["php", "-S", "0.0.0.0:10000", "-t", "/app"]
