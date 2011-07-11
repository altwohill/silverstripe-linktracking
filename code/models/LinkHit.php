<?php
/**
 * Stores a link hit in the database to allow reporting
 *
 * @package linktracking
 * @author Al Twohill <firstname@hol.net.nz>
 **/
class LinkHit extends DataObject {
	
	public static $db = array(
		'IP' => 'Varchar(39)' //Sized to accept IPv6
	);
	
	public static $has_one = array(
		'Link' => 'TrackedLink'
	);
	
	public static $summary_fields = array(
		'Created', //This lets us know when the link was hit
		'IP'
	);
}