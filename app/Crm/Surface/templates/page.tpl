<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="color-scheme" content="light dark">
	<title>[[t.page.title]]</title>
	<link rel="icon" href="data:,">
	<link rel="stylesheet" href="[[css]]">
	<script src="[[money]]" defer></script>
	<script src="[[script]]" defer></script>
</head>
<body>
	<a class="skip" href="#main">[[t.page.skip]]</a>
	<div class="app">
		<aside class="rail">
			<div class="brand"><b>[[brand]]</b><span>[[tag]]</span></div>
			<nav class="nav" id="nav" aria-label="[[t.nav.label]]">
				<a href="#uebersicht" data-r="uebersicht">[[t.nav.overview]]</a>
				<a href="#belege" data-r="belege">[[t.nav.documents]] <span class="n" id="n-belege"></span></a>
				<a href="#kunden" data-r="kunden">[[t.nav.clients]] <span class="n" id="n-kunden"></span></a>
				<a href="#projekte" data-r="projekte">[[t.nav.projects]]</a>
				<a href="#termine" data-r="termine">[[t.nav.events]]</a>
				<a href="#uploads" data-r="uploads">[[t.nav.uploads]]</a>
				<a href="#positionen" data-r="positionen">[[t.nav.items]]</a>
				<a href="#pdf" data-r="pdf" id="nav-pdf" hidden>[[t.nav.pdf]]</a>
				<a href="#einstellungen" data-r="einstellungen">[[t.nav.settings]]</a>
			</nav>
			<div class="foot">
				<span class="who">[[user]]</span>
				<a href="[[site]]">[[t.rail.site]]</a>
				<a href="[[workbench]]">[[t.rail.workbench]]</a>
			</div>
		</aside>
		<main id="main" tabindex="-1"><noscript><p class="state-note">[[t.page.noscript]]</p></noscript></main>
	</div>
	<div class="toast" id="toast" role="status" aria-live="polite"></div>
	<div id="dialogs"></div>
	<script type="application/json" id="crm-data">[[data]]</script>
</body>
</html>
