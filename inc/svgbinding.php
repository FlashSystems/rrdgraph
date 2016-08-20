<?php
/**
 * RRDGraph Plugin: SVG binding with RRD tool variables
 * 
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

if (! defined('DOKU_INC')) die();

/**
 * Represents a currently active attribute binding within the attribute binding stack.
 * @author dgoss
 *
 */
class AttrBinding
{
    /**
     * The value of this attribute will be replaced by the value stored within the $value variable.
     * @var String
     */
    public $attribute;
    
    /**
     * Value to set for the attribute defined by $attribute.
     * @var String
     */
    public $value;
    
    /**
     * C'tor
     * @param String $attribute Name of the attribute to bind to the given value.
     * @param String $value Value to set for the given attribute.
     */
    public function __construct($attribute, $value) {
        $this->attribute = $attribute;
        $this->value = $value;
    }
}

/**
 * Implements the SAX XML handlers used by the SvgBinding class for parsing SVG files and applying
 * the requested bindings.
 * @author dgoss
 *
 */
class XmlHandler {
    /**
     * File to write the output to.
     * @var Ressource
     */
    private $output;
    
    /**
     * Associative array containing all binding names and the associated, values.
     * @var Array
     */
    private $bindingValues;
    
    /**
     * This stack contains all binding currently active.
     * The entries can be an instance of AttrBinding or NULL if the current binding is not bound to an attribute but
     * replaces the bind-tag directly by the value.
     * @var Array
     */
    private $attrBindingStack = array();    
    
    /*
     * @see http://php.net/manual/en/function.xml-set-default-handler.php
     * This handler is used to pass everything encountered within the XML-file directly into the output file.
     */    
    public function xmlPassthroughHandler($parser, $data) {
        fwrite($this->output, $data);
    }

    /*
     * @see http://php.net/manual/en/function.xml-set-element-handler.php
     */
    public function xmlStartElementHandler($parser, $name, $attributes) {        
        if (strtolower($name) == "bind") {
            if (!array_key_exists("var", $attributes)) throw new Exception("bind-tag is missing the var attribute.");
            if (!array_key_exists("format", $attributes)) throw new Exception("bind-tag is missing the format attribute.");
            
            $value = sprintf($attributes["format"], $this->bindingValues[$attributes["var"]]);

            //-- If no attribute is set this is a direcout output binding.
            if (array_key_exists("attr", $attributes)) {                
                array_push($this->attrBindingStack, new AttrBinding($attributes["attr"], $value));
            } else {
                //-- Direct output bindings are pushed as a null value to allow poping them in the EndElementHandler.
                array_push($this->attrBindingStack, null);
                fwrite($this->output, $value);
            }
        } else {
            //-- Add all bindings that are currently on the stack to the list of attributes.
            foreach ($this->attrBindingStack as $binding) {
                if ($binding != null) $attributes[$binding->attribute] = $binding->value;
            }
            
            fwrite($this->output, '<' . $name);
            foreach ($attributes as $attr => $value) {
                fwrite($this->output, ' ' . $attr . '="' . $value. '"');
            }
            fwrite($this->output, '>');
        }
    }
    
    /*
     * @see http://php.net/manual/en/function.xml-set-element-handler.php
     */
    public function xmlEndElementHandler($parser, $name) {        
        if (strtolower($name) == "bind") {
            if (count($this->attrBindingStack) == 0) {
                throw new Exception("Closing bind tag without opening tag");
            }
            array_pop($this->attrBindingStack);
        }
        else 
        {
            fwrite($this->output, '</' . $name . '>');
        }
    }        
    
    /**
     * C'tor
     * @param Resource $xmlParser The XML parser used for parsing this file. The constructor automatically adds its methods to the given parser.
     * @param String $outputFileName Name of the file to write the generated XML file to.
     * @param Array $bindingValues Associative array containing the binding names as keys and the values as values.
     * @throws Exception
     */
    public function __construct(&$xmlParser, $outputFileName, &$bindingValues) {
        xml_set_object($xmlParser, $this);
        xml_set_default_handler($xmlParser, 'xmlPassthroughHandler');
        xml_set_character_data_handler($xmlParser, 'xmlPassthroughHandler');
        xml_set_element_handler($xmlParser, 'xmlStartElementHandler', 'xmlEndElementHandler');

        xml_parser_set_option($xmlParser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($xmlParser, XML_OPTION_SKIP_WHITE, 1);
        
        $this->bindingValues = &$bindingValues;
        
        $this->output = fopen($outputFileName, 'w');
        if ($this->output === false) throw new Exception('Could not open file "' . $outputFileName . '" for writing.');
    }
    
    /**
     * D'tor
     */
    public function __destruct() {
        fclose($this->output);
    }
}

/**
 * Class to create an image containing an error message.
 */
class SvgBinding {
    /**
     * Associative array containing the binding names as keys and the name of the aggregate function
     * used to create the binding value as a string value.
     * @var Array
     */
    private $aggregates = array();
    
    /**
     * Adds a new binding to the list of bindings. The given aggregate function will be applied to
     * all values and the result will be saved as a binding with the given name.
     * @param String $bindingName Name of the binding to store the result value in. 
     * @param String $aggregateFunction Name of the aggregate function to use for creating the value.
     */
    public function setAggregate($bindingName, $aggregateFunction) {
        $this->aggregates[$bindingName] = $aggregateFunction;
    }
    
    /**
     * Uses the given SVG file and processes all bindings within it. For creating values for the bindings
     * rrd_xport is used with the options given within $rrdOptions.
     * @param String $outputFile Name of the file to write the created SVG to.
     * @param String $rrdOptions String containing the options to pass to rrd_xport.
     * @param String $inputFile Name of the file to read the SVG structure from for processing.
     * @throws Exception
     */
    public function createSVG($outputFile, $rrdOptions, $inputFile) {        
        //-- Export the RRD data for the given options into memory.
        //   RRDExport is writing to the output stream if an error occures. This seems to be a bug,
        //   and currupts our error image. Output buffering to the rescue.
        ob_start();
        $rrdData = @rrd_xport($rrdOptions);
        ob_end_clean();
        if ($rrdData === false) throw new Exception(rrd_error());
        
        //-- Construct the binding values by applying the aggregate function.
        $bindingValues = array();
        foreach ($rrdData["data"] as $data)
        {
            //-- Only process data if we know the aggregate function
            $bindingName = $data[legend];
            
            $data["data"] = array_filter($data["data"], function ($value) { return !is_nan($value); });
            
            if (array_key_exists($bindingName, $this->aggregates))
            {
                switch (strtoupper($this->aggregates[$bindingName])) {
                //-- Takes the minimum value of all of the values within this RRD dataset.
                case "MINIMUM":
                case "MIN":
                    $bindingValues[$bindingName] = min($data["data"]);
                    break;
                    
                //-- Takes the maximum value of all of the values within this RRD dataset.
                case "MAXIMUM":
                case "MAX":
                    $bindingValues[$bindingName] = max($data["data"]);
                    break;
                    
                //-- Takes the average of all of the values within this RRD dataset.
                case "AVERAGE":
                case "AVG":
                    $bindingValues[$bindingName] = array_sum($data["data"])/count($data["data"]);
                    break;
                    
                //-- Just sums the values from the RRD. This may not be what you expect. See
                //   TOTAL.
                case "SUM":
                    $bindingValues[$bindingName] = array_sum($data["data"]);
                    break;
                    
                //-- Total converts the realtive values stored within the RRD back to absolute values
                //   and sums them up. For each value within the RRD total += delta_t * value is
                //   calculated.
                case "TOTAL":
                    $lastTs = NULL;
                    $total = 0;
                    foreach ($data["data"] as $ts => $value) {                        
                        if ($lastTs != NULL) {
                          if (!is_nan($value)) {
                            $total += ($ts - $lastTs) * $value;
                          }
                        }
                        
                        $lastTs = $ts;
                    }
                    $bindingValues[$bindingName] = $total;
                    break;
                    
                //-- Takes the first (lowest timestamp) non NAN value of all of the values within this RRD dataset.
                case "FIRST":
                    $bindingValues[$bindingName] = reset($data["data"]);
                    break;
                    
                //-- Takes the last (highest timestamp) non NAN value of all of the values within this RRD dataset.
                case "LAST":
                    $bindingValues[$bindingName] = end($data["data"]);
                    break;
                    
                default:
                    throw new Exception('Unknown aggregation function ' . $this->aggregates[$bindingName]);
                } 
            }
        }
        
        //-- Initialize the SAX parser
        $xmlParser = xml_parser_create();
        $handler = new XmlHandler($xmlParser, $outputFile, $bindingValues);

        //-- Now parse the SVG file.
        $data = file_get_contents($inputFile);
        if ($data === false) throw new Exception('Could not load file "' . $inputFile. '".');
        if (xml_parse($xmlParser, $data, true) == 0) throw new Exception('Parsing SVG failed: ' . xml_error_string(xml_get_error_code($xmlParser)));
        
        unset($xmlParser);
    }
}
