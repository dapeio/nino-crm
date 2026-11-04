<?php
declare(strict_types=1);

// The panel CRM's own words, one file per interface language (see Admin::text()): merged into the workbench's fills while the
// module is active, never into the project's text files. The surface at /crm reads the sentences of the status and of the setup
// from here as well (Surface::texts()). The words of the portal pages are the other set, in unit/text/
return [

	'[[/_admin/crm/nav]]' => 'CRM',
	'[[/_admin/crm/label/open]]' => 'CRM öffnen',
	'[[/_admin/crm/label/setup]]' => 'Portal einrichten',
	'[[/_admin/crm/error/load]]' => 'Der Status des Moduls konnte nicht gelesen werden.',
	'[[/_admin/crm/error/status]]' => 'Der Status des Moduls konnte nicht gelesen werden.',
	'[[/_admin/crm/error/setup]]' => 'Das Portal konnte nicht eingerichtet werden.',
	'[[/_admin/crm/manual/intro]]' => 'Das CRM arbeitet in einer eigenen Oberfläche unter /crm: Kunden, Belege, Positionen und Einstellungen. Hier in der Workbench stehen nur der Weg dorthin, der Status des Moduls und der Knopf für das Portal.',

	'[[/_admin/crm/setup/done]]' => 'Eingerichtet. Neu: Routen %s, Vorlagen %s, Elementtypen %s, Worte %s. Schon da: %s.',
	'[[/_admin/crm/status/allok]]' => 'Alles ist bereit: Das Portal ist eingerichtet und die Features laufen.',
	'[[/_admin/crm/status/module-off]]' => 'Das Modul steht nicht in /nino/modules. Trage \\Crm\\Crm in private/config.php ein.',
	'[[/_admin/crm/status/data-unwritable]]' => 'Das Verzeichnis data/ ist nicht beschreibbar, die Datenbank kann nicht angelegt werden.',
	'[[/_admin/crm/status/portal-none]]' => 'Das Portal ist noch nicht eingerichtet. Drück auf Portal einrichten.',
	'[[/_admin/crm/status/lacks-routes]]' => 'Routen',
	'[[/_admin/crm/status/lacks-templates]]' => 'Vorlagen',
	'[[/_admin/crm/status/lacks-types]]' => 'der Elementtyp Positionen',
	'[[/_admin/crm/status/lacks-paths]]' => 'der geschützte Pfad /portal',
	'[[/_admin/crm/status/portal-partial]]' => 'Das Portal ist nur zum Teil eingerichtet, es fehlt: %s. Drück auf Portal einrichten, es ergänzt, was fehlt.',
	'[[/_admin/crm/status/members-missing]]' => 'Das Feature Mitglieder ist nicht installiert.',
	'[[/_admin/crm/status/members-off]]' => 'Das Feature Mitglieder ist installiert, aber nicht aktiv. Schalte es im Panel Features ein.',
	'[[/_admin/crm/status/members-problem]]' => 'Das Feature Mitglieder hat ein Problem, siehe das Panel Features.',
	'[[/_admin/crm/status/files-missing]]' => 'Das Feature Dateien ist nicht installiert.',
	'[[/_admin/crm/status/files-off]]' => 'Das Feature Dateien ist installiert, aber nicht aktiv. Schalte es im Panel Features ein.',
	'[[/_admin/crm/status/files-problem]]' => 'Das Feature Dateien hat ein Problem, siehe das Panel Features.',
	'[[/_admin/crm/status/review-missing]]' => 'Das Feature Review ist nicht installiert.',
	'[[/_admin/crm/status/review-off]]' => 'Das Feature Review ist installiert, aber nicht aktiv. Schalte es im Panel Features ein.',
	'[[/_admin/crm/status/review-problem]]' => 'Das Feature Review hat ein Problem, siehe das Panel Features.',
	'[[/_admin/crm/status/sign-missing]]' => 'Das Feature Unterschrift ist nicht installiert.',
	'[[/_admin/crm/status/sign-off]]' => 'Das Feature Unterschrift ist installiert, aber nicht aktiv. Schalte es im Panel Features ein.',
	'[[/_admin/crm/status/sign-problem]]' => 'Das Feature Unterschrift hat ein Problem, siehe das Panel Features.',
	'[[/_admin/crm/status/pdf-missing]]' => 'Das Feature PDF ist nicht installiert.',
	'[[/_admin/crm/status/pdf-off]]' => 'Das Feature PDF ist installiert, aber nicht aktiv. Schalte es im Panel Features ein.',
	'[[/_admin/crm/status/pdf-problem]]' => 'Das Feature PDF hat ein Problem, siehe das Panel Features.',

	'[[/_admin/error/crm_setup_members]]' => 'Das Feature Mitglieder ist nicht aktiv. Schalte es im Panel Features ein.',
];
