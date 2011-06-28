<?php

/**
 * phpPdfReader
 */
function __autoload($class_name) {
    require_once $class_name . '.php';
}

/**
 * @todo Turn off before shipping
 */
error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

/**
 * This example uses XRef Streams and has no trailer
 * Version 1.7
 */
$examplePath1 = 'http://wwwimages.adobe.com/www.adobe.com/content/dam/Adobe/en/devnet/pdf/pdfs/PDF32000_2008.pdf';
/**
 * This example uses an XRef Table and has a trailer
 * Version 1.4
 */
$examplePath2 = 'http://jwebnet.net/phpPdfReader/examples/grid.pdf';

$reader = new jw_pdfReader($examplePath2);
?>
