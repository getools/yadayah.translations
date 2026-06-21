FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev ffmpeg libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev postgresql-client curl \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo pdo_pgsql gd exif \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Node.js 20 + Claude Code CLI for auto-fix agent
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs unzip \
    && npm install -g @anthropic-ai/claude-code \
    && rm -rf /var/lib/apt/lists/*

# yt-dlp + Deno (the n-challenge JS solver invoked via
# `--remote-components ejs:github` in api/transcript-worker.php). Both must
# live in the image — installing them inside the running container would be
# wiped on `docker compose up -d` recreates.
#
# Two yt-dlp installs are needed:
#   1. the compiled /usr/local/bin/yt-dlp binary (ships its own interpreter), and
#   2. the pip `yt_dlp` Python module, which the api/yt-dlp-plugin wrapper runs
#      via `python3 -m yt_dlp` so external .py plugins (bgutil POT provider) load.
# The module MUST be baked here: if it is only installed at request time, a
# container restart wipes it and the first concurrent transcript jobs race to
# reinstall it, failing with "Could not install packages due to an OSError".
RUN curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux \
        -o /usr/local/bin/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp \
    && curl -fsSL https://bootstrap.pypa.io/get-pip.py -o /tmp/get-pip.py \
    && python3 /tmp/get-pip.py --break-system-packages -q --root-user-action=ignore \
    && python3 -m pip install --break-system-packages --no-cache-dir --root-user-action=ignore yt-dlp \
    && rm /tmp/get-pip.py \
    && curl -fsSL https://github.com/denoland/deno/releases/latest/download/deno-x86_64-unknown-linux-gnu.zip \
        -o /tmp/deno.zip \
    && unzip -q /tmp/deno.zip -d /usr/local/bin \
    && chmod +x /usr/local/bin/deno \
    && rm /tmp/deno.zip

# bgutil PO Token plugin for yt-dlp (defeats Botguard "you're not a bot"
# challenge). The HTTP server side runs as the pot-provider sidecar; this
# is the client-side plugin that knows to call it. Installed under www-data's
# config dir so the transcript-worker process auto-discovers it.
RUN mkdir -p /var/www/.config/yt-dlp/plugins/bgutil \
    && curl -fsSL https://github.com/Brainicism/bgutil-ytdlp-pot-provider/releases/latest/download/bgutil-ytdlp-pot-provider.zip \
        -o /tmp/pot.zip \
    && unzip -oq /tmp/pot.zip -d /var/www/.config/yt-dlp/plugins/bgutil \
    && rm /tmp/pot.zip \
    && chown -R www-data:www-data /var/www/.config

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
RUN sed -i 's/DirectoryIndex .*/DirectoryIndex index.html index.php/' /etc/apache2/mods-enabled/dir.conf

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
