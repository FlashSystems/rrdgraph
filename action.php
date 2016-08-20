<?php
/**
 * RRDGraph Plugin: Action Plugin
 *
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

if (! defined ( 'DOKU_INC' )) die ();

/**
 * Structure for storing the media information passed to the media manager.
 * This contains the path within the rrdrender virtual namespace. 
 *
 */
class rrdMediaInfo
{
    public $pageId;
    public $imageId;
}

/**
 * Action Plugin Class for RRDGraph 
 *
 */
class action_plugin_rrdgraph extends DokuWiki_Action_Plugin {
    
    private function &parseMediaPath($media)
    {
        $parts = split(":", $media);
        if (count($parts) < 3) return false;

        $result = new rrdMediaInfo();

        //-- Check if we are within the rrdgraph virtual namespace.
        if (strcmp(array_shift($parts), "rrdrender") != 0) return false;

        $result->imageId = array_pop($parts);
        $result->pageId = implode(":", $parts);
        
        //-- Verify the imageId
        if (strspn($result->imageId, "0123456789abcdef") != strlen($result->imageId)) return null;
        
        return $result;
    }
    
	/**
	 * Register the necessary events.
	 * @param Doku_Event_Handler $controller Event-Handler for registering the necessary events.
	 */
	public function register(Doku_Event_Handler $controller) {
		$controller->register_hook ( 'PARSER_CACHE_USE', 'BEFORE', $this, '_handle_cache_use' );
		$controller->register_hook ( 'MEDIA_SENDFILE', 'BEFORE', $this, '_handle_media_sendfile' );
		$controller->register_hook ( 'FETCH_MEDIA_STATUS', 'BEFORE', $this, '_handle_fetch_media_status' );
	}
	
	/**
	 * Event-Handler for PARSER_CACHE_USE.
	 * This handler is called BEFORE the cache is used and determins the dependencies of the page. It checks the metadata
	 * if an RRD graph is present and retrieves the dependencies of the graphs on this page from the metadata. 
	 * 
	 * @param Doku_Event $event The Doku_Event object
	 * @param mixed      $param Value provided as fifth argument to register_hook()
	 */
	public function _handle_cache_use(&$event, $param) {
		$cache = &$event->data;
		
		if (! (isset ( $cache->page ) && isset ( $cache->mode )))
			return;
		
		$dependencies = p_get_metadata ( $cache->page, 'plugin_' . $this->getPluginName () . ' dependencies' );
		
		if (! empty ( $dependencies )) {
			foreach ( $dependencies as $dependency ) {
				$cache->depends ['files'] [] = wikiFN ( $dependency );
			}
		}
	}
	
	/**
	 * Event-Handler for MEDIA_SENDFILE.
	 * This handler ist called BEFORE media files are sent to the user. If the virtual rrdrender media namespace
	 * is detected. The graph renderer is called and a virtual rrdgraph file ist sent.
	 * @param Doku_Event $event The Doku_Event object
	 * @param mixed      $param Value provided as fifth argument to register_hook()
	 * @throws Exception
	 */
	public function _handle_media_sendfile(&$event, $param) {
	    global $INPUT;
	    
	    $data = &$event->data;
	    $mediaPath = $this->parseMediaPath($data['media']);
	    
	    if ($mediaPath !== false) {
	     
    	    //-- Load the rrdgraph helper. This helper contains the cache manager and other stuff used here.
    	    $rrdGraphHelper = &plugin_load('helper', 'rrdgraph');
    	    if ($rrdGraphHelper === null) throw new Exception("rrdgraph helper not found.");
    	    
    	    //-- Read some more parameters
    	    $rangeNr = $INPUT->int('range', 0, true);
    	    $mode = $INPUT->str('mode', MODE_GRAPH_EMBEDDED, true);
    	    $bindingSource = $INPUT->str('bind');
    
    	    //-- Call the helper function to render and send the graph.
    	    $rrdGraphHelper->sendRrdImage($mediaPath->pageId, $mediaPath->imageId, $rangeNr, $mode, $bindingSource);
    	    
            //-- The graph was successfully send. Suppress any more processing
            $event->preventDefault();	    
	    }
	}

	/**
	 * Event-Handler for FETCH_MEDIA_STATUS.
	 * This handler ist called BEFORE the status of the media file is retrieved. If the virtual rrdrender meida
	 * namespace is detected the method checks if the parameters look valid. If that's the case, the status code
	 * is set to 200 and DokuWiki calls the MEDIA_SENDFILE event hook. if the virtual namespace is not detected
	 * the method falls through and the normal media processing takes place.
	 * @param Doku_Event $event The Doku_Event object
	 * @param mixed      $param Value provided as fifth argument to register_hook()
	 * @throws Exception
	 */
	public function _handle_fetch_media_status(&$event, $param) {
	    $data = &$event->data;	    
	    $mediaPath = $this->parseMediaPath($data['media']);
	    
	    if ($mediaPath !== false)
	    {
    	    if ($mediaPath === null) {
    	        $data['status'] = 404;
    	        $data['statusmessage'] = "Invalid graph request.";
    	    } else {
                $data['status'] = 200;
                $data['statusmessage'] = "";
    	    }
	    }
	}
}

