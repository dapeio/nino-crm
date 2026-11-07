<form class="crm-pay-notice crm-card crm-card--nested" method="post" action="[[dir]]/.crm/bezahlt" data-document="[[id]]">
				[csrf]
				<input type="hidden" name="document" value="[[id]]">
				<p class="crm-pay-notice__hint">[[/project/crm-portal/pay/notice-hint]]</p>
				<p class="crm-order__actions"><button type="submit" class="crm-btn crm-btn--secondary">[[icon:banknote]]<span>[[/project/crm-portal/pay/notice-button]]</span></button></p>
			</form>
