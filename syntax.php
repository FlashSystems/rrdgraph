<?php
/**
 * RRDGraph Plugin: Helper classes
 * 
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

if (!defined('DOKU_INC')) die();

/**
 * Implements rrd class plugin's syntax plugin.
 *
 */
class syntax_plugin_rrdgraph extends DokuWiki_Syntax_Plugin {
    /** Constant that indicates that a recipe is used for cerating graphs. */
    const RT_GRAPH = 0;
    /** Constant that indicates that a recipe is used for inclusion in other recipes. */
    const RT_TEMPLATE = 1;
    /** Constant that indicates that a recipe is used for bound svg graphics. */
    const RT_BOUNDSVG = 2;

    /** Array index of the graph type within the parsed recipe. */ 
    const R_TYPE = 'type';
    /** Array index of the graph name within the parsed recipe. */
    const R_NAME = 'name';
    /** Array index of a flag that indicates if the results of this recipe should be included within the generated xhtml output. */
    const R_SHOW = 'show';
    /** Array index of the recipe data within the parsed recipe. */
    const R_DATA = 'data';
    /** Array index of the ganged flag within the parsed recipe. */
    const R_GANGED = 'ganged';
    /** Array index of the name of the bound svg file if the parsed recipe is of type RT_BOUNDSVG. */
    const R_BSOURCE = 'bsource';

    /**
     * Stores the rrd recipe while it's parsed. This variable is reset every time a new recipe starts.
     * @var Array
     */
    private $rrdRecipe;

    /**
     * Returns the syntax mode of this plugin.
     * @return String Syntax mode type
     */
    public function getType() {
        return 'substition';
    }

    /**
     * Returns the paragraph type of this plugin.
     * @return String Paragraph type
     */
    public function getPType() {
        return array ();
    }

    /**
     * Returns the sort order for this plugin.
     * @return Integer Sort order - Low numbers go before high numbers
     */
    public function getSort() {
        return 320;
    }

    /**
     * Connect lookup pattern to lexer.
     * 
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {
        $this->Lexer->addEntryPattern('<rrd.*?>(?=.*?</rrd>)', $mode, 'plugin_rrdgraph');
    }

    /**
     * Adds some patterns after the start pattern was found.
     */
    public function postConnect() {
        $this->Lexer->addPattern('\n[ \t]*(?:[a-z0-9,<>=&|]+\?)?[A-Z0-9]+:[^\n]+', 'plugin_rrdgraph'); //TODO: Parser Regex mit der weiter untern verschmelzen und in eine Konstante packen!
        $this->Lexer->addExitPattern('</rrd>', 'plugin_rrdgraph');
    }

    /**
     * Parses the given string as a boolean value. 
     * @param String $value String to be parsed.
     * @return boolean If the string is "yes", "on" or "true" true is returned. If the stirng is anything else, false is returned.
     */
    private function parseBoolean($value) {
        $value = strtolower(trim($value));
        if (is_numeric($value)) return (intval($value) > 0);
        
        switch ($value) {
        case 'yes' :
        case 'on' :
        case 'true' :
            return true;
        default :
            return false;
        }
    }

    /**
     * Extracts the range tags from a given recipe.
     * @param Array $recipe The rrd recipe that should be parsed. 
     * @return Array An array of arrays is returned. For each RANGE tag an array is created containing three values: (0) The range name, (1) the start time, (2) the end time.
     */
    private function getRanges($recipe) {
        $ranges = array ();
        foreach ($recipe as $option) {
            list ($condition, $key, $value) = $option;
            switch ($key) {
            case "RANGE" :
                $range = explode(":", $value, 3);
                if (count($range) == 3) $ranges[] = $range;
                break;
            }
        }
        
        return $ranges;
    }

    /**
     * Generates the XHTML markup for the tabs based on a range definition generated by getRanges(). 
     * @param Array $ranges The range definition generated by getRanges().
     * @param Integer $selectedTab The number of the selected tab. (zero based).
     * @param String $graphId The id-value (hex-hash) of the graph this tab markup is generated for.
     * @param Boolean $initiallyGanged If the "ganged" checkbox shlould be initially ticked.
     * @return String Returns the XHTML markup that should be inserted into the page..
     */
    private function generateTabs($ranges, $selectedTab, $graphId, $initiallyGanged) {
        //-- Define the tabs for bigger streen resolutions...
        $xhtml = '<ul class="rrdTabBar" id="' . "__T$graphId" . '">';
        $tabCounter = 0;
        foreach ($ranges as $number => $range) {
            $rangeName = $range[0];
            
            $xhtml .= '<li id="';
            $xhtml .= '__TI' . $graphId . 'X' . $number;
            $xhtml .= '"';
            if ($tabCounter ++ == $selectedTab) $xhtml .= ' class="rrdActiveTab"';
            $xhtml .= '><a href="javascript:rrdSwitchRange(';
            $xhtml .= "'$graphId', $number";
            $xhtml .= ')">';
            $xhtml .= htmlentities($rangeName);
            $xhtml .= '</a></li>';
        }
        
        $xhtml .= '</ul>';
        
        //-- ...and a drop down list for small resultions and mobile devices. Theo two are switched by CSS.
        $xhtml .= '<select id="' . "__T$graphId" . '" OnChange="rrdDropDownSelected(' . "'$graphId'" . ', this)">';
        
        $tabCounter = 0;
        foreach ($ranges as $number => $range) {
            $rangeName = $range[0];
            
            $xhtml .= '<option id="';
            $xhtml .= '__TI' . $graphId . 'X' . $number;
            $xhtml .= '" value=' . $number;
            if ($tabCounter ++ == $selectedTab) $xhtml .= ' selected="true"';
            $xhtml .= '>';
            $xhtml .= htmlentities($rangeName);
            $xhtml .= '</option>';
        }
        $xhtml .= '</select>';
        
        $xhtml .= '<div class="rrdGangCheckbox"><input type="checkbox" value="' . $graphId . '" name="rrdgraph_gang"' . ($initiallyGanged?'checked="checked"':'') . '/></div>';
        $xhtml .= '<div class="rrdClearFloat"></div>';
        
        return $xhtml;
    }

    /**
     * Parses the given tag and extracts the attributes.
     * @param String $tag A tag <xxx> given within the DokuWiki page.
     * @param Array $defaults An array containing the default values for non existing attributes. The attribute name is used as the array key. If the attribute is not explicitly supplied whtin $tag the value from this array is returned.
     * @return Array Returns an array that contains the tags as key, value pairs. The key is used as the arrays key value.
     */
    private function parseAttributes($tag, $defaults) {
        if (preg_match('/<[[:^space:]]+(.*?)>/', $tag, $matches) != 1) return false;
        
        $attributes = array ();
        
        if (($numMatches = preg_match_all('/([[:alpha:]]+)[[:space:]]*=[[:space:]]*[\'"]?([[:alnum:]:.-_]+)[\'"]?/', $matches[1], $parts, PREG_SET_ORDER)) > 0) {
            foreach ($parts as $part) {
                $key = strtolower(trim($part[1]));
                $value = trim($part[2]);
                if (! empty($value)) $attributes[$key] = $value;
            }
        }
        
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $attributes)) $attributes[$key] = $value;
        }
        
        return $attributes;
    }

    /**
     * Recreates the line of a rrd recipe from the parsed recipe data.
     * This is used to recreate the recipe for showing template code.
     * This method is called by array_reduce so the parameters are documented on the php website.
     * @param String $carry The output of the last runs.
     * @param Array $item The element of the rrd recipe.
     * @return String The stringified version of the passed array.
     */
    private function reduceRecipeLine($carry, $item) {
        if (empty($item[0]))
            return $carry . "\n" . $item[1] . ':' . $item[2];
        else
            return $carry . "\n" . $item[0] . '?' . $item[1] . ':' . $item[2];
    }

    /**
     * Handle matches of the rrdgraph syntax
     * 
     * @param String $match The match of the syntax
     * @param Integer $state The state of the handler
     * @param Integer $pos The position in the document
     * @param Doku_Handler $handler The handler
     * @return Array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        //-- Do not handle comments!
        if (isset($_REQUEST['comment'])) return false;
        
        switch ($state) {
        	
        case DOKU_LEXER_ENTER :
            //-- Clear the last recipe.
            $this->rrdRecipe = array ();
            
            $attributes = $this->parseAttributes($match, array("show" => true, "ganged" => false));
            
            if (array_key_exists("template", $attributes)) {
                $this->rrdRecipe[self::R_TYPE] = self::RT_TEMPLATE;
                $this->rrdRecipe[self::R_NAME] = $attributes['template'];
                $this->rrdRecipe[self::R_SHOW] = $this->parseBoolean($attributes['show']);
                $this->rrdRecipe[self::R_GANGED] = false;
            } else if (array_key_exists("bind", $attributes)) {
                $this->rrdRecipe[self::R_TYPE] = self::RT_BOUNDSVG;
                $this->rrdRecipe[self::R_SHOW] = true;  // Bound SVG images will never be ganged and always visible.
                $this->rrdRecipe[self::R_GANGED] = false;
                $this->rrdRecipe[self::R_BSOURCE] = $attributes['bind'];
            } else {
                $this->rrdRecipe[self::R_TYPE] = self::RT_GRAPH;
                // The name if left empty. In this case it will be set by DOKU_LEXER_EXIT. 
                $this->rrdRecipe[self::R_SHOW] = true;
                $this->rrdRecipe[self::R_GANGED] = $this->parseBoolean($attributes['ganged']);
            }
            
            break;
        
        case DOKU_LEXER_MATCHED :
            if (preg_match('/^(?:([a-z0-9,<>=&|]+)\?)?([A-Z0-9]+):(.*)$/', trim($match, "\r\n \t"), $matches) == 1) {
                list ($line, $condition, $key, $value) = $matches;
                
                //-- A rrd recipe line consists of 3 array elements. The (0) condition (may be empty), (1) the key and (2) the value.
                $this->rrdRecipe[self::R_DATA][] = array (
                        $condition,
                        trim($key),
                        trim($value) 
                );
            }
            break;
        
        case DOKU_LEXER_EXIT :
            
            //-- If no Name is set for this recipe. Create one by hashing its content.
            if (! isset($this->rrdRecipe[self::R_NAME])) $this->rrdRecipe[self::R_NAME] = md5(serialize($this->rrdRecipe[self::R_DATA]));
            
            return $this->rrdRecipe;
        }
        
        return array ();
    }

    /**
     * Render xhtml output or metadata
     * 
     * @param String $mode Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer $renderer The renderer
     * @param Array $data The data from the handler() function
     * @return boolean If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        global $ID;
        
        //-- Don't render empty data.
        if (count($data) == 0) return false;
        
        //-- Initialize the helper plugin. It contains functions that are used by the graph generator and the syntax plugin.
        $rrdGraphHelper = $this->loadHelper('rrdgraph');
        
        if ($mode == 'metadata') {
        	//-- If metadata is rendered get the dependencies of the current recipe and merge them with the dependencies of the previous graphs.
        	if (!is_array($renderer->meta['plugin_' . $this->getPluginName()]['dependencies'])) $renderer->meta['plugin_' . $this->getPluginName()]['dependencies'] = array();
        	
            $renderer->meta['plugin_' . $this->getPluginName()]['dependencies'] = array_unique(array_merge($renderer->meta['plugin_' . $this->getPluginName()]['dependencies'], $rrdGraphHelper->getDependencies($data[self::R_DATA])), SORT_STRING);
        } else if ($mode == 'xhtml') {
        	//-- If xhtml is rendered. Generate the tab bar and the images.
        	//   Every graph gehts an id that is dereived from the md5-checksum of the recipe. This way a graph with a different recipe
        	//   gets a new and different graphId.
            $rrdGraphHelper = $this->loadHelper('rrdgraph');
            $rrdGraphHelper->storeRecipe($ID, $data[self::R_NAME], $data[self::R_DATA]);
            
            $mediaNamespace = $this->getConf('graph_media_namespace');
            
            if ($data[self::R_SHOW]) {
                switch ($data[self::R_TYPE]) {
                //-- Graphs are generated and shown.
                case self::RT_GRAPH :
                    try {
                        $newDoc = "";
                        
                        $graphId = $data[self::R_NAME];
                        $imageURL = DOKU_BASE . '_media/' . $mediaNamespace . ':' . $ID . ':' . $graphId;
                        $inflatedRecipe = $rrdGraphHelper->inflateRecipe($data[self::R_DATA]);
                        $ranges = $this->getRanges($inflatedRecipe);
                        
                        $mainDivAttributes = array (
                                'class' => 'rrdImage',
                                'data-graphid' => $graphId,
                                'data-ranges' => count($ranges) 
                        );
                        $imageAttributes = array (
                                'src' => $imageURL,
                                'id' => '__I' . $graphId 
                        );
                        $linkAttributes = array (
                                'href' => $imageURL . '?mode=fs',
                                'target' => 'rrdimage',
                                'id' => '__L' . $graphId 
                        );
                        
                        $newDoc .= '<div ' . buildAttributes($mainDivAttributes) . '>';
                        
                        $newDoc .= $this->generateTabs($ranges, 0, $graphId, $data[self::R_GANGED]);
                        $newDoc .= '<div class="rrdLoader" id="__LD' . $graphId . '"></div>';
                        $newDoc .= '<a ' . buildAttributes($linkAttributes) . '><img ' . buildAttributes($imageAttributes) . '/></a>';

                        $newDoc .= '</div>';
                        
                        $renderer->doc .= $newDoc;
                        unset($newDoc);
                    }
                    catch (Exception $ex) {
                        $renderer->doc .= '<div class="rrdError">' . htmlentities($ex->getMessage()) . '</div>';
                    }
                    break;
                
                //-- Graph templates are output as text. They may be hidden via the show attribute.
                case self::RT_TEMPLATE :
                    $renderer->doc .= '<h2>RRD Template &quot;' . htmlentities($data[self::R_NAME]) . '&quot;</h2>';
                    $renderer->doc .= '<pre>';
                    $renderer->doc .= array_reduce($data[self::R_DATA], array (
                            $this,
                            "reduceRecipeLine" 
                    ));
                    $renderer->doc .= '</pre>';
                    break;
                    
                //-- This is a bound SVG file. They are processed by the graph.php file and embedded as images.
                case self::RT_BOUNDSVG:
                        $newDoc = "";
                        
                        $graphId = $data[self::R_NAME];
                        $bindingSource = $data[self::R_BSOURCE];
                        $imageURL = DOKU_BASE . '_media/' . $mediaNamespace . ':' . $ID . ':' . $graphId . '?mode=' . helper_plugin_rrdgraph::MODE_BINDSVG . '&bind=' . $bindingSource;
                        
                        $imageAttributes = array (
                                'src' => $imageURL,
                                'id' => '__I' . $graphId 
                        );
                        
                        $newDoc .= '<img ' . buildAttributes($imageAttributes) . '/>';
                        
                        $renderer->doc .= $newDoc;
                        unset($newDoc);
                        break;
                    
                }
            }
        }
        
        return true;
    }
}
