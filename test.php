<?php

require_once('vendor/autoload.php');

use SimpleSAML\XHTML\Template;
//use SimpleSAML\Locale\Translate;

$config = \SimpleSAML\Configuration::getInstance();

$t = new Template($config, 'cmdotcom:message.twig');
$msg = $t->getTwig()->render('cmdotcom:message.twig');
//$msg = Translate::translateSingularGettext('{code}\nEnter this verification code when asked during the authentication process.');
//str_replace('\n', chr(10), $msg);

//echo $msg . PHP_EOL;
