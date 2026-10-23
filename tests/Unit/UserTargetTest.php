<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Target\NormalizesKey;
use Importright\Target\UserTarget;

final class UserTargetTest extends TestCase {

	public function test_email_keys_are_tracked_case_insensitively(): void {
		$target = new UserTarget();
		$this->assertInstanceOf( NormalizesKey::class, $target, 'Batch only normalizes keys for targets that opt in.' );
		$this->assertSame( $target->normalize_key( 'alice@example.com' ), $target->normalize_key( 'Alice@Example.COM' ) );
		$this->assertSame( 'alice@example.com', $target->normalize_key( 'ALICE@EXAMPLE.COM' ) );
	}

	public function test_other_keys_are_left_alone(): void {
		$target = new UserTarget();
		$this->assertSame( 'CUST-0042', $target->normalize_key( 'CUST-0042' ) );
		$this->assertSame( 'AliceSmith', $target->normalize_key( 'AliceSmith' ) );
	}
}
