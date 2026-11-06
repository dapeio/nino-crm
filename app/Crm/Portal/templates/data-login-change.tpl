<details class="crm-login__fold crm-fold"[[open]]>
			<summary class="crm-login__open crm-fold__summary crm-btn crm-btn--secondary crm-btn--small"><span>[[/project/crm-portal/data/login-change]]</span>[[icon:chevron-down]]</summary>
			<form class="crm-login__form crm-fold__body" method="post" action="[[dir]]/.crm/anmeldeadresse">
				[csrf]
				<p class="crm-field[[invalid]]">
					<label class="crm-field__label" for="crm-login-email">[[/project/crm-portal/data/login-new]]</label>
					<input class="crm-data__input crm-field__input nino-form-input" type="email" id="crm-login-email" name="email" maxlength="254" autocomplete="email" required aria-describedby="crm-login-hint"[[described]]>
					<span class="crm-field__hint" id="crm-login-hint">[[/project/crm-portal/data/login-hint]]</span>
				</p>
				<p class="crm-data__actions"><button type="submit" class="crm-btn crm-btn--primary">[[icon:mail]]<span>[[/project/crm-portal/data/login-request]]</span></button></p>
			</form>
		</details>
