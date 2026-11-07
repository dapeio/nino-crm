<details class="crm-decline crm-fold">
				<summary class="crm-decline__summary crm-fold__summary crm-btn crm-btn--ghost">[[icon:x]]<span>[[/project/crm-portal/order/decline-fold]]</span>[[icon:chevron-down]]</summary>
				<form class="crm-decline__form crm-card crm-card--nested" method="post" action="[[dir]]/.crm/ablehnen" data-document="[[id]]">
					[csrf]
					<input type="hidden" name="document" value="[[id]]">
					<input type="hidden" name="shown" value="[[hash]]">
					<input type="hidden" name="from" value="[[from]]">
					<p class="crm-field crm-decline__field"><label class="crm-field__label" for="crm-decline-[[from]]-[[id]]">[[/project/crm-portal/order/decline-reason]]</label><textarea class="crm-decline__input crm-field__input nino-form-input" id="crm-decline-[[from]]-[[id]]" name="reason" rows="3" maxlength="[[max]]"></textarea></p>
					<p class="crm-order__actions"><button type="submit" class="crm-btn crm-btn--secondary">[[icon:x]]<span>[[/project/crm-portal/order/decline-button]]</span></button></p>
				</form>
			</details>
