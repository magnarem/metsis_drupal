#!/usr/bin/env sh
# Borrowed from https://github.com/drush-ops/drush/
# Enable drush bash completion inside the ddev web container
# Suppress error output because this command is not present on Symfony 4.
export PATH=/var/www/html:$PATH
eval "$(drush completion bash 2>/dev/null)"

# enable composer completion inside the ddev web container
eval "$(composer completion bash 2>/dev/null)"
