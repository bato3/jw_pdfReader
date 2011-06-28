<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of pdfObject
 *
 * @author Joseph
 */
class jw_pdfReader {

    /**
     * Single digit representing the highest supported pdf version
     * currently 7 (1.7)
     * @var int
     */
    protected $maxSupportedVersion = 7;

    /**
     * Holds the file stream data
     * @var string
     */
    protected $fileStream;

    /**
     * Holds the length of the file stream
     * @var int
     */
    protected $fileLength;

    /**
     * Holds the file handle to the cached file.
     * @var handle
     */
    protected $fileHandle;

    /**
     * Holds the offset pointer in the file stream
     * @var int
     */
    protected $filePointer;

    /**
     * Holds the pdf version number
     * @var string
     */
    var $pdfVersion;

    /**
     * Holds the file offset of the XRF information
     * @var int
     */
    protected $xrefOffset;

    /**
     * Holds the objects
     * @var array
     */
    var $pdfObjects = array();
    var $offsets;

    function __construct($path = NULL) {
        if (!is_null($path)) {
            $this->_load($path);

            fclose($this->fileHandle);
            displayMsg('NOTICE', 'End of Construct');
        }
    }

    /**
     * Loads the pdf file
     * @param string $path
     * @return null
     */
    protected function _load($path) {

        if (@fopen($path, "r") == true) {
            $this->fileStream = file_get_contents($path);

            /* remove newlines on both ends */
            $this->fileStream = trim($this->fileStream);
            $this->fileLength = strlen($this->fileStream);

            $this->fileHandle = tmpfile();
            fwrite($this->fileHandle, $this->fileStream);
            $this->fileStream = '';
            rewind($this->fileHandle);

            displayMsg('NOTICE', 'Loaded ' . $path . ' with ' . $this->fileLength . ' bytes.');
        } else {
            displayMsg('FATAL', $path . ' is not readable.');
        }
        return;
    }

}

?>
