# Use official PHP 8.2 image with Apache built-in
FROM php:8.2-apache

# Install required PHP extensions for MySQL (PDO & mysqli)
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache mod_rewrite for .htaccess redirects & routing
RUN a2enmod rewrite

# Copy project files into Apache web server root
COPY . /var/www/html/

# Adjust Apache port binding to Render's default port ($PORT or 80)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Expose port
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]