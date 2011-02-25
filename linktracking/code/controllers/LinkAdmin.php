<?php
/**
 * Provides a basic interface for creating tracked links and viewing hits based on
 * @see ModelAdmin
 *
 * @package linktracking
 * @author Al Twohill <firstname@hol.net.nz>
 **/
class LinkAdmin extends ModelAdmin {
	static $managed_models = array(
		'TrackedLink',
	);
	
	static $url_segment = 'links'; //will be linked as /admin/links
	static $menu_title = 'Link Tracking';
	
	static $page_length = 10;
}