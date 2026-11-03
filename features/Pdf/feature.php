<?php
// features/Pdf/feature.php - what the Features panel reads. The class is not
// declared here: features/Pdf/ can only ever serve \Nino\Modules\Pdf.
return [
	'key'					=> 'pdf',
	'name'				=> [ 'en_US' => 'PDF', 'de_DE' => 'PDF' ],
	'description'	=> [
		'en_US' => 'A PDF builder with no library: a layout the owner sets once - paper, margins, font, header and footer with page numbers, a logo and their own signature -, a library of text blocks in HTML, and templates composed of them - text, a slot for a table or any HTML, a free-text input, a signature field at a position in millimetres, a page break - each with a tag of the project\'s list. render() turns a template and what a caller knows - and, where it wants one, a part of HTML appended on a page of its own after the last block (the option append) - into the bytes of a PDF, the same bytes every time; templates(), fields(), inputs(), tags() and layout() answer what a caller needs to offer them.',
		'de_DE' => 'Ein PDF-Baukasten ohne Bibliothek: ein Layout, das der Inhaber einmal einstellt - Papier, Ränder, Schrift, Kopf- und Fußzeile mit Seitenzahlen, ein Logo und die eigene Unterschrift -, eine Bibliothek von Textbausteinen in HTML und Vorlagen daraus - Text, ein Platzhalter für eine Tabelle oder beliebiges HTML, ein Freitextfeld, ein Unterschriftfeld an einer Stelle in Millimetern, ein Seitenumbruch -, jede mit einem Tag aus der Liste des Projekts. render() macht aus einer Vorlage und dem, was ein Aufrufer weiß - und, wo er einen will, einem HTML-Teil, der nach dem letzten Block auf einer eigenen Seite angehängt wird (die Option append) -, die Bytes eines PDFs, jedes Mal dieselben; templates(), fields(), inputs(), tags() und layout() antworten, was ein Aufrufer braucht, um sie anzubieten.',
	],
	// What the Description tab shows: all six sections, the empty ones too
	'manual'			=> [
		'shortcodes' => [],
		'markup' => [
			[ 'en_US' => 'Nothing on the site: a PDF is no element of a page.', 'de_DE' => 'Nichts auf der Website: Ein PDF ist kein Element einer Seite.' ],
		],
		'routes' => [],
		'panel' => [
			'PDF' => [
				'en_US' => 'Three tabs on top of the panel - Layout, Text blocks and Templates - and the Settings tab the kernel adds, where the tags of the project are. Everything the panel stores is data of the project, below data/pdf/, and a backup carries it.',
				'de_DE' => 'Drei Tabs oben im Panel - Layout, Textbausteine und Vorlagen - und der Tab Einstellungen, den der Kernel ergänzt und in dem die Tags des Projekts stehen. Was das Panel speichert, sind Daten des Projekts unter data/pdf/, und das Backup nimmt sie mit.',
			],
			'Layout' => [
				'en_US' => 'The panel\'s own screen: the paper (A4 or US Letter), the four margins in millimetres (5 to 50), the font (Helvetica, Times or Courier) and the base size in points (8 to 14), the header and the footer as HTML - at most 4000 characters each and 40 mm high on every page - and two pictures, the logo and the owner\'s signature, a PNG or a JPEG of at most 4000 by 4000 pixels, which are put in and taken away at once and are no part of the form. In the header and the footer [[page]] and [[pages]] are the page numbers. The paper is not changed under the templates: while a signature box of a template would lie outside the page of the other paper, the change is refused and the answer names the templates.',
				'de_DE' => 'Der eigene Bildschirm des Panels: das Papier (A4 oder US Letter), die vier Ränder in Millimetern (5 bis 50), die Schrift (Helvetica, Times oder Courier) und die Grundgröße in Punkt (8 bis 14), Kopf- und Fußzeile als HTML - je höchstens 4000 Zeichen und auf jeder Seite 40 mm hoch - und zwei Bilder, das Logo und die Unterschrift des Inhabers, ein PNG oder ein JPEG bis 4000 mal 4000 Pixel, die sofort hineingelegt und herausgenommen werden und nicht zum Formular gehören. In Kopf- und Fußzeile sind [[page]] und [[pages]] die Seitenzahlen. Das Papier wird den Vorlagen nicht unter den Händen gewechselt: Liegt ein Unterschriftfeld einer Vorlage auf dem anderen Papier außerhalb der Seite, wird der Wechsel abgelehnt, und die Antwort nennt die Vorlagen.',
			],
			'Text blocks' => [
				'en_US' => 'The library of texts a template uses by reference: a title, a key and HTML each. A block of the library is read when a document is rendered, so a changed block changes every template that uses it. The HTML is cleaned when it is saved, by a list of the feature\'s own - headings, paragraphs, lists, tables, rules, strong, em, links and the logo stay; any other tag is unwrapped to its text, a script or a style is dropped, and of the attributes only those the layout reads are kept - and the same list holds the header, the footer, a text of a template\'s own and a slot a caller hands in. A block a template uses cannot be deleted; the answer names the templates.',
				'de_DE' => 'Die Bibliothek der Texte, die eine Vorlage als Verweis nutzt: je ein Titel, ein Schlüssel und HTML. Ein Baustein der Bibliothek wird beim Erzeugen eines Dokuments gelesen, ein geänderter Baustein ändert also jede Vorlage, die ihn nutzt. Das HTML wird beim Speichern bereinigt, nach einer Liste des Features selbst - Überschriften, Absätze, Listen, Tabellen, Linien, strong, em, Links und das Logo bleiben; jedes andere Tag wird zu seinem Text aufgelöst, ein script oder style fällt weg, und von den Attributen bleiben nur die, die das Layout liest -, und dieselbe Liste hält Kopf- und Fußzeile, den eigenen Text einer Vorlage und einen Platzhalter, den ein Aufrufer mitgibt. Ein Baustein, den eine Vorlage nutzt, lässt sich nicht löschen; die Antwort nennt die Vorlagen.',
			],
			'Templates' => [
				'en_US' => 'A list - name, tag, number of blocks - with New, Duplicate and Delete, and the editor of one template: a name, a key, a tag of the project\'s list and at most 60 blocks in their order, moved with the arrows. A block is html (a block of the library, or a text of its own), a slot (the HTML a caller hands in), an input (a free text a caller asks a person for, shown where the block stands), a signature field (a party, a label, a page - a number or the last - and a box in millimetres from the top-left corner of the paper, inside the page, with an optional line) or a page break. The tokens [[name]] and [[a.b]] are what the caller hands in; a text of the site is its key, slash first, between the same brackets.',
				'de_DE' => 'Eine Liste - Name, Tag, Zahl der Blöcke - mit Neu, Duplizieren und Löschen und der Editor einer Vorlage: ein Name, ein Schlüssel, ein Tag aus der Liste des Projekts und höchstens 60 Blöcke in ihrer Reihenfolge, mit den Pfeilen verschoben. Ein Block ist Html (ein Baustein der Bibliothek oder ein eigener Text), ein Platzhalter (das HTML, das ein Aufrufer mitgibt), ein Eingabefeld (ein Freitext, nach dem ein Aufrufer eine Person fragt, dort ausgegeben, wo der Block steht), ein Unterschriftfeld (eine Partei, eine Beschriftung, eine Seite - eine Nummer oder die letzte - und ein Kasten in Millimetern von der linken oberen Ecke des Papiers, innerhalb der Seite, mit einer Linie nach Wahl) oder ein Seitenumbruch. Die Tokens [[name]] und [[a.b]] sind das, was der Aufrufer mitgibt; ein Text der Website ist sein Schlüssel, Schrägstrich zuerst, in denselben Klammern.',
			],
			'Preview' => [
				'en_US' => 'The button Preview in the editor renders what the editor holds, saved or not, with sample data - [name] for a token, the label in square brackets for an input, a small table for a slot, and some text for a template with no body - and shows it in a frame with a link to download it. Nothing is stored.',
				'de_DE' => 'Der Knopf Vorschau im Editor erzeugt, was der Editor hält, gespeichert oder nicht, mit Beispieldaten - [Name] für ein Token, die Beschriftung in eckigen Klammern für ein Eingabefeld, eine kleine Tabelle für einen Platzhalter und etwas Text für eine Vorlage ohne Inhalt - und zeigt es in einem Rahmen mit einem Link zum Herunterladen. Gespeichert wird nichts.',
			],
		],
		'callbacks' => [
			'/nino/http/output' => [
				'en_US' => 'Lets the responses of the workbench frame a blob: address, by one source added to the frame-src of their Content-Security-Policy, so that the preview shows in a frame. For /_admin only; a page of the site keeps its policy as it is.',
				'de_DE' => 'Lässt die Antworten der Workbench eine blob:-Adresse einrahmen: Der frame-src ihrer Content-Security-Policy wird um genau diese eine Quelle ergänzt, damit die Vorschau in einem Rahmen erscheint. Nur für /_admin; eine Seite der Website behält ihre Policy, wie sie ist.',
			],
		],
		'install' => [],
	],
	'category'		=> 'content',
	'version'			=> '1.1.0',
	'nino'				=> '^1.8',
	// gd decodes a picture that is no JPEG, dom reads the HTML of a document (and holds it to the list of what the
	// layout understands), zlib compresses every stream of the file, iconv writes the text in the code page of the
	// fonts; gd is named although the kernel names it too, so that a reader sees what the writer needs, and mbstring,
	// which the kernel names, is not named again
	'php'					=> [ 'ext' => [ 'gd', 'dom', 'iconv', 'zlib' ] ],
	'requires'		=> [],
	// The layout, the blocks, the templates and the two pictures, all below the directory
	'data'				=> [ '/data/pdf' ],
	'assets'			=> [],
	'components'	=> [],
	'stacks'			=> [],
	'settings'		=> [
		'tags' => [
			'type'		=> 'lines',
			'label'		=> [ 'en_US' => 'Tags of the templates', 'de_DE' => 'Tags der Vorlagen' ],
			'hint'		=> [
				'en_US' => 'One tag per line - lower case letters, digits and hyphens, 2 to 40 characters, starting with a letter. The words a caller asks the templates by: a select of every template tagged invoice. A template keeps the tag it was saved with; one that is no longer on this list is marked in the list of templates and gets another tag before it can be saved again.',
				'de_DE' => 'Ein Tag pro Zeile - Kleinbuchstaben, Ziffern und Bindestriche, 2 bis 40 Zeichen, mit einem Buchstaben am Anfang. Die Wörter, nach denen ein Aufrufer die Vorlagen fragt: eine Auswahl aller Vorlagen mit dem Tag invoice. Eine Vorlage behält das Tag, mit dem sie gespeichert wurde; eine, die nicht mehr auf dieser Liste steht, ist in der Liste der Vorlagen markiert und bekommt ein anderes Tag, bevor sie wieder gespeichert werden kann.',
			],
			'default'	=> [ 'offer', 'invoice', 'contract', 'privacy', 'information' ],
		],
	],
];
