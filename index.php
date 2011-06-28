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

$examplePath1 = 'http://wwwimages.adobe.com/www.adobe.com/content/dam/Adobe/en/devnet/pdf/pdfs/PDF32000_2008.pdf';

$reader = new jw_pdfReader($examplePath1);
?>
