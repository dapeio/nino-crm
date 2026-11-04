<!-- nino:template-name Portal dates -->
[template /templates/html-header]
<div class="nino-wrap">

<section id="intro" class="nino-section">
	<div class="nino-grid-row">
		<div class="nino-grid-100">
			[title /template/page-portal-termine/intro/title tag="h2"]
			[text /template/page-portal-termine/intro/text]
		</div>
	</div>
</section>

<section id="dates" class="nino-section">
	<div class="nino-grid-row">
		<div class="nino-grid-100">
			[events /events]
				[title title tag="h3"]
				[event-when start end="end"]
				[text place]
				[event-ics .slug]
			[/events]
		</div>
	</div>
</section>

<section id="feed" class="nino-section">
	<div class="nino-grid-row">
		<div class="nino-grid-100">
			[event-feed]
		</div>
	</div>
</section>

</div>
[template /templates/html-footer]
