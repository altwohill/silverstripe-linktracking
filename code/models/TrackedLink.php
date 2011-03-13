<?php
/**
 * Stores the link to be tracked in the database, as well as the source and observer.
 * 
 * The source is where the hit is coming from, either a text string such as 'web'
 * or 'twitter', or a DataObject in the database such as a Member (useful if you
 * send an email to a member, you can see if they have viewed it).
 * 
 * The observer is the object which observes hits across multiple sources. This could
 * be a page, or an email campaign where you have generated individual links to be
 * sent out via different sources.
 *
 * @package linktracking
 * @author Al Twohill <firstname@hol.net.nz>
 **/
class TrackedLink extends DataObject {
	/**#@+
	 * Silverstripe data structure
	 */
	/**
	 * Database fields. Note that SourceType/ID and ObserverType/ID is really a has_one,
  	 * but it could be any data-type, and the SourceType could also be a string
	 */
	public static $db = array(
		'Slug' => 'Varchar',
		'LinkType' => 'Enum("Redirect,DirectDownload")',
		'Destination' => 'Varchar(255)',
		'SourceType' => 'Varchar(255)',
		'SourceID' => 'Int',
		'ObserverType' => 'Varchar(255)',
		'ObserverID' => 'Int',
	);
	/**
	 * Relationships
	 */
	public static $has_many = array (
		'Hits' => 'LinkHit'
	);
	/**
	 * Fields to display in @see LinkAdmin
	 */
	public static $summary_fields = array(
		'Slug',
		'Destination',
		'LinkType',
		'SourceType'
	);
	
	/**
	 * Constructs the TrackedLink and generates the Slug (if a destination is set)
	 * 
	 * If $source passed a DataObject it will be linked to, Otherwise a string is assumed
	 * $linkType 'Redirect' is default, but if tracking an image that you want to display, use 'DirectDownload'
	 * For $observer to be useful, it should be decorated by Observer
	 * 
	 * @param string $destination The URL to go to
	 * @param string|DataObject $source The source of the link. 
	 * @param string $linkType The type of link. '
	 * @param DataObject $observer The object watching the link for hits
	 * 
	 * @return TrackedLink
	 */ 
	public static function create($destination, $source , $linkType = 'Redirect', $observer = null) {
		$link = new TrackedLink();
		$link->Destination = $destination;	
		$link->setSource($source);
		$link->LinkType = $linkType;
		$link->setObserver($observer);
		$link->generateSlug();
		
		return $link;
	}
	
	/**
	 * Parses the given content looking for links and images
	 * and creates tracked links from them.
	 * It then replaces the links in the content with tracked links
	 * 
	 * If $source passed a DataObject it will be linked to, Otherwise a string is assumed
	 * For $observer to be useful, it should be decorated by Observer
	 * 
	 * @param string $content  The content to parse
	 * @param string|DataObject $source The source of the link. 
	 * @param string $fullUrl The part of the url to prepend to the slug in the new content
	 * @param DataObject $observer The object that wants to know about the link hits. 
	 * 
	 * @return string The modified content
	 */ 
	public static function create_from_content($content, $source, $fullUrl, $observer = null) {
		$dom = new DOMDocument();
		@$dom->loadHTML($content); //Don't complain about html errors
		
		foreach ($dom->getElementsByTagName('a') as $a) {
		    $href = $a->getAttributeNode('href');
		    if (strpos($href->value, 'mailto') === false) {
				$link = self::create($href->value, $source, 'Redirect', $observer);
				$link->write();
				$href->value = $fullUrl . $link->Slug;
			}
		}
		
		foreach ($dom->getElementsByTagName('img') as $img) {
			$src = $img->getAttributeNode('src');
		   
			$link = self::create($src->value, $source, 'DirectDownload', $observer);
			$link->write();
			$src->value = $fullUrl . $link->Slug;
		}
		
		return $dom->saveXML();
	}
	
	/**
	 * Gets the source of the link. Either a dataobject, in which case the SourceType
	 * will be the object type, and SourceID will be the ID
	 *  OR
	 * SourceType is something outside of the site, in which case it returns an @see
  	 * ArrayData describing it. eg "Twitter"
	 * 
	 * This is used for tracking where a link was accessed from.
	 * 
	 * @return DataObject The source of the link, eg "Twitter" or "Member A"
	 */ 
	public function getSource() {
		if ($this->SourceType) {
			if ($this->SourceID) {
				$sql_type = Convert::raw2sql($this->SourceType);
				$item = DataObject::get_by_id($sql_type, $this->SourceID);
			} else {
				$item = new ArrayData(array('Name' => $this->SourceType));
			}
			return $item;
		} else {
			return null;
		}
	}
	
	/**
	 * Sets the source of the link we're tracking
	 * 
	 * If passed a DataObject it will be linked to. Otherwise a string is assumed.
	 * 
	 * @param string|DataObject $source The source of the link. 
	 */ 
	public function setSource($source) {
		if ($source && $source instanceof DataObject) {
			$this->SourceType = $source->ClassName;
			$this->SourceID = $source->ID;
		} else {
			$this->SourceType = $source;
		}
	}
	
	/**
	 * Get the object that is watching the link.
	 * To be able to do reporting, the object should be decorated by Observer
	 * 
	 * @return DataObject
	 */
	public function getObserver() {
		if ($this->ObserverType && $this->ObserverID) {
			$sql_type = Convert::raw2sql($this->ObserverType);
			$item = DataObject::get_by_id($sql_type, $this->Observer);
			return $item;
		}
	}
	
	/**
	 * Sets the observer of the link
	 * To be able to do reporting, the object should be decorated by Observer
	 * 
	 * @param DataObject $observer
	 */
	public function setObserver($observer) {
		if ($observer && $observer instanceof DataObject) {
			$this->ObserverType = $observer->ClassName;
			$this->ObserverID = $observer->ID;
		}
	}
	
	/**
	 * If a Slug isn't generated, generate one now
	 */
	public function onBeforeWrite() {
		if (!$this->Slug) {
			$this->generateSlug();
		}
		parent::onBeforeWrite();
	}
	
	/**
	 * Generates a unique random 'slug' (url part), using md5.
	 * Gives up after 100 tries if it still hasn't found a unique one
	 */ 
	protected function generateSlug() {
		$count = 0;
		do {
			//Make sure we don't keep trying for too long
			$count++;
			if ($count > 100) {
				user_error('Tried 100 times to generate a unique slug', E_USER_ERROR);
				break;
			}
		
			//Generate something random
			$random = rand();
		    $string = md5($random);
			$this->Slug = substr($string, 0, 6);
			//Make sure we don't have a duplicate
		} while (DataObject::get_one('TrackedLink', "Slug = '$this->Slug' AND ID != '{$this->ID}'"));
	}
}