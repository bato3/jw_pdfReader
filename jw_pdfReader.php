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
     * Holds the file offset of the XRef information
     * @var int
     */
    var $offsetXRef = 0;

    /**
     * Holds the objects
     * @var array
     */
    var $pdfObjects = array();
    var $offsets;

    /**
     * Holds the XRef table entries
     * @var array
     */
    var $xrefEntries = array();

    function __construct($path = NULL) {
        if (!is_null($path)) {
            $this->_load($path);
            $this->getTrailer();
            $this->getXRefInfo();
//            $this->print_r_pre($this->xrefEntries);
            $this->parseObject($this->xrefEntries[7]['offset']);

            fclose($this->fileHandle);
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

            /**
             * Save to a tempory file so we don't hold the socket open
             */
            $this->fileHandle = tmpfile();
            fwrite($this->fileHandle, $this->fileStream);
            $this->fileStream = '';
            /**
             * Start at the beginning
             */
            rewind($this->fileHandle);
        } else {
            Throw new Exception($path . ' is not readable.');
        }
        return;
    }

    /**
     * Scans the end of the file for the startxref keyword and gets the 
     * offset of the xref information
     * 
     * @todo Check for the existance of the trailer keyword
     */
    function getTrailer() {
        $lines = array();
        fseek($this->fileHandle, -50, SEEK_END);
        while (!feof($this->fileHandle)) {
            $lines[] = fgets($this->fileHandle);
        }

        $this->offsetXRef = trim($lines[count($lines) - 2]);
        unset($lines);
    }

    /**
     * Scan the XRef offset and retrieve the data for either a table or a stream
     */
    function getXRefInfo() {
        $firstLine = '';
        fseek($this->fileHandle, $this->offsetXRef);
        $firstLine = trim(fgets($this->fileHandle));
        /**
         * Check if it is a Table or a Stream
         */
        if (strpos($firstLine, 'xref') !== false) {
            /**
             * This is an XRef Table
             */
            $xrefBuffer = '';
            $entryCount = 0;
            /**
             * Get the count of entries on the next line
             */
            preg_match('/[[:digit:]]+ ([[:digit:]]+)/', fgets($this->fileHandle), $matches);
            $entryCount = $matches[1];
            for ($i = 0; $i < $entryCount; $i++) {
                preg_match('/([[:digit:]]{10}) ([[:digit:]]{5}) ([nf])/', fgets($this->fileHandle), $matches);
                if (count($matches) < 4) {
                    throw new Exception('Unable to read XRef table entries.');
                }
                $this->xrefEntries[] = array(
                    'offset' => $matches[1],
                    'generation' => $matches[2],
                    'type' => $matches[3],
                );
            }
        } elseif (strpos($firstLine, 'obj') !== false) {
            /**
             * This is a XRef Stream
             * 
             * @todo Add ability to read XRef streams
             */
        } else {
            /**
             * This is not valid XRef information
             */
            echo htmlspecialchars($firstLine);
            throw new Exception('Unable to read XRef information.');
        }
    }

    function parseObject($offset) {
        $type = '';
        $firstLine = '';
        /**
         * Start at offset
         */
        fseek($this->fileHandle, $offset);
        /**
         * Find out what type of object this is
         */
        $firstLine = fread($this->fileHandle, 100);
        preg_match('#/Type/([[:alpha:]]+)#', $firstLine, $matches);
        if (!@$matches[1]) {
            $type = 'Unknown';
        } else {
            $type = $matches[1];
        }
        switch ($type) {
            case 'XObject':
                /**
                 * This is a graphic external object
                 * 
                 * @todo Add the ability to process eXternal Objects
                 */
                throw new Exception('We do not currently support the ' . $type . ' object type.');
                break;
            case 'Unknown':
                /**
                 * This object does not have a /Type entry
                 */
                /**
                 * Is it a filtered stream?
                 */
                $filter = '';
                preg_match('#/Filter/([[:alpha:][:digit:]]+)#', $firstLine, $matches);
                if (!@$matches[1]) {
                    $filter = 'None';
                } else {
                    $filter = $matches[1];
                }
                switch ($filter) {
                    case 'None':
                        /**
                         * Not filtered
                         */
                        break;
                    case 'FlateDecode':
                        $options = array();
                        /**
                         * Filter: FlateDecode
                         */
                        $options = $this->getFlateDecodeOptions($firstLine);
                        break;
                    default:
                        /**
                         * @todo Add support for other filters
                         */
                        throw new Exception('Filter ' . $filter . ' is not supported at this time.');
                        break;
                }
                echo 'Filter: ' . $filter . "<br>\n";
                echo htmlspecialchars($firstLine);
                break;
            default:
                /**
                 * We have no clue what this is
                 */
                throw new Exception('Unable to read object type on stream at offset ' . $offset);
                break;
        }
    }

    function getFlateDecodeOptions($dictionaryLine) {
        $options = array();
        /**
         * Check for a Predictor
         */
        $predictor = 0;
        preg_match('#/Predictor ([[:digit:]]+)#', $dictionaryLine, $matches);
        if (!@$matches[1]) {
            // No Predictor value, leave at default
        } else {
            $predictor = $matches[1];
        }
        $columns = array_fill(0, 3, 0);
        preg_match('#/Columns\[([[:digit:]]) ([[:digit:]]) ([[:digit:]])\]#', $dictionaryLine, $matches);
        if (!@$matches[1]) {
            // No Columns value, leave at default
        } else {
            $columns = array(
                $matches[1],
                $matches[2],
                $matches[3],
            );
        }
        $options = array(
            'predictor' => $predictor,
            'columns' => $columns,
        );
        return $options;
    }

    function getObjectContentStream($offset) {
        
    }

    /**
     * Encloses print_r inside <pre> tags
     * @param array $array 
     */
    function print_r_pre($array) {
        echo "<pre>\n";
        print_r($array);
        echo "</pre>\n";
    }

}

?>
