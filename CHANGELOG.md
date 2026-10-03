# Changelog Blätter Export Epub

All notable changes to this module, grouped by topic. New entries go into the "Unreleased" section at the top; on deployment it gets the date and the branch.

Sections per version: Features, Security, Bugs, Removed, Maintenance (omit empty sections).

## Unreleased (branch `20261003_refactor`)

### Features
- Exported ePubs comply with the EPUB 2 specification (checked with epubcheck)
- Articles are converted into valid XHTML during the export; embedded videos become links
- Images within articles are embedded from the file system instead of being left as broken references
- The palm logo is embedded from the module, the export no longer depends on the live site
- The pepgen salt can be set per server in `settings.php`
- Unit tests for access, download, drush command, forms, chapters and the XHTML conversion

### Security
- Own permissions for the export and the settings, marked as restricted
- Download access is checked for the requesting user and not cached
- The pepgen salt is no longer shown in the settings form and, when set in `settings.php`, no longer ends up in the config export

### Bugs
- Export and settings were only accessible to administrators (permissions that did not exist)
- The drush command `bl-watermark` returned a wrong token (Drush instead of Drupal config, wrong date)
- Failed or incomplete downloads from pepgen were delivered as empty or broken files
- Authors with the same last name or sort value were missing in the author list and the byline
- Special characters in titles, subtitles, rubrics and author names could make the ePub invalid
- Image credits created invalid links and attributes; multiple lines were joined without space
- Quote marks of blockquotes were not shown because of invalid CSS
- The watermark in the imprint ran into the following sentence without a line break
- The export form crashed when the selected issue could not be found

### Removed
- Mobi download via Kindle mail including its settings (not offered since September 2022); update 10001 removes the old settings

### Maintenance
- Dependencies in `info.yml` and version constraints in `composer.json` added or tightened
- Download controller uses dependency injection and streaming instead of `readfile()`/`exit`
