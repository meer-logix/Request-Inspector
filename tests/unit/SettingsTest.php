<?php
use PHPUnit\Framework\TestCase;
use RequestInspector\Settings;
final class SettingsTest extends TestCase {
 protected function setUp():void {$GLOBALS['ri_options']=[];}
 public function testSecureDefaults():void {$s=Settings::defaults();$this->assertFalse($s['enabled']);$this->assertFalse($s['request_body']);$this->assertFalse($s['response_body']);$this->assertFalse($s['database']);}
 public function testRequiresRevision():void {$this->assertInstanceOf(WP_Error::class,Settings::validate(['enabled'=>true]));}
 public function testRejectsStaleRevision():void {$this->assertInstanceOf(WP_Error::class,Settings::validate(['revision'=>0]));}
 public function testValidUpdate():void {$s=Settings::validate(['revision'=>1,'enabled'=>true]);$this->assertSame(2,$s['revision']);$this->assertTrue($s['enabled']);}
 public function testRejectsUnsafeRules():void {$this->assertInstanceOf(WP_Error::class,Settings::validate(['revision'=>1,'custom_keys'=>['/(a+)+$/']]));}
 public function testRejectsCapabilityEscalation():void {$this->assertInstanceOf(WP_Error::class,Settings::validate(['revision'=>1,'view_capability'=>'read']));}
 public function testRejectsUnboundedSettings():void {$this->assertInstanceOf(WP_Error::class,Settings::validate(['revision'=>1,'max_body_kb'=>999999]));}
 public function testRejectsStringBoolean():void {$this->assertInstanceOf(WP_Error::class,Settings::validate(['revision'=>1,'enabled'=>'true']));}
}
