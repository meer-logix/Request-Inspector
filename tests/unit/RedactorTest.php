<?php
use PHPUnit\Framework\TestCase;
use RequestInspector\Redactor;
final class RedactorTest extends TestCase {
 public function testAggregateTextBudget():void {$safe=(new Redactor())->value(array_fill(0,100,str_repeat('a',65536)));$this->assertLessThan(300000,strlen(json_encode($safe)));}
 public function testUnsupportedBodyTypes():void {$body=(new Redactor())->body(new stdClass(),['application/json'],true);$this->assertNull($body['content']);$this->assertSame('unsupported_body_type',$body['reason']);}
 public function testNestedCredentialsAreRemoved():void { $r=new Redactor(['private_key']);$safe=$r->value(['nested'=>['ACCESS_TOKEN'=>'sentinel','Password'=>'sentinel','private_key'=>'sentinel'],'ok'=>42]);$this->assertStringNotContainsString('sentinel',json_encode($safe));$this->assertSame(42,$safe['ok']); }
 public function testHeaderAllowlist():void { $safe=(new Redactor())->headers(['Authorization'=>'sentinel','X-Mystery'=>'sentinel','Content-Type'=>'application/json','Cookie'=>'sentinel']);$this->assertSame('application/json',$safe['content-type']);$this->assertStringNotContainsString('sentinel',json_encode($safe)); }
 public function testUrlCredentialsAndFragments():void {$safe=(new Redactor())->url('https://person:sentinel@example.test/path?token=sentinel&ok=42#sentinel');$this->assertStringNotContainsString('sentinel',$safe);$this->assertStringContainsString('ok=42',$safe);}
 public function testMalformedBodyIsOmitted():void {$b=(new Redactor())->body('{"token":"sentinel','application/json',true);$this->assertNull($b['content']);$this->assertSame('malformed_json',$b['reason']);}
 public function testLargeBodyIsOmittedBeforeParsing():void {$b=(new Redactor())->body(str_repeat('a',1048577),'application/json',true);$this->assertNull($b['content']);$this->assertSame('parse_limit',$b['reason']);}
 public function testBinaryAndTextAreOmitted():void {foreach(['image/png','text/html','text/plain'] as $mime){$b=(new Redactor())->body('sentinel',$mime,true);$this->assertNull($b['content']);}}
 public function testTruncationOnlyAfterRedaction():void {$b=(new Redactor())->body(['token'=>'sentinel','value'=>str_repeat('a',200)],'application/json',true,60);$this->assertTrue($b['is_truncated']);$this->assertLessThanOrEqual(60,$b['stored_size']);$this->assertStringNotContainsString('sentinel',$b['content']);}
 public function testDisabledBodiesRemainDisabled():void {$b=(new Redactor())->body(['token'=>'sentinel'],'application/json',false);$this->assertNull($b['content']);$this->assertSame('disabled',$b['reason']);}
 public function testSqlLiteralsAndComments():void {$sql=(new Redactor())->sql("SELECT * FROM wp_options WHERE option_value='sentinel' AND id=123 /* sentinel */");$this->assertStringNotContainsString('sentinel',$sql);$this->assertStringNotContainsString('123',$sql);$this->assertStringContainsString('wp_options',$sql);}
 public function testMalformedSqlIsOmitted():void {$this->assertStringContainsString('OMITTED',(new Redactor())->sql("SELECT 'sentinel"));}
 public function testNodeBudgetBoundsWideArrays():void {$safe=(new Redactor())->value(array_fill(0,5000,'a'));$this->assertLessThanOrEqual(2001,count($safe));}
 public function testDeepObjectsAreNotSerialized():void {$safe=(new Redactor())->value((object)['secret'=>'sentinel']);$this->assertStringNotContainsString('sentinel',$safe);}
}
