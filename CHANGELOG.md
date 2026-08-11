# Changelog
All notable changes to this project will be documented in this file.

## [2.0.0] / 2026-08-11

## :warning: Breaking changes
- A new volume for thumbnails needs to be mounted (see README)

- The PostgreSQL image  must be changed from `postgres:15` to `pgvector/pgvector:pg15`. Replace the 15 by your current version of PostgreSQL

- This release introduce a new way of handling thumbnails, you need to regenerate them through `Administration -> Jobs -> Regenerate thumbnails`
Until the job is launched and completed, all your thumbnails will be blank.

- The duplicates detection was also reworked, please run the job `Administration -> Jobs -> Regenerate vectors`

### Features
- Add optional autotagging feature, see README (benjaminjonard)
- New bulk upload page. User can now drag and drop multiple images in a staging page for quicker board attribution (benjaminjonard)
- Add a new Jobs panel in Administration :
  - `Tag existing posts` -> run auto tagging task on selected boards, can take a long time depending on the number of images you have
  - `Regenerate vectors` -> used for duplicates detection
  - `Regenerate thumbnails`
- Rework tags page, add pagination, filtering and ordering (benjaminjonard)
- Two tags can now be merged into one (benjaminjonard)

### Miscellaneous
- Rework thumbnails handling (benjaminjonard)
- Rework duplicate detection (benjaminjonard)
- Docker image now uses FrankenPHP as the web server (benjaminjonard)
- Upgrade PHP and JS dependencies (benjaminjonard)

## [1.4.0] / 2026-01-25
### Miscellaneous
- Upgrade PHP to 8.5, Symfony to 8.0 and JS dependencies (benjaminjonard)

## [1.3.1] / 2025-12-21
### Miscellaneous
- Upgrade PHP (Symfony 7.4) and JS dependencies (benjaminjonard)

## [1.3.0] / 2025-06-05
### Features
- Allow video/x-m4v mime type (benjaminjonard)

### Miscellaneous
- Update to PHP 8.4 (benjaminjonard)
- Upgrade PHP (Symfony 7.3) and JS dependencies (benjaminjonard)

## [1.2.6] / 2024-10-28
### Features
- Add Portuguese (Brazilian) language (Lucasofchirstms)

### Miscellaneous
- Fix deprecations (benjaminjonard)
- Upgrade PHP and JS dependencies (benjaminjonard)

## [1.2.5] / 2024-08-16
### Miscellaneous
- Delay thumbnail generation by 10% of the video length, trying to avoid all black or white thumbnails  (benjaminjonard)
- Upgrade PHP and JS dependencies (benjaminjonard)
- Update docker image to Ubuntu 24 noble (benjaminjonard)

## [1.2.4] / 2024-07-16
### Features
- Add metrics endpoint using OpenTelemetry format, used for statistics (benjaminjonard)
- Allow changing board when editing a Post (benjaminjonard)
- Add ability to add a comment on a Post (benjaminjonard)

### Miscellaneous
- Upgrade PHP and JS dependencies (benjaminjonard)
- Update to Bulma v1 (benjaminjonard)**

## [1.2.3] / 2024-02-09
### Features
- Add Hungarian language (forms55)
- Add language selector on login page (benjaminjonard)

### Miscellaneous
- Upgrade PHP and JS dependencies (benjaminjonard)

## [1.2.2] / 2024-01-19
### Fix
- Fix board infinite scroll (benjaminjonard)

### Miscellaneous
- Upgrade PHP and JS dependencies (benjaminjonard)

## [1.2.1] / 2024-01-11
### Fix
- Fix modal not appearing (benjaminjonard)

### Miscellaneous
- Fix deprecations (benjaminjonard)
- Upgrade PHP and JS dependencies, fix CVE-2023-26159 (benjaminjonard)

## [1.2.0] / 2024-01-02
### Features
- Add support for SVG files (benjaminjonard)

### Fix
- Fix missing translations (benjaminjonard)

### Miscellaneous
- Update to PHP 8.3 (benjaminjonard)
- Upgrade PHP (Symfony 7.0) and JS dependencies (benjaminjonard)
- Update to yarn 4 (benjaminjonard)

## [1.1.5] / 2023-08-05
### Features
- Add Spanish language (phampyk)
- Add env variables to change number of posts per page (benjaminjonard)

### Miscellaneous
- Update PHP and JS dependencies (benjaminjonard)
- Add a few data in admin dashboard (benjaminjonard)

## [1.1.4] / 2023-07-05
### Features
- Add basic admin dashboard (benjaminjonard)

### Fix
- Fix duration display when duration < 1 sec (benjaminjonard)
- Use ffmpeg to generate better gif thumbnails (benjaminjonard)

### Miscellaneous
- Update PHP and JS dependencies (benjaminjonard)
- Add volume icon on posts that have sound (benjaminjonard)
- Update breadcrumb for search (benjaminjonard)

## [1.1.3] / 2023-05-26
### Features
- Add option to enable infinite scrolling (benjaminjonard)
- Basic detection of similar images when creating a new post, in beta state (benjaminjonard)
- Add support for AVIF image format (benjaminjonard)
- Add `THUMBNAILS_FORMAT` env variable, supports `jpeg`, `png`, `webp` and `avif` (benjaminjonard)

### Fix
- Fix filename when clicking on download button (benjaminjonard)

### Miscellaneous
- Move Docker base image from Debian to Ubuntu (benjaminjonard)
- Move thumbnails to dedicated folder, see README for new Docker volume (benjaminjonard)
- Update PHP and JS dependencies (benjaminjonard)

## [1.1.2] / 2023-03-29
### Fix
- Fix permission issues with uploads folder (benjaminjonard)

### Miscellaneous
- Update PHP and JS dependencies (benjaminjonard)

## [1.1.1] / 2023-03-22
### Miscellaneous
- Use default browser theme when necessary (benjaminjonard)
- Rework login page (benjaminjonard)
- Add logout button (benjaminjonard)
- Add new env variables `PHP_MEMORY_LIMIT` and `UPLOAD_MAX_FILESIZE` (benjaminjonard)
- Update PHP and JS dependencies, fix CVE-2022-24895 (benjaminjonard)

## [1.1.0] / 2023-01-23
### Features
- Add support for translations via Crowdin, add French translation (benjaminjonard)

### Miscellaneous
- Update to PHP 8.2 (benjaminjonard)
- Update PHP and JS dependencies (benjaminjonard)

## [1.0.1] / 2022-11-30
### Features
- Add tag delete (benjaminjonard)

### Fixes
- Fix mobile navbar colors when using dark mode (benjaminjonard)
- Make tags not required when adding a post (benjaminjonard)

### Miscellaneous
- Add functional tests (benjaminjonard)
- Update PHP and JS dependencies (benjaminjonard)

## [1.0.0] / 2022-10-15
- Initial release (benjaminjonard)
