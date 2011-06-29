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
//            $this->parseObject($this->xrefEntries[7]['offset']);

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
            $this->parseObject($this->offsetXRef);
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
        $firstLine = fread($this->fileHandle, 300);
        preg_match('#/Type/([[:alpha:]]+)#', $firstLine, $matches);
        if (!@$matches[1]) {
            $type = 'Unknown';
        } else {
            $type = $matches[1];
        }
        switch ($type) {
            case 'XRef':
                /**
                 * This is a cross-reference stream
                 */
                /**
                 * Is it a filtered stream?
                 */
                $this->parseObjectXRefSream($offset, $firstLine);
                break;
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
                        $buffer = $this->getObjectContentStream($offset);
                        $options = $this->getFlateDecodeOptions($firstLine);
                        $buffer = $this->filterFlateDecode($buffer, $options);
                        break;
                    default:
                        /**
                         * @todo Add support for other filters
                         */
                        echo 'Filter: ' . $filter . "<br>\n";
                        echo htmlspecialchars($firstLine);
                        throw new Exception('Filter ' . $filter . ' is not supported at this time.');
                        break;
                }
                break;
            default:
                /**
                 * We have no clue what this is
                 */
                throw new Exception('Unable to read object type on stream at offset ' . $offset);
                break;
        }
    }

    function parseObjectXRefSream($offset, $dictionary) {
        $filter = '';
        preg_match('#/Filter/([[:alpha:][:digit:]]+)#', $dictionary, $matches);
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
                $objId = 0;
                $objType = '';
                $objOffset = '';
                $objGen = '';
                $w = array();
                $options = array();
                $tmpObject = array();
                /**
                 * Filter: FlateDecode
                 */
                $buffer = $this->getObjectContentStream($offset);
                $options = $this->getFlateDecodeOptions($dictionary);
                $buffer = $this->filterFlateDecode($buffer, $options);
                $rowLength = array_sum($options['w']) + 1;
                $rowCount = strlen($buffer) / $rowLength;

                $w = $options['w'];

                for ($i = 0; $i < $rowCount; $i++) {
                    $row = substr($buffer, 0, $rowLength);
                    /**
                     * Trim the first byte off
                     */
                    $row = substr($row, 1);
                    $objType = hexdec(bin2hex(substr($row, 0, $w[0])));
                    $objOffset = hexdec(bin2hex(substr($row, $w[0], $w[1])));
                    $objGen = hexdec(bin2hex(substr($row, $w[0] + $w[1])));
                    switch ($objType) {
                        case 0:
                            $objId = 'NULL';
                        case 1:
                            $objId = $this->getObjectIdByOffset($objOffset);
                            break;
                        case 2:
                            $results = array();
                            $this->loopAndFind($this->pdfObjects, 'id', $objOffset, $results);
//                            echo 'Results.';
//                            $this->print_r_pre($results);
                            $objId = $results[0]['offset'];
                            break;
                    }
                    $tmpObject = array(
                        'id' => $objId,
                        'type' => $objType,
                        'offset' => $objOffset,
                        'gen' => $objGen,
                    );
                    $this->pdfObjects[] = $tmpObject;
                    $buffer = substr($buffer, $rowLength);
                }
                $this->print_r_pre($this->pdfObjects);
                break;
            default:
                /**
                 * @todo Add support for other filters
                 */
                echo 'Filter: ' . $filter . "<br>\n";
                echo htmlspecialchars($dictionary);
                throw new Exception('Filter ' . $filter . ' is not supported at this time.');
                break;
        }
    }

    /**
     * Takes an object offset and return the id number
     * @param int $offset
     * @return int 
     */
    function getObjectIdByOffset($offset) {
        $objId = 0;
        $firstLine = '';
        /**
         * Start at offset
         */
        fseek($this->fileHandle, $offset);
        /**
         * Find out what type of object this is
         */
        $firstLine = fread($this->fileHandle, 300);
        preg_match('/([[:digit:]]+) [[:digit:]]+ obj/', $firstLine, $matches);
        $objId = $matches[1];
        return $objId;
    }

    /**
     * Inflates the data and send it though a filter if needed.
     * @param string $buffer
     * @param array $options
     * @return string 
     */
    function filterFlateDecode($buffer, $options) {
        /**
         * Inflate the buffer
         * Since this is not actually gzipped we need to strip the first two bytes
         */
        $buffer = gzinflate(substr($buffer, 2));
        /**
         * Check if data was predicted
         */
        if ($options['predictor'] > 0) {
            switch ($options['predictor']) {
                case 12:
                    /**
                     * PNG UP was used
                     */
                    $buffer = $this->filterUp($buffer, $options['w']);
                    break;

                default:
                    /**
                     * @todo Add support for the other predictors
                     */
                    throw new Exception('Predictor number ' . $options['predictor'] . ' is not yet supported.');
                    break;
            }
        }
        return $buffer;
    }

    /**
     * Preforms PNG UP de-prediction on buffer
     * @param string $buffer
     * @param array $w
     * @return string 
     */
    function filterUp($buffer, $w) {
        $decodedBuffer = '';
        $rowLength = array_sum($w) + 1;
        $rowCount = strlen($buffer) / $rowLength;
        $upRow = array_fill(0, $rowLength, 0);
        $tmpChr = '';
        /**
         * Loop though the rows
         */
        for ($i = 0; $i < $rowCount; $i++) {
            $curRow = substr($buffer, 0, $rowLength);

            $curRow = str_split($curRow);
            $decodedRow = array();
            /**
             * Loop though the columns of the row
             */
            for ($j = 0; $j < $rowLength; $j++) {
                if ($j == 0) {
                    // Skip the first character, it is the predictor encoding of 2
                    $tmpChr = ord($curRow[$j]);
                } else {
                    /**
                     * Convert both curRow and upRow to dec, add them together, then convert back to bin
                     */
                    $tmpChr = ord($curRow[$j]) + $upRow[$j] & 0xFF;
                }
                $decodedRow[$j] = chr($tmpChr);
                $upRow[$j] = ord($decodedRow[$j]);
            }
            $decodedRow = implode('', $decodedRow);
            $decodedBuffer .= $decodedRow;
            /**
             * Trim the buffer to the next row
             */
            $buffer = substr($buffer, $rowLength);
        }
        return $decodedBuffer;
    }

    /**
     * Extracts the FlateDecode options from the dictionary
     * @param string $dictionaryLine
     * @return string
     */
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
        $w = array_fill(0, 3, 0);
        preg_match('#/W\[([[:digit:]]) ([[:digit:]]) ([[:digit:]])\]#', $dictionaryLine, $matches);
        if (!@$matches[1]) {
            // No Columns value, leave at default
        } else {
            $w = array(
                $matches[1],
                $matches[2],
                $matches[3],
            );
        }
        $options = array(
            'predictor' => $predictor,
            'w' => $w,
        );
        return $options;
    }

    /**
     * Takes the object at offset and returns the content stream
     * @param int $offset
     * @return string
     */
    function getObjectContentStream($offset) {
        $buffer = '';
        $firstLine = '';
        $end = 0;
        fseek($this->fileHandle, $offset);
        $buffer = fgets($this->fileHandle);
        while (!strpos($buffer, 'endstream')) {
            $buffer .= fgets($this->fileHandle);
        }
        /**
         * Check the Length value
         */
        $length = 0;
        preg_match('#/Length ([[:digit:]]+)#', $buffer, $matches);
        if (!@$matches[1]) {
            // No length value, this is bad
            throw new Exception('Unable to read the length value of the object at offset ' . $offset);
        } else {
            $length = $matches[1];
        }
        /**
         * Cut off the stream and endstream keywords
         */
        $buffer = substr($buffer, strpos($buffer, 'stream') + 6);
        $end = strpos($buffer, 'endstream');
        $buffer = trim(substr($buffer, 0, $end));
        /**
         * Compare the length of buffer against the length value
         */
        if ($length <> strlen($buffer)) {
            throw new Exception('The length of the stream at offset ' . $offset . ' does not match it\s length value.');
        }

        return $buffer;
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

    function strToDec($string, $spacer = '') {
        $hex = '';
        for ($i = 0; $i < strlen($string); $i++) {
            $hex .= ord($string[$i]) . $spacer;
        }
        return $hex;
    }

    /**
     * Searches an array for makthing keys and return an array of those records
     * 
     * @param array $array
     * @param string $index
     * @param string $search
     * @param array $results
     * @return bool 
     */
    function loopAndFind($array, $index, $search, &$results) {
        $results = array();
        foreach ($array as $k => $v) {
            if (isset($v[$index])) {
                if ($v[$index] == $search) {
                    $results[] = $v;
                }
            }
        }
        if (count($results) > 0) {
            return true;
        } else {
            return false;
        }
    }

}

?>
