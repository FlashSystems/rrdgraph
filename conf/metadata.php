<?php
/**
 * RRDGraph Plugin: Metadata for configuration options of RRDGraph plugin.
 *
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

$meta['cache_timeout'] = array('numeric', '_min' => 0);
$meta['include_acl'] = array('onoff');