<?php
/**
 * RRDGraph Plugin: Static class for mapping file extensions to content type
*
* @author Daniel Goß <developer@flashsystems.de>
* @license MIT
*/
class ContentType
{
    /**
     * Contains an associative array mapping file extensions (key) to content type strings (value).
     * @var Array
     */
    const contentTypes = array(
      "png" => "image/png",
      "svg" => "image/svg+xml"      
    );
    
    /**
     * Returns the content type to use for transmitting the given file name. The content type is
     * solely detected by the file extension.
     * @param String $fileName The file name to detect the content type for.
     * @return String The content type to use or null if the given file extension is not in the list of known file extensions.
     */
    public static function get_content_type($fileName) {
        $extension = strtolower(ltrim(strrchr($fileName, '.'), '.'));
        
        if (array_key_exists($extension, self::contentTypes)) {
            return self::contentTypes[$extension];
        } else {
            return null;
        }
    }
}