# Fonts of the ePub

The fonts of the website, embedded into every ePub (`IssueExporter::FONTS`).

| Files | Font | License | Source |
|---|---|---|---|
| `DroidSerif-*.ttf` | Droid Serif 1.03, © 2012, 2013 Google | Apache License 2.0 (`LICENSE-Apache-2.0.txt`) | Android Open Source Project, `platform/frameworks/base`, `data/fonts` (branch `kitkat-release`) |
| `DroidSans-*.ttf` | Droid Sans 1.00, © 2007 Google | Apache License 2.0 (`LICENSE-Apache-2.0.txt`) | Android Open Source Project, `platform/frameworks/base`, `data/fonts` (branch `kitkat-release`), `DroidSans.ttf` renamed to `DroidSans-Regular.ttf` |
| `PlayfairDisplay-*.ttf` | Playfair Display 1.203, © 2017 The Playfair Display Project Authors | SIL Open Font License 1.1 (`OFL.txt`) | Google Fonts, `ofl/playfairdisplay`; static instances (weight 400, 700, italic 400) of the variable fonts, made with `fontTools.varLib.instancer` |

The Droid fonts of Google Fonts and of the website contain only a Latin subset (about 200 characters, without e.g. ğ, ć, š, ł or …); the Android versions cover Latin, Greek and Cyrillic. Arabic, Hebrew and CJK are left to the reader.
