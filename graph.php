<?php
/**
 * RRDGraph Plugin: Graph generator
 * 
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

if (! defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../../../') . '/');
define('DOKU_DISABLE_GZIP_OUTPUT', 1);

//-- Initialize DokuWiki's core
require_once (DOKU_INC . 'inc/init.php');
require_once ("inc/errorimage.php");
require_once ("inc/rpncomputer.php");

//-- Close the currently open session. We don't need it here.
session_write_close();

//-- User abort must be ignored because we're building new images for the cache. If the
//   user aborts this process, the cache may be currupted. 
@ignore_user_abort(true);

try {
    $pageId = getId('page');
    $graphId = $INPUT->str('graph');
    $rangeNr = $INPUT->int('range', 0, true);
    $mode = $INPUT->str('mode', 'e');
    
    //-- ACL-Check
    if (auth_quickaclcheck($pageId) < AUTH_READ) throw new Exception("Access denied by ACL.");
    
    //-- Currently only fs and e are supported modes.
    if ($mode != 'fs') $mode = 'e';
    
    //-- Load the rrdgraph helper. This helper contains the cache manager and other stuff used here.
    $rrdGraphHelper = &plugin_load('helper', 'rrdgraph');
    if ($rrdGraphHelper === null) throw new Exception("rrdgraph helper not found.");
    
    //-- Check if the cached image is still valid. If this is not the case, recreate it.
    $cacheInfo = $rrdGraphHelper->getImageCacheInfo($pageId, $graphId, $rangeNr, $mode);
    if (! $cacheInfo->isValid()) {
        
        //-- We found we should update the file. Upgrade our lock to an exclusive one.
        //   This way we OWN the lockfile and nobody else can get confused while we do our thing.
        $cacheInfo->upgradeLock();
        
        $recipe = $rrdGraphHelper->fetchRecipe($pageId, $graphId);
        if ($recipe === null) throw new Exception("The graph " . $graphId . " is not defined on page " . $pageId);
        
        $recipe = $rrdGraphHelper->inflateRecipe($recipe);
        if ($recipe === null) throw new Exception("Inflating the graph " . $graphId . " on page " . $pageId . " failed.");
        
        //-- Initialize the RPN-Computer for conditions
        $rpncomp = new RPNComputer();
        $rpncomp->addConst("true", true);
        $rpncomp->addConst("false", false);
        $rpncomp->addConst("fullscreen", $mode == 'fs');
        $rpncomp->addConst("range", $rangeNr);
        $rpncomp->addConst("page", $pageId);
        
        $options = array ();
        $graphCommands = array ();
        $ranges = array ();
        foreach ($recipe as $element) {
            
            //-- If a condition was supplied, check it.
            if ((! empty($element[0])) && (! ($rpncomp->compute($element[0])))) {
                continue;
            }
            
            //-- Process the special options and pass the rest on to rrdtool.
            switch (strtoupper($element[1])) {
            //-- RANGE:[Range Name]:[Start time]:[End time]
            case 'RANGE' :
                $parts = explode(':', $element[2], 3);
                if (count($parts) == 3) $ranges[] = $parts;
                break;
            
            //-- OPT:[Option]=[Optinal Value]
            case 'OPT' :
                $parts = explode('=', $element[2], 2);
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                
                if (strlen($value) == 0)
                    $options[$key] = null;
                else
                    $options[$key] = $value;
                
                break;
            //-- INCLUDE:[Wiki Page]>[Template]
            case 'INCLUDE' :
                throw new Exception("Recursive inclusion detected. Only graphs can contain inclustion.");
                break;
            
            default :
                $graphCommands[] = $element[1] . ":" . $element[2];
                break;
            }
        }
        
        //-- Bounds-Check for Ranges
        if (count($ranges) == 0) throw new Exception("No time ranges defined for this graph.");
        if (($rangeNr < 0) || ($rangeNr >= count($ranges))) $rangeNr = 0;
        
        //-- The following options are not allowed because they disturbe the function of the plugin.
        //   They are filtered.
        $badOptions = array (
                'a',
                'imgformat',
                'lazy',
                'z' 
        );
        
        $options = array_diff_key($options, array_flip($badOptions));
        
        //-- Set/overwrite some of the options
        $options['imgformat'] = 'PNG';
        $options['start'] = $ranges[$rangeNr][1];
        $options['end'] = $ranges[$rangeNr][2];
        $options['999color'] = "SHADEA#C0C0C0";
        $options['998color'] = "SHADEB#C0C0C0";
        $options['border'] = 1;
        
        //-- Encode the options
        $commandLine = array ();
        foreach ($options as $option => $value) {
            $option = ltrim($option, "0123456789");
            if (strlen($option) == 1)
                $dashes = '-';
            else
                $dashes = '--';
            
            $commandLine[] = $dashes . $option;
            
            if ($value != null) {
                $value = trim($value, " \"\t\r\n");
                $commandLine[] .= $value;
            }
        }
        
        //-- Correct the filename of the graph in case the rangeNr was modified by the range check.
        unset($cacheInfo);
        $cacheInfo = $rrdGraphHelper->getImageCacheInfo($pageId, $graphId, $rangeNr, $mode);
        
        //-- We've to reupgrade the lock, because we got a new cacheInfo instance.
        $cacheInfo->UpgradeLock();
        
        //-- Render the RRD-Graph
        if (rrd_graph($cacheInfo->getFilename(), array_merge($commandLine, $graphCommands)) === false) throw new Exception(rrd_error());
        
        //-- Get the new cache info of the image to send the correct headers.
        unset($cacheInfo);
        $cacheInfo = $rrdGraphHelper->getImageCacheInfo($pageId, $graphId, $rangeNr, $mode);
    }
    
    if (is_file($cacheInfo->getFilename())) {
        // -- Output the image. The content length is determined via the output buffering because
        // on newly generated images (and with the cache on some non standard filesystem) the
        // size given by filesize is incorrect.
        header("Content-Type: image/png");
        header('Expires: ' . gmdate('D, d M Y H:i:s', $cacheInfo->getValidUntil()) . " GMT");
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $cacheInfo->getLastModified()) . " GMT");
        
        ob_start();
        readfile($cacheInfo->getFilename());
        header("Content-Length: " . ob_get_length());
        ob_end_flush();
    } else {
        ErrorImage::outputErrorImage("File not found", $cacheInfo->getFilename());
    }
}
catch (Exception $ex) {
    ErrorImage::outputErrorImage("Graph generation failed", $ex->getMessage());
}

if (isset($cacheInfo)) unset($cacheInfo);