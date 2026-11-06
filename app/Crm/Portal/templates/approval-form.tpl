<form class="crm-approval__form crm-card crm-card--nested" method="post" action="[[dir]]/.crm/abnahme" data-project="[[id]]">
			[csrf]
			<input type="hidden" name="project" value="[[id]]">
			<input type="hidden" name="shown" value="[[hash]]">
			[[news]]
			<p class="crm-approval__field"><label class="crm-approval__check crm-check" for="crm-approval-[[id]]"><input type="checkbox" id="crm-approval-[[id]]" name="agree" value="1" required><span class="crm-approval__sentence">[[sentence]]</span></label></p>
			<p class="crm-approval__actions"><button type="submit" class="crm-btn crm-btn--primary">[[icon:check]]<span>[[/project/crm-portal/approval/button]]</span></button></p>
		</form>
