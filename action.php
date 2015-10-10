<?php
/**
 * RRDGraph Plugin: Action Plugin
 *
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

if (! defined ( 'DOKU_INC' )) die ();

/**
 * Action Plugin Class for RRDGraph 
 *
 */
class action_plugin_rrdgraph extends DokuWiki_Action_Plugin {
	/**
	 * Register the necessary events.
	 * @param Doku_Event_Handler $controller Event-Handler for registering the necessary events.
	 */
	public function register(Doku_Event_Handler $controller) {
		$controller->register_hook ( 'PARSER_CACHE_USE', 'BEFORE', $this, '_handle_cache_use' );
	}
	
	/**
	 * Event-Handler for PARSER_CACHE_USE.
	 * This Handler is called BEFORE the case is used and determins the dependencies of the page. It checks the metadata
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
}

