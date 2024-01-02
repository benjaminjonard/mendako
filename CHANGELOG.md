# Changelog
All notable changes to this project will be documented in this file.

## [1.2.0] / 2024-01-02
### Miscellaneous
- Update to PHP 8.3 (benjaminjonard)
- Upgrade PHP (Symfony 7.0) and JS dependencies (benjaminjonard)

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
