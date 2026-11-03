<?php
declare(strict_types=1);

/**
 *	harness.stub.php		The signatures of the helpers the kernel's tests/harness.php defines, for PHPStan alone: the
 *											kernel's phpstan.neon analyses app/ - this suite included - and not tests/, where the harness is.
 *											Nothing requires this file; the suite requires the harness itself.
 */

function check( string $label, bool $condition ): void {
}

function ninoSandbox( string $name ): array {
	return [];
}

function ninoSandboxDir( array &$appData ): string {
	return '';
}

function ninoWarnings(): array {
	return [];
}

function ninoCall( array &$appData, callable $target, array $data = [], ?string $action = null, int $statusBefore = 200 ): array {
	return [];
}

function ninoUser( array &$appData, string $mail, array $perms = [ '/*' ], string $password = 'correct horse battery staple' ): void {
}

function ninoVisit( array &$appData, string $uri, string $method = 'GET', array $server = [] ): array {
	return [];
}

function ninoDone( array &$appData ): never {
	throw new \LogicException( 'never run' );
}
