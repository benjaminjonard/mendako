<p align="center">
<img src="https://img.shields.io/github/license/benjaminjonard/mendako" />    
    <img src="https://img.shields.io/github/v/release/benjaminjonard/mendako" />
    <img src="https://img.shields.io/badge/php->=8.5-blue" />
    <img src="https://img.shields.io/badge/postgresql->=15.0-blue" />            
</p> 

# Mendako

Quick project done over the course of a week.

Private, light booru-like image board, supports multiple boards.

Inspired by https://github.com/danbooru/danbooru

## Screenshots
<p align="center">
    <img width="400px" src="https://user-images.githubusercontent.com/20560781/196007085-5be47dac-809c-4cff-bedd-deb4757c168e.png">
    <img width="400px" src="https://user-images.githubusercontent.com/20560781/196007150-e3cd4665-e6d9-4afb-8d11-41c155493f0c.png">
    <img width="400px" src="https://user-images.githubusercontent.com/20560781/196007132-3df3fdde-1d28-4906-88aa-74326d9f369f.png">
</p>

## Installation
#### Step 1 -> Create a `docker-compose.yml` file
```
services:
    mendako:
        container_name: mendako
        image: benjaminjonard/mendako
        restart: unless-stopped
        ports:
            - 81:80
        env_file:
            - .env
        depends_on:
            - mendako_postgresql
        volumes:
            - "./volumes/mendako/public/uploads:/uploads"
            - "./volumes/mendako/public/thumbnails:/thumbnails"

    mendako_postgresql:
        container_name: mendako_postgresql
        image: pgvector/pgvector:pg18
        restart: unless-stopped
        env_file:
            - .env
        environment:
            - POSTGRES_DB=${DB_NAME}
            - POSTGRES_USER=${DB_USER}
            - POSTGRES_PASSWORD=${DB_PASSWORD}
        volumes:
            - "./volumes/postgresql:/var/lib/postgresql"

    # Only required for ML tasks, you can remove it if you don't want autotagging
    # mendako_ml:
    #    container_name: mendako_ml
    #    image: benjaminjonard/mendako-ml
    #    restart: unless-stopped       
```

####  Step 2 -> Create a `.env` file
```
########################################################################################################
#                                                WEB
#
# APP_DEBUG=1 displays detailed error message
#
# APP_SECRET is a random string used for security, you can use for example openssl rand -base64 21
# APP_SECRET is automatically generated when using Docker
#
# PHP_TZ, see possible values here https://www.w3schools.com/php/php_ref_timezones.asp
# APP_THUMBNAILS_FORMAT possible values : jpeg, png, webp, avif. Leave empty if you want to keep the original image format
# APP_POST_PER_PAGE and APP_INFINITE_SCROLL_POST_PER_PAGE are limited to 200
#
# WORKER_PHP_MEMORY_LIMIT -> max memory consumption for job workers, the memory will be freed once the worker is done
#
# FRANKENPHP_THREADS_NUMBER, must be strictly superior to FRANKENPHP_WORKERS_NUMBER
########################################################################################################

APP_DEBUG=0
APP_ENV=prod
#APP_SECRET=

HTTPS_ENABLED=1
UPLOAD_MAX_FILESIZE=20M
PHP_MEMORY_LIMIT=512M
WORKER_PHP_MEMORY_LIMIT=2G
PHP_TZ=Europe/Paris

APP_THUMBNAILS_FORMAT=
APP_POST_PER_PAGE=20
APP_INFINITE_SCROLL_POST_PER_PAGE=50

FRANKENPHP_WORKERS_NUMBER=1
FRANKENPHP_THREADS_NUMBER=2

########################################################################################################
#                                                DATABASE
########################################################################################################

DB_NAME=mendako
DB_HOST=mendako_postgresql
DB_PORT=5432
DB_USER=mendako
DB_PASSWORD=mendako
DB_VERSION=18

########################################################################################################
#                                                MACHINE LEARNING (AUTOTAG)
########################################################################################################
APP_AUTOTAG_ENABLED=0
APP_ML_URL=http://mendako_ml:8000
APP_AUTOTAG_AUTOVALIDATE_THRESHOLD_WD=30
APP_AUTOTAG_BOARDS_WITH_WD=board1,board2
```

####  Step 3 -> Review both files and update values if required

####  Step 4 -> Start Mendako
`docker compose up -d`

### Optional: Automatic tags

Mendako can suggest tags on upload using a **local** automatic tagging inference service. It is fully optional.

Tagging uses WD EVA02 Large v3, which produces Danbooru tags — it works well with illustrations but performs poorly on photographs. You can choose which board to autotag using `APP_AUTOTAG_BOARDS_WITH_WD`. 

For photographs I tried a few models but results were not good enough. If a better model is released at some point I'll add it. 

`APP_AUTOTAG_AUTOVALIDATE_THRESHOLD_WD` lets you choose the minimum level of confidence for auto validating a suggested tag. If under the threshold, the tag will stay suggested and will be available for manual review in `Tags -> Validation`. Basically a low value means some errors but nearly no human intervention, high value means less errors but more human work. 

You can run the command `docker compose exec mendako php php bin/console app:tag:delete-suggested` to remove all suggested tags if you want to experiment.

I personally set it to 30, nearly all tags are accepted. A small part of them are errors or redundant, but the vast majority are correct. 

A blacklist lets you remove some tags entirely for the auto suggestions.


### Available environment variables

| Name                                  | Description                                  | Possible values                                     |
|---------------------------------------|----------------------------------------------|-----------------------------------------------------|
| DB_USER                               | Your database user                           |                                                     |
| DB_PASSWORD                           | Your database password                       |                                                     |
| DB_HOST                               | Your database address                        |                                                     |
| DB_PORT                               | Your database port                           |                                                     |
| DB_NAME                               | Your database name                           |                                                     |
| DB_VERSION                            | Your database server version                 | ex: `18`                                          |
| APP_SECRET                            | Random string used for security              |                                                     |
| APP_ENV                               | Symfony environment, `prod` by default       | `prod` or `dev`                                     |
| APP_DEBUG                             | Activate Symfony debug mode, `0` by default  | `0` or `1`                                          |
| HTTPS_ENABLED                         | If your instance uses https                  | `0` or `1`                                          |
| UPLOAD_MAX_FILESIZE                   | Defaults to 20M                              |                                                     |
| PHP_MEMORY_LIMIT                      | Defaults to 512M                             |                                                     |
| WORKER_PHP_MEMORY_LIMIT               | Defaults to 2G                               |                                                     |
| PHP_TZ                          | Your timezone, default to Europe\Paris       | https://www.w3schools.com/php/php_ref_timezones.asp |
| APP_AUTOTAG_ENABLED                   | Enable autotag feature                       | `0` (default, off) or `1`                           |
| APP_ML_URL                            | URL of automatic tagging inference service   | default `http://mendako_ml:8000`                    |
| APP_AUTOTAG_AUTOVALIDATE_THRESHOLD_WD | Min percent to auto-validate a suggested tag | `0`–`100`, default `85`                             |
| APP_AUTOTAG_BOARDS_WITH_WD            | Boards tagged by the WD tagger               | comma-separated slugs, `*` for all, empty for none  |


## Support Mendako

There are a few things you can do to support Mendako :

* If you like Mendako please consider leaving a ⭐, it gives additional motivation to continue working on the project
* Report any bug or error you see
* English is not my first language, it would be a huge help if you could report any mistakes in Mendako.

You can contribute and edit translations here: https://crowdin.com/project/mendako.
If you wish to contribute to a new language, please open a discussion on github or crowdin and I'll gladly add it.
You are also welcome if you want to proofread existing translations.

### Translations status
<!-- CROWDIN-TRANSLATIONS-PROGRESS-ACTION-START -->


#### Available

<table><tr><td align="center" valign="top"><img width="30px" height="30px" title="English" alt="English" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/en.png"></div><div align="center" valign="top">100%</td><td align="center" valign="top"><img width="30px" height="30px" title="French" alt="French" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/fr.png"></div><div align="center" valign="top">100%</td></tr></table>

#### In progress

<table><tr><td align="center" valign="top"><img width="30px" height="30px" title="Japanese" alt="Japanese" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/ja.png"></div><div align="center" valign="top">76%</td><td align="center" valign="top"><img width="30px" height="30px" title="Portuguese, Brazilian" alt="Portuguese, Brazilian" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/pt-BR.png"></div><div align="center" valign="top">76%</td><td align="center" valign="top"><img width="30px" height="30px" title="Spanish" alt="Spanish" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/es-ES.png"></div><div align="center" valign="top">76%</td><td align="center" valign="top"><img width="30px" height="30px" title="Hungarian" alt="Hungarian" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/hu.png"></div><div align="center" valign="top">74%</td></tr></table>
<!-- CROWDIN-TRANSLATIONS-PROGRESS-ACTION-END -->

## Licensing
Mendako is an Open Source software, released under the MIT License. 
