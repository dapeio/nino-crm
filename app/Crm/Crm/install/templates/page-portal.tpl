<!-- nino:template-name Portal -->
[template /templates/html-header]
<div class="nino-wrap">

<section id="intro" class="nino-section">
	<div class="nino-grid-row">
		<div class="nino-grid-100">
			[crm-portal-nav]
			[title /template/page-portal/intro/title tag="h2"]
			[text /template/page-portal/intro/text]
			[members-name] [crm-client]
			[crm-projects open="1" short="1"]
			[button /template/page-portal/link-projekte/label href="/template/page-portal/link-projekte/href" style="primary"]
			[button /template/page-portal/link-upload/label href="/template/page-portal/link-upload/href" style="primary"]
			[button /template/page-portal/link-belege/label href="/template/page-portal/link-belege/href" style="primary"]
			[button /template/page-portal/link-termine/label href="/template/page-portal/link-termine/href" style="primary"]
			[button /template/page-portal/link-meine-daten/label href="/template/page-portal/link-meine-daten/href" style="outline"]
		</div>
	</div>
</section>

</div>
[template /templates/html-footer]
