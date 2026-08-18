#!/usr/bin/env sh
cd "$(dirname "$0")" || exit 1
php -r "if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,'Enable pdo_sqlite in php.ini\n');exit(1);}" || exit 1
php -r "echo function_exists('curl_init') ? 'Google Sheet connection: cURL OK\n' : 'Note: enable PHP cURL for Google Sheet sync.\n';"
echo "Open http://localhost:8000"
php -S localhost:8000 -t public
