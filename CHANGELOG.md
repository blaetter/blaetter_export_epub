# Changelog Blätter Export Epub

All notable changes to this module, grouped by topic. New entries go into the "Unreleased" section at the top; on deployment it gets the date and the branch.

Sections per version: Features, Security, Bugs, Removed, Maintenance (omit empty sections).

## Unreleased

### Features
- The export page lists the issues as table (newest first, 20 per page) with an explanation above and multiple selection; the actions sit below the table in Claro's bulk actions bar, like in the order overview of NodeShop (dark, with the number of selected items, sticky while items are selected); each row links to the issue and offers the download of its ePub and editing
- German translation in `translations/de.po`, registered as interface translation of the module, so `drush locale:update` imports it; all texts of the user interface are English source strings passed through `t()`
- The selected issues can be handed over to pepgen (the default when its original directory is set, new setting) or downloaded (up to 10 at once, several as zip archive, built one after the other into temporary files): the hand over runs as batch, unzips each ePub into the original directory of pepgen, keeps the previous version as hidden backup and only replaces it when the new one is complete; the table shows since when pepgen has each ePub
- Drush command `blaetter_export_epub:export` (`bl-export`) exports one or more issues (or all from a node id on, `--from`) and hands them over to pepgen or writes them into a directory (`--destination`); it needs `--uri` for links relative to the website. Drush runs as anonymous user and leaves unpublished articles out, so it lists them for each issue that has some and asks before exporting it (`--yes` exports, `--no` or `-n` skips the issue)
- A lock per issue keeps the export page and drush from replacing the same ePub at the same time; a hand over that was interrupted between moving the old and the new version aside is repaired by the next one
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
- Messages for subscribers when the download of an ePub failed were shown in English, they had no translation
- Fonts that are not installed in `templates/font/` were still listed in the manifest and the styles, so every ePub since 2020 had 6 epubcheck errors; missing fonts are now left out together with their `@font-face` rules, and the export warns about them
- The cover and the lead images of the articles were missing when the website could not be reached from the server itself (e.g. on the stage server behind a password): they are read from the file system instead of being fetched via their URL; images in a public files directory outside the Drupal installation (symlink) are embedded as well
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
- The download is a regular response of Drupal instead of `sendBook()` of PHPePub writing directly to the output; `Generator::createEpub()` was removed
- The export of an issue moved from the form into `IssueExporter`, used by the form and the drush command; the drush command gets the request stack and the path resolver injected
- Dependencies in `info.yml` and version constraints in `composer.json` added or tightened
- Download controller uses dependency injection and streaming instead of `readfile()`/`exit`
