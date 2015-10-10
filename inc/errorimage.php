<?php
/**
 * RRDGraph Plugin: Error image generator
 * 
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

if (! defined('DOKU_INC')) die();

/**
 * Static class to create an image containing an error message.
 */
class ErrorImage {
    /** Size of the title line. */
    const TITLE_FONT = 3;
    /** Size of the second text line. */    
    const FONT = 2;
    /** Spacing between line 1 and 2 in pixels. */
    const LINE_SPACING = 2;
    /** Width of the colored border around the message. */
    const BORDER_WIDTH = 2;
    /** Spacing between border and text. */
    const BORDER_SPACING = 10;

    /**
     * Create and output an image containing the given error message.
     * The output is directly sento to the webbrowser. It is equiped with cache inhibition headers.
     * The image size will be automatically determined in a way that all text is visible.
     * 
     * @param String $title Title of the error message. This is rendered with a bold typeface on the first row of the output image.
     * @param String $message Error message. This is rendered with a normal typeface on the second row of the output image.
     */
    public static function outputErrorImage($title, $message) {
        $messageWidth = imagefontwidth(self::FONT) * strlen($message) + self::BORDER_SPACING * 2 + self::BORDER_WIDTH * 2;
        $titleWidth = imagefontwidth(self::TITLE_FONT) * strlen($title) + self::BORDER_SPACING * 2 + self::BORDER_WIDTH * 2;
        
        $width = max($messageWidth, $titleWidth);
        $height = imagefontheight(self::TITLE_FONT) + imagefontheight(self::FONT) + self::BORDER_SPACING * 2 + self::BORDER_WIDTH * 2;
        
        $image = imagecreatetruecolor($width, $height);
        $cBackground = imagecolorallocate($image, 255, 255, 255);
        $cBorder = imagecolorallocate($image, 255, 0, 0);
        $cBlack = imagecolorallocate($image, 0, 0, 0);
        
        imagefill($image, 0, 0, $cBorder);
        imagefilledrectangle($image, self::BORDER_WIDTH, self::BORDER_WIDTH, $width - self::BORDER_WIDTH - 1, $height - self::BORDER_WIDTH - 1, $cBackground);
        
        $y = self::BORDER_WIDTH + self::BORDER_SPACING;
        imagestring($image, self::TITLE_FONT, self::BORDER_WIDTH + self::BORDER_SPACING, $y, $title, $cBlack);
        $y += imagefontheight(self::FONT) + self::LINE_SPACING;
        imagestring($image, self::FONT, self::BORDER_WIDTH + self::BORDER_SPACING, $y, $message, $cBlack);
        
        //-- Suppress caching
        header("Content-Type: image/png");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        
        //-- Output the image. Create a valid Content-Length-Header via output buffering.
        ob_start();
        imagepng($image);
        header("Content-Length: " . ob_get_length());
        ob_end_flush();
        
        imagedestroy($image);
    }
}