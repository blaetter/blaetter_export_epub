# Blätter Export Epub

This module lets you select an issue from the "Blätter für deutsche und internationale Politik" and exports it as an epub. The mobi export and the mobi download via Kindle mail were removed, mobi is not offered since September 2022.

This module will only work within the Drupal installation of the Blätter, there is no further work planned to abstract or generalize this.

You are, however, free to fork or use this module for your own purposes.

## Hints

- The export can include some webfonts into the ePub (`IssueExporter::FONTS`). As of copyright these fonts are not part of this repository, they have to be put into `templates/font/` after installing the module. Fonts that are missing are left out of the ePub together with their `@font-face` rules (the export warns about them), so the ePub stays valid.
- The palm logo below the articles and the fonts are added from the module, so the export works without access to the live site.
- The identifier of the ePub is pinned to the domain of the Blätter (https://www.blaetter.de/node/…).

## Export and pepgen

The export page (`/admin/content/epub`) lists the issues, newest first and 20 per page, unpublished ones too (with their status), so the ePub of an issue can be prepared before it is published. The selected issues are exported as ePub with the placeholder for the watermark of pepgen in the imprint, and either downloaded (several as zip archive) or, when the original directory of pepgen is set in the settings (`/admin/settings/blaetter_export_epub`, `pepgen.original_dir`), handed over to pepgen directly; then this is the default action. Each row also offers the download of its ePub (`/admin/content/epub/{node}/download`). The hand over runs as batch, one issue per request:

- The ePub is unzipped into `<original directory>/<node id>.epub`, the format pepgen reads its originals in.
- It is unzipped into a hidden directory next to it first and swapped in afterwards, so pepgen never reads a half written ePub. The previous version stays as `.<node id>.epub.previous` (only the last one).
- The web server (and the user running drush) needs write access to the directory and the ePubs in it.
- pepgen keeps personalised ePubs it generated before; they are only rebuilt from the new original after they are removed from its public directory.

The same export is available on the command line, e.g. to export many issues at once:

```bash
drush --uri=https://www.blaetter.de blaetter_export_epub:export 33093
drush --uri=https://www.blaetter.de blaetter_export_epub:export --from=29382 --destination=/tmp/epubs
```

`--uri` is required, links relative to the website would point to `http://default` otherwise. Without `--destination` the ePubs are handed over to pepgen.

The export page includes unpublished articles of an issue (it runs as the editor, who may see them), so an ePub can be prepared before the articles are published. Drush runs as anonymous user and leaves them out; for each issue with unpublished articles it lists them and asks whether to export it anyway (`--yes`: export, `--no` or `-n`: skip). Prepare issues with unpublished articles on the export page.

At most 10 issues can be downloaded at once, they are built within one request; the hand over to pepgen runs as batch and has no limit. A lock per issue keeps two hand overs of the same issue from interfering.

## Imprint, authors and footnotes

The imprint comes from `templates/imprint.tmpl` for new issues. An issue that has an ePub in the original directory of pepgen already keeps the imprint of that ePub when it is exported again, so old issues keep their imprint of the time (only the placeholder for the watermark is brought to the current form). Drush reads it from the configured directory also with `--destination`.

The authors page is taken over the same way, as the short bios of the authors change over the years, but only if it lists the same authors as the current one (e.g. not after an article was added or an author renamed); otherwise the current authors page is built. Drush reports for every issue whether imprint and authors come from the existing ePub or are current.

Footnotes of Word (`div#ftn1`, anchors `#_ftn1`) and LibreOffice (`div#sdfootnote1`) are collected into one block `div.Ebook-Fussnoten` after the text, styled in `templates/css/styles.css`.

Embedded videos become links. Embedded players that are of no use in an ePub (podcasts of detektor.fm, `XhtmlSanitizer::DROPPED_EMBED_HOSTS`) are left out with their paragraph, as are the inserts of blocks, contents and media (`[content:13]`) that the website renders into the body.

## Translation

All texts of the user interface are English source strings passed through `t()` (or `TranslatableMarkup`), the content of the ePub itself is German. The German translation is part of the module (`translations/de.po`) and registered in the info file, so `drush locale:check` and `drush locale:update` import it; translations changed by hand in the interface are kept. To import only this file:

```bash
drush locale:import de "$PWD/web/modules/contrib/blaetter_export_epub/translations/de.po" --type=not-customized --override=not-customized
```

The path has to be absolute (drush changes into the Drupal root). Contexts of `t()` have to be ASCII.
