FROM php:7.4-cli

# install git
RUN apt-get update
RUN apt-get install -y git


ARG file_name
ARG version
ARG sandbox
ARG release_mode

COPY deploy.php /deploy.php
COPY ${file_name} /${file_name}
RUN git clone -n https://github.com/Freemius/freemius-php-sdk.git /freemius-php-api && git checkout 9ca08b0f3166bac2bb4d640eaee89d63719626f2

EXPOSE 80/tcp
EXPOSE 80/udp

CMD php /deploy.php
