<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="color-scheme" content="light dark">
	<title>[[t.login.title]] · [[t.page.title]]</title>
	<link rel="icon" href="data:,">
	<link rel="stylesheet" href="[[css]]">
	<script src="[[script]]" defer></script>
</head>
<body>
	<main class="blank">
		<div class="card pad login">
			<div class="brand"><b>[[brand]]</b><span>[[tag]]</span></div>
			<h1>[[t.login.title]]</h1>
			<form id="login" method="post" action="#" novalidate>
				<label class="field"><span>[[t.login.user]]</span><input type="email" id="login-user" name="user" autocomplete="username" required autofocus></label>
				<label class="field"><span>[[t.login.pw]]</span><input type="password" id="login-pw" name="pw" autocomplete="current-password" required></label>
				<p class="state-note" id="login-msg" role="status" aria-live="polite"></p>
				<div class="actions">
					<button type="submit" class="btn primary" id="login-submit">[[t.login.submit]]</button>
					<a class="btn" href="[[site]]">[[t.rail.site]]</a>
				</div>
			</form>
		</div>
	</main>
	<script type="application/json" id="crm-data">[[data]]</script>
</body>
</html>
