FROM $BASE_IMAGE
ENV PHP_VERSION=8.4
RUN sed -i '/^;log_limit/clog_limit = 8192' /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
RUN echo "security.limit_extensions = .php .html" >> /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
RUN echo "php_admin_value[date.timezone] = UTC" >> /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
RUN sed -i '/^;catch_workers_output/ccatch_workers_output = yes' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
RUN sed -i '/^;access.log/caccess.log = /proc/self/fd/2' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
RUN sed -i '/^;access.format/caccess.format =%{REMOTE_ADDR}e - [%t] %m %{REQUEST_SCHEME}e://%{HTTP_HOST}e%{REQUEST_URI}e %s pid:%p dur:%{milliseconds}d mem:%{bytes}M cpu_usr:%{user}C cpu_sys:%{system}C cpu_tot:%{total}C' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
RUN sed -i '/^;php_flag\\[display_errors\\]/cphp_flag[display_errors] = off' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
RUN sed -i '/^;php_admin_value\\[error_log\\]/cphp_admin_value[error_log] = /proc/self/fd/2' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
RUN sed -i '/^;php_admin_flag\\[log_errors\\]/cphp_admin_flag[log_errors] = on' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
