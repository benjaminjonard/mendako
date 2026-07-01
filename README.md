<p align="center">
<img src="https://img.shields.io/github/license/benjaminjonard/mendako" />    
    <img src="https://img.shields.io/github/v/release/benjaminjonard/mendako" />
    <img src="https://img.shields.io/badge/php->=8.2-blue" />
    <img src="https://img.shields.io/badge/postgresql->=10.0-blue" />            
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
version: '3.4'

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
            - "./volumes/mendako/public/thumbnails:/thumbnails" #Not mandatory

    mendako_postgresql:
        container_name: mendako_postgresql
        image: postgres:15
        restart: unless-stopped
        env_file:
            - .env
        environment:
            - POSTGRES_DB=${DB_NAME}
            - POSTGRES_USER=${DB_USER}
            - POSTGRES_PASSWORD=${DB_PASSWORD}
        volumes:
            - "./volumes/postgresql:/var/lib/postgresql/data"
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
########################################################################################################

APP_DEBUG=0
APP_ENV=prod
#APP_SECRET=

HTTPS_ENABLED=1
UPLOAD_MAX_FILESIZE=20M
PHP_MEMORY_LIMIT=512M
PHP_TZ=Europe/Paris

APP_THUMBNAILS_FORMAT=
APP_POST_PER_PAGE=20
APP_INFINITE_SCROLL_POST_PER_PAGE=50

########################################################################################################
#                                                DATABASE
########################################################################################################

DB_NAME=mendako
DB_HOST=mendako_postgresql
DB_PORT=5432
DB_USER=mendako
DB_PASSWORD=mendako
DB_VERSION=15

```

####  Step 3 -> Review both files and update values if required

####  Step 4 -> Start Mendako
`docker-compose up -d`

### Optional: Automatic tags

Mendako can suggest tags on upload using a **local** automatic tagging inference service. It is fully optional: if you do not add the service below, Mendako runs exactly as before, with no extra dependency and no added runtime cost.

To enable it, add the `mendako_ml` service **and** the `mendako_worker` (which processes the tagging queue) to your `docker-compose.yml` (both only start with the `autotag` profile):
```
    mendako_ml:
        container_name: mendako_ml
        image: benjaminjonard/mendako-ml
        restart: unless-stopped
        profiles: ["autotag"]

    mendako_worker:
        container_name: mendako_worker
        image: benjaminjonard/mendako
        restart: unless-stopped
        profiles: ["autotag"]
        env_file:
            - .env
        depends_on:
            - mendako_postgresql
        entrypoint: ["php", "bin/console", "messenger:consume", "autotag_interactive", "autotag_batch", "--time-limit=3600", "--memory-limit=128M"]
```
The worker drains `autotag_interactive` (uploads) before `autotag_batch` (retroactive/backlog tagging), so interactive tagging always takes priority. For a large backlog you may run a second worker dedicated to `autotag_batch` only.
Then start the stack with the profile:

`docker-compose --profile autotag up -d`

Notes:
- The service is **internal only** (no published port); the app reaches it at `http://mendako_ml:8000`. All inference runs locally — no data leaves your server.
- Set `MENDAKO_ML_URL` in your `.env` only if you run the service elsewhere (default `http://mendako_ml:8000`).
- The automatic tagging feature requires a `pgvector`-enabled PostgreSQL image (e.g. `pgvector/pgvector:pg16`).
- Once the service is running, the admin automatic tagging page (under Administration) lists the available models and their status per category, lets you **download or remove** each model (one click, stored in the models volume), and lets you choose the active model.
- When a **CLIP** model is active, each processed item also gets a semantic embedding (stored alongside its tags), which powers two kinds of learned suggestions: tags propagated from your most visually similar already-tagged items (kNN), and zero-shot matches of the image against your own tag names (so photos of animals/objects the illustration tagger can't read are still covered). These learned suggestions are always offered as click-to-add chips — never auto-applied. Additive and optional — without a CLIP model, only the tagger runs.

#### Switching to a different-dimension CLIP model

Embeddings are bound to the dimension of the model that produced them (the shipped `siglip2-so400m` is 1152). If you ever switch to a CLIP model of a **different** dimension, run the maintenance command so the embedding column is re-dimensioned and every item is re-embedded:

```bash
docker exec mendako_worker php bin/console app:autotag:reindex-clip-embeddings <dimension>
```

It drops the index, purges the old embeddings, re-dimensions the column, recreates the index, and re-dispatches every item for re-embedding. **No tags are lost** (only embeddings are purged — they are recomputable). You must also update the `clipVector` `dimensions` mapping on `Post`/`StagedUpload` to match the new model.

#### Tagging your existing backlog

To generate suggestions for posts that predate the feature, run:

```bash
docker exec mendako_worker php bin/console app:autotag:tag-backlog
```

This enqueues posts that have **never been automatic tagging-processed** (no suggestion yet) on the deprioritized `autotag_batch` queue, so it never competes with interactive uploads. A post whose suggestions were all accepted or dismissed is left alone. Add `--staged` to tag the staging area instead, or `--all` to re-process **every** item (e.g. after switching models) — this re-runs inference on the whole set, so it is costly. Re-running before the queue drains duplicates work (the handler is idempotent, so results stay correct). Suggestions are never auto-applied — review them in the normal tag UI.

To check progress at any time:

```bash
docker exec mendako_worker php bin/console app:autotag:batch-status
```

It reports, for posts and for staged uploads, how many have been automatic tagging-processed (have suggestions) out of the total, with a completion state.

### Mirroring the automatic tagging models (maintainer only)

End users never need this — they download models from the admin UI with no Hugging Face account. This section is for the **maintainer**: the catalog points at **public** Hugging Face mirrors of the models so every self-hoster can download them anonymously, even if an upstream repo disappears. Both shipped models are Apache-2.0, so redistribution is allowed as long as the `LICENSE`/`NOTICE` is kept.

To (re)create the mirrors:

1. Create a Hugging Face account, then a **write** access token at https://huggingface.co/settings/tokens (scope: *write*). Keep it private. Install the CLI with `pip install -U "huggingface_hub[cli]"` (on distros with an externally-managed Python, e.g. Arch, use a virtualenv or `pipx install "huggingface_hub[cli]"`), then `hf auth login`.
2. For each model, download the upstream ONNX files, create a **public** repo under your namespace, and upload them (keeping `LICENSE`, adding a `NOTICE` crediting the upstream author):
   - **WD tagger** — upstream `SmilingWolf/wd-eva02-large-tagger-v3` (`model.onnx`, `selected_tags.csv`) → `benjaminjonard/wd-eva02-large-tagger-v3-onnx`.
   - **SigLIP2** — the Immich SigLIP2 ONNX export `immich-app/ViT-SO400M-16-SigLIP2-384__webli` (`visual/model.onnx`, `textual/model.onnx`, `tokenizer.json`) → `benjaminjonard/siglip2-so400m-onnx`, normalized to `visual.onnx` / `textual.onnx` / `tokenizer.json`. The `tokenizer.json` is required for zero-shot tagging (the text encoder consumes token ids); make sure it is mirrored alongside the two `.onnx` files.
   ```
   hf download SmilingWolf/wd-eva02-large-tagger-v3 model.onnx selected_tags.csv --local-dir ./mirror-wd
   hf repo create wd-eva02-large-tagger-v3-onnx        # public by default
   hf upload benjaminjonard/wd-eva02-large-tagger-v3-onnx ./mirror-wd .
   ```
   *(`hf` replaces the deprecated `huggingface-cli`.)*
3. Confirm each repo is **public** on huggingface.co, then pin the catalog: set `repo_id` + the head-commit `revision` (SHA) for each entry in `ml/app/catalog.py`.

### Available environment variables

| Name                | Description                                 | Possible values                                     |
|---------------------|---------------------------------------------|-----------------------------------------------------|
| DB_USER             | Your database user                          |                                                     |
| DB_PASSWORD         | Your database password                      |                                                     |
| DB_HOST             | Your database address                       |                                                     |
| DB_PORT             | Your database port                          |                                                     |
| DB_NAME             | Your database name                          |                                                     |
| DB_VERSION          | Your database server version                | ex: `10.3`                                          |
| APP_SECRET          | Random string used for security             |                                                     |
| APP_ENV             | Symfony environment, `prod` by default      | `prod` or `dev`                                     |
| APP_DEBUG           | Activate Symfony debug mode, `0` by default | `0` or `1`                                          |
| HTTPS_ENABLED       | If your instance uses https                 | `0` or `1`                                          |
| UPLOAD_MAX_FILESIZE | Defaults to 20M                             |                                                     |
| PHP_MEMORY_LIMIT    | Defaults to 512M                            |                                                     |
| PHP_TIMEZONE        | You timezone, default to Europe\Paris       | https://www.w3schools.com/php/php_ref_timezones.asp |
| MENDAKO_AUTOTAG_ENABLED  | Hard master switch for Automatic tags    | `0` (default, off) or `1`                           |
| MENDAKO_ML_URL      | URL of the optional automatic tagging inference service    | default `http://mendako_ml:8000`                    |


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

<table><tr><td align="center" valign="top"><img width="30px" height="30px" title="English" alt="English" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/en.png"></div><div align="center" valign="top">100%</td><td align="center" valign="top"><img width="30px" height="30px" title="Japanese" alt="Japanese" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/ja.png"></div><div align="center" valign="top">100%</td><td align="center" valign="top"><img width="30px" height="30px" title="Portuguese, Brazilian" alt="Portuguese, Brazilian" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/pt-BR.png"></div><div align="center" valign="top">100%</td><td align="center" valign="top"><img width="30px" height="30px" title="Spanish" alt="Spanish" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/es-ES.png"></div><div align="center" valign="top">100%</td><td align="center" valign="top"><img width="30px" height="30px" title="French" alt="French" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/fr.png"></div><div align="center" valign="top">98%</td><td align="center" valign="top"><img width="30px" height="30px" title="Hungarian" alt="Hungarian" src="https://raw.githubusercontent.com/benjaminjonard/crowdin-translations-progress-action/1.0/flags/hu.png"></div><div align="center" valign="top">97%</td></tr></table>
<!-- CROWDIN-TRANSLATIONS-PROGRESS-ACTION-END -->

## Licensing
Mendako is an Open Source software, released under the MIT License. 
