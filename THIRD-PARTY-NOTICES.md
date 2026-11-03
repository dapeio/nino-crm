# Third-party notices

Nino's own code is released under the [MIT License](LICENSE). It bundles no
third-party library, but it draws icons from one: the workbench's pages, the
install wizard and the `icon()` of every workbench panel inline SVG icons
from [Lucide](https://lucide.dev), and the kernel's components carry Lucide's
icons as geometry in `Components.php`. Lucide is released under the ISC License;
the icons it took over from Feather, the project it grew out of, are under
the MIT License as well. Both licenses ask for their notice to travel with
every copy of the icons, and this file is where it does. The one other thing
Nino delivers that is not its own is a typeface, [Open Sans](#open-sans), which
the base install unit copies into every new project.

| Files | Icons |
| --- | --- |
| `_admin/templates/page-*.tpl`, `_admin/install/templates/page-*.tpl` | the Nino mark (`box`), the workbench's toggles, the wizard's step and progress icons |
| `_admin/Nino/Modules/*/Admin/Admin.php`, `_nino/Nino/Modules/*/Admin/Admin.php` | one per panel, the one its entry in the workbench menu shows |
| `_nino/Nino/Modules/Components/Components.php` | the geometry of the sixteen components' icons, as a tool shows them |

Most of them keep Lucide's own class names - `lucide lucide-<name>` - in
their markup, which is how to find them.

## Lucide

The sixteen components' icons are ten icons of Lucide 0.577.0 - heading, type,
text-align-start, panel-top, mouse-pointer-click, image, contrast, code,
separator-horizontal and chevron-down - transcribed as the parts inside the
`<svg>` of each file and kept as data that a tool draws, not as markup. Of
these, `code`, `type` and `chevron-down` are among the icons derived from
Feather that are listed below.

ISC License

Copyright (c) 2026 Lucide Icons and Contributors

Permission to use, copy, modify, and/or distribute this software for any
purpose with or without fee is hereby granted, provided that the above
copyright notice and this permission notice appear in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.

---

The following Lucide icons are derived from the Feather project:

airplay, alert-circle, alert-octagon, alert-triangle, aperture, arrow-down-circle, arrow-down-left, arrow-down-right, arrow-down, arrow-left-circle, arrow-left, arrow-right-circle, arrow-right, arrow-up-circle, arrow-up-left, arrow-up-right, arrow-up, at-sign, calendar, cast, check, chevron-down, chevron-left, chevron-right, chevron-up, chevrons-down, chevrons-left, chevrons-right, chevrons-up, circle, clipboard, clock, code, columns, command, compass, corner-down-left, corner-down-right, corner-left-down, corner-left-up, corner-right-down, corner-right-up, corner-up-left, corner-up-right, crosshair, database, divide-circle, divide-square, dollar-sign, download, external-link, feather, frown, hash, headphones, help-circle, info, italic, key, layout, life-buoy, link-2, link, loader, lock, log-in, log-out, maximize, meh, minimize, minimize-2, minus-circle, minus-square, minus, monitor, moon, more-horizontal, more-vertical, move, music, navigation-2, navigation, octagon, pause-circle, percent, plus-circle, plus-square, plus, power, radio, rss, search, server, share, shopping-bag, sidebar, smartphone, smile, square, table-2, tablet, target, terminal, trash-2, trash, triangle, tv, type, upload, x-circle, x-octagon, x-square, x, zoom-in, zoom-out

The MIT License (MIT) (for the icons listed above)

Copyright (c) 2013-present Cole Bemis

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## Open Sans

The base install unit delivers one typeface, [Open Sans](https://github.com/googlefonts/opensans),
in the weights 300, 400 and 700, and the look a project starts from is set in
it. The fonts are the static TTFs of Open Sans 3.003, converted to woff2 without
subsetting. The unit copies its `fonts` directory into a project's
`public/fonts/` as it is, so the license, which asks for its text to travel
with every copy of the fonts, goes along as `open-sans-OFL.txt`.

| Files | Font |
| --- | --- |
| `_admin/install/library/base/fonts/open-sans-light.woff2` | Open Sans Light, 300 |
| `_admin/install/library/base/fonts/open-sans-regular.woff2` | Open Sans Regular, 400 |
| `_admin/install/library/base/fonts/open-sans-bold.woff2` | Open Sans Bold, 700 |
| `_admin/install/library/base/fonts/open-sans-OFL.txt` | the license of the three, as the project publishes it |

SIL Open Font License, Version 1.1

```text
Copyright 2020 The Open Sans Project Authors (https://github.com/googlefonts/opensans)

-----------------------------------------------------------
SIL OPEN FONT LICENSE Version 1.1 - 26 February 2007
-----------------------------------------------------------

PREAMBLE
The goals of the Open Font License (OFL) are to stimulate worldwide
development of collaborative font projects, to support the font
creation efforts of academic and linguistic communities, and to
provide a free and open framework in which fonts may be shared and
improved in partnership with others.

The OFL allows the licensed fonts to be used, studied, modified and
redistributed freely as long as they are not sold by themselves. The
fonts, including any derivative works, can be bundled, embedded,
redistributed and/or sold with any software provided that any reserved
names are not used by derivative works. The fonts and derivatives,
however, cannot be released under any other type of license. The
requirement for fonts to remain under this license does not apply to
any document created using the fonts or their derivatives.

DEFINITIONS
"Font Software" refers to the set of files released by the Copyright
Holder(s) under this license and clearly marked as such. This may
include source files, build scripts and documentation.

"Reserved Font Name" refers to any names specified as such after the
copyright statement(s).

"Original Version" refers to the collection of Font Software
components as distributed by the Copyright Holder(s).

"Modified Version" refers to any derivative made by adding to,
deleting, or substituting -- in part or in whole -- any of the
components of the Original Version, by changing formats or by porting
the Font Software to a new environment.

"Author" refers to any designer, engineer, programmer, technical
writer or other person who contributed to the Font Software.

PERMISSION & CONDITIONS
Permission is hereby granted, free of charge, to any person obtaining
a copy of the Font Software, to use, study, copy, merge, embed,
modify, redistribute, and sell modified and unmodified copies of the
Font Software, subject to the following conditions:

1) Neither the Font Software nor any of its individual components, in
Original or Modified Versions, may be sold by itself.

2) Original or Modified Versions of the Font Software may be bundled,
redistributed and/or sold with any software, provided that each copy
contains the above copyright notice and this license. These can be
included either as stand-alone text files, human-readable headers or
in the appropriate machine-readable metadata fields within text or
binary files as long as those fields can be easily viewed by the user.

3) No Modified Version of the Font Software may use the Reserved Font
Name(s) unless explicit written permission is granted by the
corresponding Copyright Holder. This restriction only applies to the
primary font name as presented to the users.

4) The name(s) of the Copyright Holder(s) or the Author(s) of the Font
Software shall not be used to promote, endorse or advertise any
Modified Version, except to acknowledge the contribution(s) of the
Copyright Holder(s) and the Author(s) or with their explicit written
permission.

5) The Font Software, modified or unmodified, in part or in whole,
must be distributed entirely under this license, and must not be
distributed under any other license. The requirement for fonts to
remain under this license does not apply to any document created using
the Font Software.

TERMINATION
This license becomes null and void if any of the above conditions are
not met.

DISCLAIMER
THE FONT SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO ANY WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT
OF COPYRIGHT, PATENT, TRADEMARK, OR OTHER RIGHT. IN NO EVENT SHALL THE
COPYRIGHT HOLDER BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
INCLUDING ANY GENERAL, SPECIAL, INDIRECT, INCIDENTAL, OR CONSEQUENTIAL
DAMAGES, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
FROM, OUT OF THE USE OR INABILITY TO USE THE FONT SOFTWARE OR FROM
OTHER DEALINGS IN THE FONT SOFTWARE.
```

## The icons of the project's features

The six features that live in this repository - Members, Pdf, Files, Review,
Sign and Events - draw their icons the way the kernel does: the `icon()` of
each feature's workbench panel (`features/*/Admin/Admin.php`) and the `icon` of
every component a manifest declares (`features/*/feature.php`) carry the
geometry of inline SVG icons from [Lucide](https://lucide.dev), release 0.577.0.
They are covered by the [Lucide](#lucide) section above: the ISC License and,
for the icons derived from Feather, the MIT License, whose notices are quoted
there and travel with these copies as well.

## Adobe Core 14 AFM metrics

The Pdf feature measures text in the twelve standard fonts it can set - Helvetica,
Times and Courier, each in regular, bold, italic and bold italic - by their
character widths, and those are the metrics of Adobe's Core 14 AFM files as
[Apache PDFBox](https://github.com/apache/pdfbox) redistributes them
(`pdfbox/src/main/resources/org/apache/pdfbox/resources/afm/`). Each font has a
file of its own in `features/Pdf/Fonts/Metrics/`, a modified form of the AFM file:
the widths of the glyphs WinAnsiEncoding addresses - the codes 32 to 255 - are all
it keeps, and nothing of a font program is part of it. The two other fonts of the
set, Symbol and ZapfDingbats, are not used and not shipped.

Adobe asks that its copyright notices are retained, that a modification is noted
in the modified file and that its paragraph travels with the files. The head of
each of the twelve files carries the notices, says that it is modified and quotes
the paragraph - a feature is published as an archive of its own directory, which
does not carry this file - and this file does as well.

| Files | Font | Notice of the AFM file |
| --- | --- | --- |
| `Fonts/Metrics/Helvetica.php`, `Helvetica-Bold.php`, `Helvetica-Oblique.php`, `Helvetica-BoldOblique.php` | Helvetica | Copyright (c) 1985, 1987, 1989, 1990, 1997 Adobe Systems Incorporated.  All Rights Reserved. Helvetica is a trademark of Linotype-Hell AG and/or its subsidiaries. |
| `Fonts/Metrics/Times-Roman.php`, `Times-Bold.php`, `Times-Italic.php`, `Times-BoldItalic.php` | Times | Copyright (c) 1985, 1987, 1989, 1990, 1993, 1997 Adobe Systems Incorporated.  All Rights Reserved. Times is a trademark of Linotype-Hell AG and/or its subsidiaries. |
| `Fonts/Metrics/Courier.php`, `Courier-Oblique.php` | Courier | Copyright (c) 1989, 1990, 1991, 1992, 1993, 1997 Adobe Systems Incorporated.  All Rights Reserved. |
| `Fonts/Metrics/Courier-Bold.php`, `Courier-BoldOblique.php` | Courier | Copyright (c) 1989, 1990, 1991, 1993, 1997 Adobe Systems Incorporated.  All Rights Reserved. |

The terms Adobe distributes the Core 14 AFM files under, as the `MustRead.html` of the
set states them and as the `LICENSE.txt` of Apache PDFBox quotes them:

```text
This file and the 14 PostScript(R) AFM files it accompanies may be used,
copied, and distributed for any purpose and without charge, with or without
modification, provided that all copyright notices are retained; that the
AFM files are not distributed without this file; that all modifications
to this file or any of the AFM files are prominently noted in the modified
file(s); and that this paragraph is not modified. Adobe Systems has no
responsibility or obligation to support the use of the AFM files.
```
