<?php
use PHPUnit\Framework\TestCase;
use RequestInspector\Policy;
final class PolicyTest extends TestCase {
    public function testIpv4AndIpv6():void {
        $this->assertTrue(Policy::contains('192.0.2.10','192.0.2.0/24'));
        $this->assertFalse(Policy::contains('192.0.3.10','192.0.2.0/24'));
        $this->assertTrue(Policy::contains('2001:db8::123','2001:db8::/32'));
        $this->assertFalse(Policy::contains('2001:db9::123','2001:db8::/32'));
        $this->assertFalse(Policy::contains('192.0.2.1','192.0.2.0/33'));
        $this->assertFalse(Policy::contains('invalid','192.0.2.0/24'));
    }
    public function testUntrustedForwardingIsIgnored():void {
        $this->assertSame('203.0.113.1',Policy::client('203.0.113.1','192.0.2.1',['10.0.0.0/8']));
        $this->assertSame('192.0.2.1',Policy::client('10.0.0.1','198.51.100.1,192.0.2.1',['10.0.0.0/8']));
        $this->assertSame('',Policy::client('10.0.0.1','invalid',['10.0.0.0/8']));
    }
    public function testPatternGrammarRejectsExpensiveExpressions():void {
        $this->assertTrue(Policy::pattern('INV-[0-9]{5}'));
        foreach(['(a+)+','a{0,}','a{1,999}','(?R)','a|b','.*','[broken'] as $pattern) $this->assertFalse(Policy::pattern($pattern),$pattern);
    }
}
