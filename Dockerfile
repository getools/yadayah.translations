FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/php.ini

# Download TinyMCE 6 community edition to a staging location
RUN apt-get update && apt-get install -y unzip wget \
    && mkdir -p /opt/tinymce \
    && wget -q https://download.tiny.cloud/tinymce/community/tinymce_6.8.2.zip -O /tmp/tinymce.zip \
    && unzip -q /tmp/tinymce.zip -d /tmp/tinymce \
    && cp -r /tmp/tinymce/tinymce/js/tinymce/* /opt/tinymce/ \
    && rm -rf /tmp/tinymce /tmp/tinymce.zip \
    && apt-get remove -y unzip wget && apt-get autoremove -y && rm -rf /var/lib/apt/lists/*

# Entrypoint script copies TinyMCE into the mounted volume
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# Set default.html as the directory index
RUN sed -i 's/DirectoryIndex .*/DirectoryIndex default.html index.html index.php/' /etc/apache2/mods-enabled/dir.conf

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
