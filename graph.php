<?php
/**
 * RRDGraph Plugin: Graph generator
 * 
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

if (! defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../../../') . '/');
define('DOKU_DISABLE_GZIP_OUTPUT', 1);

//-- Global constants
const MODE_GRAPH_EMBEDDED = 'e';
const MODE_GRAPH_FULLSCREEN = 'fs';
const MODE_BINDSVG = 'b';

//-- Initialize DokuWiki's core
require_once (DOKU_INC . 'inc/init.php');
require_once ("inc/errorimage.php");
require_once ("inc/rpncomputer.php");
require_once ("inc/svgbinding.php");
require_once ("inc/contenttypes.php");

//-- Close the currently open session. We don't need it here.
session_write_close();

//-- User abort must be ignored because we're building new images for the cache. If the
//   user aborts this process, the cache may be currupted. 
@ignore_user_abort(true);

try {
    $pageId = getId('page');
    $graphId = $INPUT->str('graph');
    $rangeNr = $INPUT->int('range', 0, true);
    $mode = $INPUT->str('mode', MODE_GRAPH_EMBEDDED, true);
    $bindingSource = $INPUT->str('bind');
    
    //-- ACL-Check
    if (auth_quickaclcheck($pageId) < AUTH_READ) throw new Exception("Access denied by ACL.");
    
    //-- Currently only fs, b and e are supported modes.
    if (($mode != MODE_GRAPH_FULLSCREEN) && ($mode != MODE_BINDSVG)) $mode = MODE_GRAPH_EMBEDDED;
    
    //-- If the mode is "b" then $bindingSource must be set and accessible
    if ($mode == MODE_BINDSVG) {
        if ($bindingSource == null) throw new Exception("Binding source missing.");
        if (auth_quickaclcheck($bindingSource) < AUTH_READ) throw new Exception("Access denied by ACL.");
    }
    
    //-- Load the rrdgraph helper. This helper contains the cache manager and other stuff used here.
    $rrdGraphHelper = &plugin_load('helper', 'rrdgraph');
    if ($rrdGraphHelper === null) throw new Exception("rrdgraph helper not found.");
    
    //-- Check if the cached image is still valid. If this is not the case, recreate it.
    $cacheInfo = $rrdGraphHelper->getImageCacheInfo($pageId, $graphId, ($mode == MODE_BINDSVG)?"svg":"png", $rangeNr, $mode);
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
        $rpncomp->addConst("fullscreen", $mode == MODE_GRAPH_FULLSCREEN);
        $rpncomp->addConst("range", $rangeNr);
        $rpncomp->addConst("page", $pageId);
        
        $options = array ();
        $graphCommands = array ();
        $ranges = array ();
        if ($mode == MODE_BINDSVG) $svgBinding = new SvgBinding(); 
        foreach ($recipe as $element) {
            
            //-- If a condition was supplied, check it.
            if ((! empty($element[0])) && (! ($rpncomp->compute($element[0])))) {
                continue;
            }
            
            //-- Process the special options and pass the rest on to rrdtool.
            switch (strtoupper($element[1])) {
            //-- RANGE:[Range Name]:[Start time]:[End time]
            case 'RANGE' :
                if (($mode == MODE_BINDSVG) && (count($ranges) == 1)) throw new Exception("For SVG binding only one RANGE can be specified.");
                $parts = explode(':', $element[2], 3);
                if (count($parts) == 3) $ranges[] = $parts;
                break;
            
            //-- OPT:[Option]=[Optinal value]
            case 'OPT' :
                $parts = explode('=', $element[2], 2);
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                
                if (strlen($value) == 0)
                    $options[$key] = null;
                else
                    $options[$key] = $value;
                
                break;
                
            //-- BDEF:[Binding]=[Variable]:[Aggregation function]
            case 'BDEF':
                if ($mode != MODE_BINDSVG) throw new Exception("BDEF only allowed if the recipe is used for binding.");
                $parts = explode('=', $element[2], 2);
                if (count($parts) != 2) throw new Exception("BDEF is missing r-value.");
                $rparts = explode(':', $parts[1], 2);
                if (count($rparts) != 2) throw new Exception("BDEF is missing aggregation function");
                $binding = $parts[0];
                $variable = $rparts[0];
                $aggFkt = $rparts[1];
                
                //-- Put the binding into the list of the SvgBinding class and output an XPORT command
                //   for RRDtool to export the used variable.
                $svgBinding->setAggregate($binding, $aggFkt);
                $graphCommands[] = "XPORT:" . $variable . ':' . $binding;
                
                break;
                
            //-- INCLUDE:[Wiki Page]>[Template]
            case 'INCLUDE' :
                throw new Exception("Recursive inclusion detected. Only graphs can contain inclusions.");
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
        $options['start'] = $ranges[$rangeNr][1];
        $options['end'] = $ranges[$rangeNr][2];
        
        //-- If we're not only doing SVG-Binding some more defaults have to be set.
        if ($mode != MODE_BINDSVG)
        {
            $options['imgformat'] = 'PNG';
            $options['999color'] = "SHADEA#C0C0C0";
            $options['998color'] = "SHADEB#C0C0C0";
            $options['border'] = 1;
        }
        
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
        $cacheInfo = $rrdGraphHelper->getImageCacheInfo($pageId, $graphId, ($mode == MODE_BINDSVG)?"svg":"png", $rangeNr, $mode);
        
        //-- We've to reupgrade the lock, because we got a new cacheInfo instance.
        $cacheInfo->UpgradeLock();
        
        //-- Depending on the current mode create a new PNG or SVG image.
        switch ($mode) {
        case MODE_GRAPH_EMBEDDED:
        case MODE_GRAPH_FULLSCREEN:
            //-- Render the RRD-Graph
            if (rrd_graph($cacheInfo->getFilename(), array_merge($commandLine, $graphCommands)) === false) throw new Exception(rrd_error());
            break;
            
        case MODE_BINDSVG:
            $bindingSourceFile = mediaFN(cleanID($bindingSource));
            $svgBinding->createSVG($cacheInfo->getFileName(), array_merge($commandLine, $graphCommands), $bindingSourceFile);
            break;
        }
        
        //-- Get the new cache info of the image to send the correct headers.
        unset($cacheInfo);
        $cacheInfo = $rrdGraphHelper->getImageCacheInfo($pageId, $graphId, ($mode == MODE_BINDSVG)?"svg":"png", $rangeNr, $mode);
    }
    
    if (is_file($cacheInfo->getFilename())) {
        // -- Output the image. The content length is determined via the output buffering because
        // on newly generated images (and with the cache on some non standard filesystem) the
        // size given by filesize is incorrect
        $contentType = ContentType::get_content_type($cacheInfo->getFilename());
        if ($contentType === null) throw new Exception("Unexpected file extension.");
        header("Content-Type: " . $contentType);
        
        header('Expires: ' . gmdate('D, d M Y H:i:s', $cacheInfo->getValidUntil()) . ' GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $cacheInfo->getLastModified()) . ' GMT');
        
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