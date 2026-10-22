FROM alpine:3.21

# Upgrade all base system packages and install Apache, PHP 8.3+ with security patches
# Patched against critical RCE vulnerabilities (PHP 8.3.36, 8.2.35, 8.4.27)
RUN apk update && apk upgrade --no-cache \
    && apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    apache2 \
    apache2-utils \
    php83 \
    php83-apache2 \
    php83-pdo \
    php83-pdo_mysql \
    php83-mysqli \
    php83-session \
    php83-json \
    php83-mbstring \
    php83-openssl \
    php83-zip \
    php83-curl \
    php83-fileinfo \
    php83-ctype \
    php83-tokenizer \
    php83-xml \
    php83-dom \
    php83-simplexml \
    php83-iconv \
    php83-phar \
    php83-gd \
    python3 \
    gcc \
    g++ \
    musl-dev \
    openjdk17-jdk \
    && ln -sf /usr/bin/php83 /usr/bin/php

# Install official Composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure Apache (enable mod_rewrite, mod_headers, set DocumentRoot & Alias)
RUN sed -i 's/#LoadModule rewrite_module modules\/mod_rewrite.so/LoadModule rewrite_module modules\/mod_rewrite.so/' /etc/apache2/httpd.conf \
    && sed -i 's/#LoadModule headers_module modules\/mod_headers.so/LoadModule headers_module modules\/mod_headers.so/' /etc/apache2/httpd.conf \
    && sed -i 's#^DocumentRoot ".*"#DocumentRoot "/var/www/html"#' /etc/apache2/httpd.conf \
    && sed -i 's#<Directory "/var/www/localhost/htdocs">#<Directory "/var/www/html">#' /etc/apache2/httpd.conf \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/httpd.conf \
    && sed -i 's#^ErrorLog .*#ErrorLog /dev/stderr#' /etc/apache2/httpd.conf \
    && sed -i 's#^CustomLog .*#CustomLog /dev/stdout combined#' /etc/apache2/httpd.conf \
    && echo "ServerName localhost" >> /etc/apache2/httpd.conf \
    && echo "Alias /shreyansir /var/www/html" >> /etc/apache2/httpd.conf

# Configure custom PHP runtime settings
RUN echo "upload_max_filesize = 50M" >> /etc/php83/conf.d/custom.ini \
    && echo "post_max_size = 50M" >> /etc/php83/conf.d/custom.ini \
    && echo "memory_limit = 256M" >> /etc/php83/conf.d/custom.ini \
    && echo "max_execution_time = 300" >> /etc/php83/conf.d/custom.ini \
    && echo "date.timezone = Asia/Kolkata" >> /etc/php83/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Install composer dependencies
RUN if [ -f composer.json ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction; \
    fi

# Ensure uploads directory exists with correct write permissions for Apache
RUN mkdir -p /var/www/html/uploads/csv /var/www/html/uploads/questions \
    && chown -R apache:apache /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

EXPOSE 80

CMD ["httpd", "-D", "FOREGROUND"]
