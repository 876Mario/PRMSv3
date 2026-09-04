<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/helper.php';

final class PasswordPolicyHelperTest extends PHPUnit\Framework\TestCase
{
    public function testRejectsTooShortPassword(): void
    {
        $this->assertSame(
            'Password must be at least 8 characters long.',
            validatePasswordPolicy('Abc123')
        );
    }

    public function testRejectsPasswordWithoutUppercase(): void
    {
        $this->assertSame(
            'Password must contain at least one uppercase letter.',
            validatePasswordPolicy('lowercase1')
        );
    }

    public function testRejectsPasswordWithoutNumber(): void
    {
        $this->assertSame(
            'Password must contain at least one number.',
            validatePasswordPolicy('Lowercase')
        );
    }

    public function testAcceptsPasswordThatMeetsPolicy(): void
    {
        $this->assertNull(validatePasswordPolicy('ValidPass1'));
    }
}
