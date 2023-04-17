FROM php:8.1.7-apache

# Enable mod_rewrite
RUN a2enmod rewrite